<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;
use Modules\Family\Models\Family;

final class ParticipationReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_PARTICIPATION;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $range = $this->resolveDateRange($tenantId, $filter);
        $bcc = $this->resolveBcc($tenantId, $filter);
        $project = $this->resolveProject($tenantId, $filter);
        $metrics = app(ExecutiveReportMetricsService::class);

        $participating = $metrics->countDistinctParticipatingFamilies($tenantId, $range, $bcc, $project);
        $activeQuery = Family::query()->where('tenant_id', $tenantId)->where('status', 'active');
        $bcc->applyToFamilyQuery($activeQuery, $tenantId);
        $project->applyToFamilyQuery($activeQuery, $tenantId);
        $active = (int) (clone $activeQuery)->count();

        $wantNotParticipating = $filter->get('participation_status', 'not_participating') !== 'participating';
        $familyQuery = $this->familiesQuery($tenantId, $filter, $wantNotParticipating, $range, $bcc, $project);

        $total = (clone $familyQuery)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $familyQuery->forPage($page, $perPage)->get()->map(fn (Family $f) => [
            'family_id' => $f->id,
            'family_code' => $f->family_code,
            'family_name' => $f->family_name,
            'bcc_code' => $f->bcc?->bcc_code,
        ])->all();

        return [
            'columns' => [
                ['key' => 'family_code', 'label' => 'Family ID'],
                ['key' => 'family_name', 'label' => 'Family'],
                ['key' => 'bcc_code', 'label' => 'BCC code'],
            ],
            'rows' => $rows,
            'totals' => [
                'active_families' => $active,
                'participating_families' => $participating,
                'not_participating_families' => max(0, $active - $participating),
                'listed_families' => $total,
            ],
            'footer' => [
                'family_code' => $total.' families',
                'family_name' => $wantNotParticipating
                    ? max(0, $active - $participating).' not participating'
                    : $participating.' participating',
            ],
            'meta' => $this->meta('participation_window', 'gross', ['metric_kind' => 'family_counts']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['family_code', 'family_name', 'bcc_code'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $range = $this->resolveDateRange($tenantId, $filter);
        $bcc = $this->resolveBcc($tenantId, $filter);
        $project = $this->resolveProject($tenantId, $filter);
        $wantNotParticipating = $filter->get('participation_status', 'not_participating') !== 'participating';
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $this->familiesQuery($tenantId, $filter, $wantNotParticipating, $range, $bcc, $project)
            ->chunk(200, function ($families) use ($handle, &$count): void {
                foreach ($families as $family) {
                    SafeCsvWriter::putRow($handle, [
                        $family->family_code,
                        $family->family_name,
                        $family->bcc?->bcc_code,
                    ]);
                    $count++;
                }
            });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return Builder<Family>
     */
    private function familiesQuery(
        int $tenantId,
        ReportFilter $filter,
        bool $notParticipating,
        ?DashboardDateRange $range,
        DashboardBccFilter $bcc,
        DashboardProjectFilter $project,
    ): Builder {
        $start = $range?->dateFrom ?? DonationBusinessDate::subDays($tenantId, 90);
        $end = $range?->collectionEnd ?? DonationBusinessDate::today($tenantId);

        $participatingIds = DonationPayment::query()
            ->forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $start)
            ->whereDate('payment_date', '<=', $end)
            ->distinct()
            ->pluck('family_id');

        $query = Family::query()
            ->where('families.tenant_id', $tenantId)
            ->where('families.status', 'active')
            ->leftJoin('bccs as participation_bccs', function ($join) use ($tenantId): void {
                $join->on('participation_bccs.id', '=', 'families.bcc_id')
                    ->where('participation_bccs.tenant_id', '=', $tenantId)
                    ->whereNull('participation_bccs.deleted_at');
            })
            ->select('families.*')
            ->with('bcc:id,bcc_code');
        ReportTableSort::applyToQuery($query, $filter, [
            'family_code' => 'families.family_code',
            'family_name' => 'families.family_name',
            'bcc_code' => 'participation_bccs.bcc_code',
        ], static function (Builder $builder): void {
            $builder->orderBy('families.family_name')->orderBy('families.id');
        });
        if ($bcc->isActive) {
            if ($bcc->unassignedOnly) {
                $query->whereNull('families.bcc_id');
            } elseif ($bcc->bccId !== null) {
                $query->where('families.bcc_id', $bcc->bccId);
            }
        }
        if ($project->isActive && $project->projectId !== null) {
            $projectId = $project->projectId;
            $query->where(function (Builder $scoped) use ($tenantId, $projectId): void {
                $scoped->whereExists(function ($sub) use ($tenantId, $projectId): void {
                    $sub->from('project_family_assignments')
                        ->whereColumn('project_family_assignments.family_id', 'families.id')
                        ->where('project_family_assignments.tenant_id', $tenantId)
                        ->where('project_family_assignments.project_id', $projectId)
                        ->whereNull('project_family_assignments.deleted_at');
                })->orWhereExists(function ($sub) use ($tenantId, $projectId): void {
                    $sub->from('project_installment_dues')
                        ->whereColumn('project_installment_dues.family_id', 'families.id')
                        ->where('project_installment_dues.tenant_id', $tenantId)
                        ->where('project_installment_dues.project_id', $projectId)
                        ->whereNull('project_installment_dues.deleted_at');
                });
            });
        }

        if ($notParticipating) {
            $query->whereNotIn('families.id', $participatingIds);
        } else {
            $query->whereIn('families.id', $participatingIds);
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $likeOp = $this->likeOperator();
                $inner->where('families.family_name', $likeOp, $like)
                    ->orWhere('families.family_code', $likeOp, $like);
            });
        }

        return $query;
    }

    protected function mapRowToCsv(array $row): array
    {
        return [$row['family_code'], $row['family_name'], $row['bcc_code'] ?? ''];
    }
}
