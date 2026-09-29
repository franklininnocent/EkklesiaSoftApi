<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class ProjectFundingReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_PROJECT_FUNDING;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        if ($this->isFamilyGapsView($filter)) {
            return $this->previewFamilyGaps($tenantId, $filter);
        }

        $query = $this->projectQuery($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $query->forPage($page, $perPage)->get()->map(fn (DonationProject $p) => $this->mapProjectRow($p))->all();
        $allRows = $this->projectQuery($tenantId, $filter)->get()->map(fn (DonationProject $p) => $this->mapProjectRow($p))->all();
        $money = $this->sumMoneyColumns($allRows, ['target_amount', 'raised_amount']);

        return [
            'columns' => $this->projectColumns(),
            'rows' => $rows,
            'totals' => [
                'project_count' => $total,
                'target_amount' => $money['target_amount'],
                'raised_amount' => $money['raised_amount'],
            ],
            'footer' => [
                'name' => $total.' projects',
                'target_amount' => $money['target_amount'],
                'raised_amount' => $money['raised_amount'],
            ],
            'meta' => $this->meta('as_of', 'gross'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['name', 'entity_kind', 'target_amount', 'raised_amount', 'funding_pct', 'status'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        if ($this->isFamilyGapsView($filter)) {
            SafeCsvWriter::putRow($handle, $this->familyGapCsvHeaders());
            $this->familyGapsQuery($tenantId, $filter)->chunk(100, function ($assignments) use ($handle, &$count): void {
                foreach ($assignments as $assignment) {
                    SafeCsvWriter::putRow($handle, $this->mapRowToCsv($this->mapFamilyGapRow($assignment)));
                    $count++;
                }
            });

            $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

            return $count;
        }

        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $this->projectQuery($tenantId, $filter)->chunk(100, function ($projects) use ($handle, &$count): void {
            foreach ($projects as $project) {
                SafeCsvWriter::putRow($handle, $this->mapRowToCsv($this->mapProjectRow($project)));
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    private function isFamilyGapsView(ReportFilter $filter): bool
    {
        return $filter->get('view') === 'gaps';
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFamilyGaps(int $tenantId, ReportFilter $filter): array
    {
        $query = $this->familyGapsQuery($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $query->forPage($page, $perPage)->get()->map(fn (ProjectFamilyAssignment $a) => $this->mapFamilyGapRow($a))->all();

        $gapTotal = '0';
        $this->familyGapsQuery($tenantId, $filter)->select(['target_amount', 'amount_collected'])->chunk(200, function ($chunk) use (&$gapTotal): void {
            foreach ($chunk as $assignment) {
                $target = (string) $assignment->target_amount;
                $collected = (string) $assignment->amount_collected;
                $gap = MoneyMath::subtract($target, $collected);
                if (MoneyMath::compare($gap, '0') > 0) {
                    $gapTotal = MoneyMath::add($gapTotal, $gap);
                }
            }
        });

        return [
            'columns' => $this->familyGapColumns(),
            'rows' => $rows,
            'totals' => [
                'family_gap_count' => $total,
                'total_funding_gap' => MoneyMath::toApiNumber($gapTotal),
            ],
            'footer' => [
                'family_name' => $total.' families',
                'funding_gap' => MoneyMath::toApiNumber($gapTotal),
            ],
            'meta' => $this->meta('as_of', 'gross', ['view' => 'gaps']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * @return Builder<DonationProject>
     */
    private function projectQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = DonationProject::query()
            ->forTenant($tenantId)
            ->whereIn('status', ['active', 'completed']);

        $kind = $filter->get('entity_kind');
        if (is_string($kind) && $kind !== '') {
            $query->where('entity_kind', $kind);
        }

        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive && $project->projectId !== null) {
            $query->where('id', $project->projectId);
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where('name', $this->likeOperator(), $like);
        }

        return ReportTableSort::applyToQuery($query, $filter, [
            'name' => 'name',
            'entity_kind' => 'entity_kind',
            'target_amount' => 'target_amount',
            'raised_amount' => 'raised_amount',
        ], static function (Builder $builder): void {
            $builder->orderBy('name');
        });
    }

    /**
     * @return Builder<ProjectFamilyAssignment>
     */
    private function familyGapsQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = ProjectFamilyAssignment::query()
            ->forTenant($tenantId)
            ->where('status', 'active')
            ->where('is_exempt', false)
            ->whereColumn('target_amount', '>', 'amount_collected')
            ->with(['family:id,family_name,family_code,bcc_id', 'project:id,name,entity_kind,status']);

        $project = $this->resolveProject($tenantId, $filter);
        if ($project->isActive && $project->projectId !== null) {
            $query->where('project_id', $project->projectId);
        }

        $kind = $filter->get('entity_kind');
        if (is_string($kind) && $kind !== '') {
            $query->whereHas('project', function (Builder $projectQuery) use ($tenantId, $kind): void {
                $projectQuery->forTenant($tenantId)->where('entity_kind', $kind);
            });
        } else {
            $query->whereHas('project', function (Builder $projectQuery) use ($tenantId): void {
                $projectQuery->forTenant($tenantId)->whereIn('status', ['active', 'completed']);
            });
        }

        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereHas('family', function (Builder $familyQuery) use ($bcc): void {
                if ($bcc->unassignedOnly) {
                    $familyQuery->whereNull('bcc_id');
                } elseif ($bcc->bccId !== null) {
                    $familyQuery->where('bcc_id', $bcc->bccId);
                }
            });
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('family', function (Builder $familyQuery) use ($like): void {
                $familyQuery->where('family_name', $this->likeOperator(), $like);
            });
        }

        $query->leftJoin('families as pfa_families', 'pfa_families.id', '=', 'project_family_assignments.family_id')
            ->leftJoin('donation_projects as pfa_projects', 'pfa_projects.id', '=', 'project_family_assignments.project_id')
            ->select('project_family_assignments.*');

        return ReportTableSort::applyToQuery($query, $filter, [
            'family_name' => 'pfa_families.family_name',
            'project_name' => 'pfa_projects.name',
            'target_amount' => 'project_family_assignments.target_amount',
            'collected_amount' => 'project_family_assignments.amount_collected',
        ], static function (Builder $builder): void {
            $builder->orderByDesc('project_family_assignments.target_amount');
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function projectColumns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Project'],
            ['key' => 'entity_kind', 'label' => 'Type'],
            ['key' => 'target_amount', 'label' => 'Target'],
            ['key' => 'raised_amount', 'label' => 'Collected'],
            ['key' => 'funding_pct', 'label' => '% funded'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function familyGapColumns(): array
    {
        return [
            ['key' => 'family_name', 'label' => 'Family'],
            ['key' => 'project_name', 'label' => 'Project'],
            ['key' => 'target_amount', 'label' => 'Pledge'],
            ['key' => 'collected_amount', 'label' => 'Collected'],
            ['key' => 'funding_gap', 'label' => 'Gap'],
            ['key' => 'funding_pct', 'label' => '% funded'],
        ];
    }

    /**
     * @return list<string>
     */
    private function familyGapCsvHeaders(): array
    {
        return ['family_name', 'family_code', 'project_name', 'target_amount', 'collected_amount', 'funding_gap', 'funding_pct'];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProjectRow(DonationProject $project): array
    {
        $target = (float) $project->target_amount;
        $raised = (float) $project->raised_amount;
        $pct = $target > 0 ? min(100, round(($raised / $target) * 100, 1)) : 0;

        return [
            'name' => $project->name,
            'entity_kind' => $project->entity_kind,
            'target_amount' => MoneyMath::toApiNumber($target),
            'raised_amount' => MoneyMath::toApiNumber($raised),
            'funding_pct' => $pct,
            'status' => $project->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFamilyGapRow(ProjectFamilyAssignment $assignment): array
    {
        $target = (string) $assignment->target_amount;
        $collected = (string) $assignment->amount_collected;
        $gap = MoneyMath::subtract($target, $collected);
        if (MoneyMath::compare($gap, '0') < 0) {
            $gap = '0';
        }
        $targetFloat = (float) $target;
        $collectedFloat = (float) $collected;
        $pct = $targetFloat > 0 ? min(100, round(($collectedFloat / $targetFloat) * 100, 1)) : 0;

        return [
            'family_id' => $assignment->family_id,
            'family_name' => $assignment->family?->family_name,
            'family_code' => $assignment->family?->family_code,
            'project_name' => $assignment->project?->name,
            'target_amount' => MoneyMath::toApiNumber($target),
            'collected_amount' => MoneyMath::toApiNumber($collected),
            'funding_gap' => MoneyMath::toApiNumber($gap),
            'funding_pct' => $pct,
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        if (isset($row['funding_gap'], $row['family_name'])) {
            return [
                $row['family_name'],
                $row['family_code'],
                $row['project_name'],
                $row['target_amount'],
                $row['collected_amount'],
                $row['funding_gap'],
                $row['funding_pct'],
            ];
        }

        return [
            $row['name'],
            $row['entity_kind'],
            $row['target_amount'],
            $row['raised_amount'],
            $row['funding_pct'],
            $row['status'] ?? '',
        ];
    }
}
