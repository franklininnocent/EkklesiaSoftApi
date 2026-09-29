<?php

namespace Modules\Donations\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class OutstandingReportBuilder extends AbstractDonationReportBuilder
{
    public function __construct(
        private readonly AsOfContributionBalanceService $asOfBalance,
    ) {}

    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_OUTSTANDING;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $asOf = (string) ($filter->get('as_of_date') ?: $this->parishToday($tenantId));
        $view = $filter->get('view', 'families');

        if ($view === 'totals') {
            return $this->previewTotals($tenantId, $filter, $asOf);
        }

        $allRows = ReportTableSort::sortArrayRows(
            $this->buildFamilyRows($tenantId, $filter, $asOf),
            $filter,
            ['family_name', 'period_label', 'due_date', 'outstanding', 'schedule_state'],
        );
        $total = count($allRows);
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = array_slice($allRows, ($page - 1) * $perPage, $perPage);

        $aggregates = $this->aggregateBuckets($tenantId, $filter, $asOf);

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'row_count' => $total,
                'outstanding_collectable' => MoneyMath::toApiNumber($aggregates['collectable']),
                'overdue_amount' => MoneyMath::toApiNumber($aggregates['overdue']),
                'as_of' => $asOf,
                'reconstruction_gaps' => $aggregates['reconstruction_gaps'],
            ],
            'footer' => [
                'family_name' => $total.' rows',
                'outstanding' => MoneyMath::toApiNumber($this->sumMoneyColumns($allRows, ['outstanding'])['outstanding']),
            ],
            'meta' => [
                'date_semantic' => 'as_of',
                'amount_basis' => 'balance',
                'as_of_method' => 'allocation_replay_with_reversal_cutoff',
            ],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewTotals(int $tenantId, ReportFilter $filter, string $asOf): array
    {
        $aggregates = $this->aggregateBuckets($tenantId, $filter, $asOf);

        return [
            'columns' => [
                ['key' => 'bucket', 'label' => 'Bucket'],
                ['key' => 'amount', 'label' => 'Amount'],
            ],
            'rows' => [
                ['bucket' => 'Overdue', 'amount' => MoneyMath::toApiNumber($aggregates['overdue'])],
                ['bucket' => 'Remaining collectable', 'amount' => MoneyMath::toApiNumber($aggregates['remaining'])],
                ['bucket' => 'Next 14 days', 'amount' => MoneyMath::toApiNumber($aggregates['next_14'])],
                ['bucket' => 'Total collectable', 'amount' => MoneyMath::toApiNumber($aggregates['collectable'])],
            ],
            'totals' => [
                'as_of' => $asOf,
                'reconstruction_gaps' => $aggregates['reconstruction_gaps'],
                'outstanding_collectable' => MoneyMath::toApiNumber($aggregates['collectable']),
                'overdue_amount' => MoneyMath::toApiNumber($aggregates['overdue']),
            ],
            'footer' => [
                'bucket' => 'As of '.$asOf,
                'amount' => MoneyMath::toApiNumber($aggregates['collectable']),
            ],
            'meta' => ['date_semantic' => 'as_of', 'view' => 'totals'],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 4],
        ];
    }

    public function csvHeaders(): array
    {
        return ['family_name', 'period_label', 'due_date', 'outstanding', 'schedule_state'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $asOf = (string) ($filter->get('as_of_date') ?: $this->parishToday($tenantId));
        $view = $filter->get('view', 'families');
        $count = 0;

        if ($view === 'totals') {
            $preview = $this->previewTotals($tenantId, $filter, $asOf);
            SafeCsvWriter::putRow($handle, ['bucket', 'amount']);
            foreach ($preview['rows'] as $row) {
                SafeCsvWriter::putRow($handle, [$row['bucket'], $row['amount']]);
                $count++;
            }

            $this->writeCsvSummary($handle, $preview);

            return $count;
        }

        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($this->buildFamilyRows($tenantId, $filter, $asOf) as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFamilyRows(int $tenantId, ReportFilter $filter, string $asOf): array
    {
        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive) {
            return $this->buildProjectInstallmentRows($tenantId, $filter, $asOf);
        }

        $rows = [];
        $this->familyDueQuery($tenantId, $filter, $asOf)->chunk(200, function ($dues) use ($tenantId, $asOf, &$rows): void {
            foreach ($dues as $due) {
                $outstanding = $this->asOfBalance->outstandingForDue($tenantId, $due, $asOf);
                if ($outstanding <= 0) {
                    continue;
                }
                $rows[] = [
                    'family_id' => $due->family_id,
                    'family_name' => $due->family?->family_name,
                    'period_label' => $due->period_label,
                    'due_date' => $due->due_date?->format('Y-m-d'),
                    'outstanding' => MoneyMath::toApiNumber($outstanding),
                    'schedule_state' => ContributionBalance::scheduleState($due, $asOf),
                ];
            }
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildProjectInstallmentRows(int $tenantId, ReportFilter $filter, string $asOf): array
    {
        $rows = [];
        $this->installmentDueQuery($tenantId, $filter, $asOf)->chunk(200, function ($dues) use ($tenantId, $asOf, &$rows): void {
            foreach ($dues as $due) {
                $outstanding = $this->asOfBalance->outstandingForInstallmentDue($tenantId, $due, $asOf);
                if ($outstanding <= 0) {
                    continue;
                }
                $state = 'current';
                if ($this->asOfBalance->isOverdueInstallmentAsOf($tenantId, $due, $asOf)) {
                    $state = 'overdue';
                }
                $rows[] = [
                    'family_id' => $due->family_id,
                    'family_name' => $due->family?->family_name,
                    'period_label' => $due->installment_label ?? ('Installment '.$due->installment_number),
                    'due_date' => $due->due_date?->format('Y-m-d'),
                    'outstanding' => MoneyMath::toApiNumber($outstanding),
                    'schedule_state' => $state,
                ];
            }
        });

        return $rows;
    }

    /**
     * @return array{collectable: string, overdue: string, remaining: string, next_14: string, reconstruction_gaps: int}
     */
    private function aggregateBuckets(int $tenantId, ReportFilter $filter, string $asOf): array
    {
        $collectable = '0';
        $overdue = '0';
        $remaining = '0';
        $next14 = '0';
        $gaps = 0;

        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive) {
            $this->baseInstallmentDueQuery($tenantId, $filter)->chunk(200, function ($dues) use ($tenantId, $asOf, &$collectable, &$overdue, &$remaining, &$next14, &$gaps): void {
                foreach ($dues as $due) {
                    if (in_array($due->status, ['waived', 'cancelled'], true) && $due->status_changed_at === null) {
                        $gaps++;
                    }
                    if (! $this->asOfBalance->isCollectableInstallmentAsOf($tenantId, $due, $asOf)) {
                        continue;
                    }
                    $outstanding = MoneyMath::normalize($this->asOfBalance->outstandingForInstallmentDue($tenantId, $due, $asOf));
                    if (! MoneyMath::isPositive($outstanding)) {
                        continue;
                    }
                    $collectable = MoneyMath::add($collectable, $outstanding);
                    if ($this->asOfBalance->isOverdueInstallmentAsOf($tenantId, $due, $asOf)) {
                        $overdue = MoneyMath::add($overdue, $outstanding);
                    } elseif ($due->due_date && $due->due_date->format('Y-m-d') >= $asOf) {
                        $remaining = MoneyMath::add($remaining, $outstanding);
                        $dueDate = $due->due_date->format('Y-m-d');
                        $nextCutoff = Carbon::parse($asOf)->addDays(14)->toDateString();
                        if ($dueDate <= $nextCutoff) {
                            $next14 = MoneyMath::add($next14, $outstanding);
                        }
                    }
                }
            });
        } else {
            $this->baseDueQuery($tenantId, $filter)->chunk(200, function ($dues) use ($tenantId, $asOf, &$collectable, &$overdue, &$remaining, &$next14, &$gaps): void {
                foreach ($dues as $due) {
                    if (in_array($due->status, ['waived', 'cancelled'], true) && $due->status_changed_at === null) {
                        $gaps++;
                    }
                    if (! $this->asOfBalance->isCollectableAsOf($tenantId, $due, $asOf)) {
                        continue;
                    }
                    $outstanding = MoneyMath::normalize($this->asOfBalance->outstandingForDue($tenantId, $due, $asOf));
                    if (! MoneyMath::isPositive($outstanding)) {
                        continue;
                    }
                    $collectable = MoneyMath::add($collectable, $outstanding);
                    if ($this->asOfBalance->isOverdueAsOf($tenantId, $due, $asOf)) {
                        $overdue = MoneyMath::add($overdue, $outstanding);
                    } elseif ($due->due_date && $due->due_date->format('Y-m-d') >= $asOf) {
                        $remaining = MoneyMath::add($remaining, $outstanding);
                        $dueDate = $due->due_date->format('Y-m-d');
                        $nextCutoff = Carbon::parse($asOf)->addDays(14)->toDateString();
                        if ($dueDate <= $nextCutoff) {
                            $next14 = MoneyMath::add($next14, $outstanding);
                        }
                    }
                }
            });
        }

        return [
            'collectable' => $collectable,
            'overdue' => $overdue,
            'remaining' => $remaining,
            'next_14' => $next14,
            'reconstruction_gaps' => $gaps,
        ];
    }

    /**
     * @return Builder<ContributionDue>
     */
    private function baseDueQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = ContributionDue::query()->forTenant($tenantId);
        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereHas('family', fn (Builder $f) => $bcc->applyToFamilyQuery($f, $tenantId));
        }
        $planId = $filter->get('plan_id');
        if (is_string($planId) && $planId !== '') {
            $query->where('plan_id', $planId);
        }

        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive) {
            $project->applyToContributionDueQuery($query, $tenantId);
        }

        return $query;
    }

    /**
     * @return Builder<ProjectInstallmentDue>
     */
    private function baseInstallmentDueQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = ProjectInstallmentDue::query()->forTenant($tenantId);
        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive) {
            $project->applyToProjectInstallmentDueQuery($query, $tenantId);
        }
        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereHas('family', fn (Builder $f) => $bcc->applyToFamilyQuery($f, $tenantId));
        }

        return $query;
    }

    /**
     * @return Builder<ProjectInstallmentDue>
     */
    private function installmentDueQuery(int $tenantId, ReportFilter $filter, string $asOf): Builder
    {
        $query = $this->baseInstallmentDueQuery($tenantId, $filter)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<=', $asOf)
            ->with(['family:id,family_name'])
            ->orderBy('due_date')
            ->orderBy('id');

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $likeOp = $this->likeOperator();
            $query->whereHas('family', fn (Builder $f) => $f->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
        }

        return $query;
    }

    /**
     * @return Builder<ContributionDue>
     */
    private function familyDueQuery(int $tenantId, ReportFilter $filter, string $asOf): Builder
    {
        $query = ContributionBalance::scopeCollectable($this->baseDueQuery($tenantId, $filter), $asOf)
            ->with(['family:id,family_name'])
            ->orderBy('due_date')
            ->orderBy('id');

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $likeOp = $this->likeOperator();
            $query->whereHas('family', fn (Builder $f) => $f->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
        }

        return $query;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'family_name', 'label' => 'Family'],
            ['key' => 'period_label', 'label' => 'Period'],
            ['key' => 'due_date', 'label' => 'Due'],
            ['key' => 'outstanding', 'label' => 'Outstanding'],
            ['key' => 'schedule_state', 'label' => 'Status'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['family_name'],
            $row['period_label'],
            $row['due_date'],
            $row['outstanding'],
            $row['schedule_state'],
        ];
    }
}
