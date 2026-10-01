<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Family\Support\FamilyHeadDisplayName;

class DonationProjectService
{
    public function __construct(private readonly DonationAuditService $auditService) {}

    public function create(int $tenantId, int $userId, array $payload): DonationProject
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): DonationProject {
            $assignments = $payload['assignments'] ?? [];
            unset($payload['assignments']);

            $payload['tenant_id'] = $tenantId;
            $payload['created_by'] = $userId;
            $payload['updated_by'] = $userId;
            $payload['raised_amount'] = $payload['raised_amount'] ?? 0;
            $payload['entity_kind'] = $payload['entity_kind'] ?? 'project';

            $project = DonationProject::create($payload);

            if (! empty($assignments)) {
                $this->syncAssignments($tenantId, $userId, $project, $assignments);
            }

            $this->auditService->log($tenantId, 'project.created', 'project', $project->id, null, $project->toArray());

            return $project->fresh(['fund', 'assignments.family']);
        });
    }

    public function update(int $tenantId, int $userId, DonationProject $project, array $payload): DonationProject
    {
        return DB::transaction(function () use ($tenantId, $userId, $project, $payload): DonationProject {
            $assignments = $payload['assignments'] ?? null;
            unset($payload['assignments']);

            $project->fill($payload);
            $project->updated_by = $userId;
            $project->save();

            if ($assignments !== null) {
                $this->syncAssignments($tenantId, $userId, $project, $assignments);
            }

            $this->maybeMarkCompleted($project);

            $this->auditService->log($tenantId, 'project.updated', 'project', $project->id, null, $project->fresh()->toArray());

            return $project->fresh(['fund', 'assignments.family']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    public function syncAssignments(int $tenantId, int $userId, DonationProject $project, array $assignments): void
    {
        foreach ($assignments as $row) {
            $familyId = $row['family_id'];
            $effectiveFrom = $row['effective_from'] ?? now()->toDateString();

            ProjectFamilyAssignment::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'family_id' => $familyId,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'target_amount' => $row['is_exempt'] ?? false ? null : (float) ($row['target_amount'] ?? $project->default_family_target),
                    'is_exempt' => (bool) ($row['is_exempt'] ?? false),
                    'effective_to' => $row['effective_to'] ?? null,
                    'status' => $row['status'] ?? 'active',
                    'notes' => $row['notes'] ?? null,
                    'updated_by' => $userId,
                ]
            );
        }
    }

    /**
     * Family targets for installment generation, resolved in memory from one assignment query.
     *
     * @param  array<int, string>  $familyIds
     * @return array<string, float>
     */
    public function positiveFamilyTargets(DonationProject $project, array $familyIds, string $asOfDate): array
    {
        if ($familyIds === []) {
            return [];
        }

        $enrolled = array_fill_keys($familyIds, true);
        $latest = [];

        $assignments = ProjectFamilyAssignment::forTenant($project->tenant_id)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->get(['family_id', 'target_amount', 'is_exempt', 'effective_from']);

        foreach ($assignments as $assignment) {
            if (! isset($enrolled[$assignment->family_id]) || isset($latest[$assignment->family_id])) {
                continue;
            }
            $latest[$assignment->family_id] = $assignment;
        }

        $targets = [];
        foreach ($familyIds as $familyId) {
            $target = $this->resolveFamilyTargetFromAssignment($project, $latest[$familyId] ?? null);
            if ($target !== null && $target > 0) {
                $targets[$familyId] = $target;
            }
        }

        return $targets;
    }

    public function resolveFamilyTarget(DonationProject $project, string $familyId, string $asOfDate): ?float
    {
        $override = $this->findActiveAssignment($project, $familyId, $asOfDate);

        if ($override?->is_exempt) {
            return null;
        }

        if ($override && $override->target_amount !== null) {
            return (float) $override->target_amount;
        }

        if ($project->assignment_mode === 'individual') {
            return null;
        }

        return (float) $project->default_family_target;
    }

    /**
     * @return array<int, string>
     */
    public function getEnrolledFamilyIds(DonationProject $project, string $asOfDate): array
    {
        if ($project->assignment_mode === 'individual') {
            return ProjectFamilyAssignment::forTenant($project->tenant_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->where('is_exempt', false)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $asOfDate);
                })
                ->pluck('family_id')
                ->unique()
                ->values()
                ->all();
        }

        $familyIds = Family::query()
            ->where('tenant_id', $project->tenant_id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($project->assignment_mode === 'uniform_with_exceptions') {
            $exemptIds = ProjectFamilyAssignment::forTenant($project->tenant_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->where('is_exempt', true)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $asOfDate);
                })
                ->pluck('family_id')
                ->all();

            $familyIds = array_values(array_diff($familyIds, $exemptIds));
        }

        return $familyIds;
    }

    /**
     * Lightweight metrics for project list screens (avoids per-family dashboard aggregation).
     *
     * @return array{
     *     overall_target: float,
     *     has_funding_target: bool,
     *     collection_percentage: float|null,
     *     families_enrolled: int,
     *     needs_attention: bool
     * }
     */
    public function getListSummary(int $tenantId, DonationProject $project): array
    {
        $summaries = $this->summarizeProjectsForList($tenantId, collect([$project]));

        return $summaries[$project->id] ?? $this->emptyListSummary($project);
    }

    /**
     * Batch list metrics for project index (avoids N+1 family/assignment queries).
     *
     * @param  Collection<int, DonationProject>  $projects
     * @return array<string, array{
     *     overall_target: float,
     *     has_funding_target: bool,
     *     collection_percentage: float|null,
     *     families_enrolled: int,
     *     needs_attention: bool
     * }>
     */
    public function summarizeProjectsForList(int $tenantId, Collection $projects, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? now()->toDateString();

        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->all();

        $activeFamilyIds = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $assignments = ProjectFamilyAssignment::forTenant($tenantId)
            ->whereIn('project_id', $projectIds)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->get();

        /** @var array<string, array<string, ProjectFamilyAssignment>> $latestAssignmentByProjectFamily */
        $latestAssignmentByProjectFamily = [];
        /** @var array<string, array<string, true>> $exemptFamilyIdsByProject */
        $exemptFamilyIdsByProject = [];
        /** @var array<string, array<string, true>> $individualEnrolledByProject */
        $individualEnrolledByProject = [];

        foreach ($assignments as $assignment) {
            $projectId = $assignment->project_id;
            $familyId = $assignment->family_id;

            if (! isset($latestAssignmentByProjectFamily[$projectId][$familyId])) {
                $latestAssignmentByProjectFamily[$projectId][$familyId] = $assignment;
            }

            if ($assignment->is_exempt) {
                $exemptFamilyIdsByProject[$projectId][$familyId] = true;
            }
        }

        foreach ($projects as $project) {
            if ($project->assignment_mode !== 'individual') {
                continue;
            }
            foreach ($latestAssignmentByProjectFamily[$project->id] ?? [] as $familyId => $assignment) {
                if (! $assignment->is_exempt) {
                    $individualEnrolledByProject[$project->id][$familyId] = true;
                }
            }
        }

        $summaries = [];
        foreach ($projects as $project) {
            $enrolledIds = $this->resolveEnrolledFamilyIdsFromContext(
                $project,
                $activeFamilyIds,
                array_keys($individualEnrolledByProject[$project->id] ?? []),
                array_keys($exemptFamilyIdsByProject[$project->id] ?? [])
            );

            $overallTarget = $this->resolveOverallFundingTargetFromContext(
                $project,
                $enrolledIds,
                $latestAssignmentByProjectFamily[$project->id] ?? []
            );

            $collected = (float) $project->raised_amount;
            $collectionPercentage = $overallTarget > 0
                ? round(($collected / $overallTarget) * 100, 2)
                : null;

            $summaries[$project->id] = [
                'overall_target' => round($overallTarget, 2),
                'has_funding_target' => $overallTarget > 0,
                'collection_percentage' => $collectionPercentage,
                'families_enrolled' => count($enrolledIds),
                'needs_attention' => $this->projectNeedsAttention($project, $overallTarget, $collectionPercentage, $asOfDate),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<int, string>  $activeFamilyIds
     * @param  array<int, string>  $individualEnrolledFamilyIds
     * @param  array<int, string>  $exemptFamilyIds
     * @return array<int, string>
     */
    private function resolveEnrolledFamilyIdsFromContext(
        DonationProject $project,
        array $activeFamilyIds,
        array $individualEnrolledFamilyIds,
        array $exemptFamilyIds
    ): array {
        if ($project->assignment_mode === 'individual') {
            return array_values($individualEnrolledFamilyIds);
        }

        $familyIds = $activeFamilyIds;

        if ($project->assignment_mode === 'uniform_with_exceptions' && $exemptFamilyIds !== []) {
            $exemptSet = array_fill_keys($exemptFamilyIds, true);
            $familyIds = array_values(array_filter(
                $familyIds,
                static fn (string $id): bool => ! isset($exemptSet[$id])
            ));
        }

        return $familyIds;
    }

    /**
     * @param  array<int, string>  $enrolledFamilyIds
     * @param  array<string, ProjectFamilyAssignment>  $assignmentsByFamilyId
     */
    private function resolveOverallFundingTargetFromContext(
        DonationProject $project,
        array $enrolledFamilyIds,
        array $assignmentsByFamilyId
    ): float {
        $explicit = (float) $project->target_amount;
        if ($explicit > 0) {
            return $explicit;
        }

        $total = 0.0;
        foreach ($enrolledFamilyIds as $familyId) {
            $total += (float) ($this->resolveFamilyTargetFromAssignment(
                $project,
                $assignmentsByFamilyId[$familyId] ?? null
            ) ?? 0);
        }

        return $total;
    }

    private function resolveFamilyTargetFromAssignment(
        DonationProject $project,
        ?ProjectFamilyAssignment $assignment
    ): ?float {
        if ($assignment?->is_exempt) {
            return null;
        }

        if ($assignment && $assignment->target_amount !== null) {
            return (float) $assignment->target_amount;
        }

        if ($project->assignment_mode === 'individual') {
            return null;
        }

        return (float) $project->default_family_target;
    }

    /**
     * @return array{
     *     overall_target: float,
     *     has_funding_target: bool,
     *     collection_percentage: float|null,
     *     families_enrolled: int,
     *     needs_attention: bool
     * }
     */
    private function emptyListSummary(DonationProject $project): array
    {
        return [
            'overall_target' => 0.0,
            'has_funding_target' => false,
            'collection_percentage' => null,
            'families_enrolled' => 0,
            'needs_attention' => false,
        ];
    }

    /**
     * Active projects below 50% of their resolved funding target, or within 14 days of end date
     * while not fully funded (matches stewardship project/campaign card rules).
     */
    public function projectNeedsAttention(
        DonationProject $project,
        float $overallTarget,
        ?float $collectionPercentage,
        string $asOfDate
    ): bool {
        if ($project->status !== 'active') {
            return false;
        }

        if ($overallTarget > 0 && $collectionPercentage !== null && $collectionPercentage < 50) {
            return true;
        }

        if ($project->end_date) {
            $end = Carbon::parse($project->end_date)->startOfDay();
            $asOf = Carbon::parse($asOfDate)->startOfDay();
            $daysRemaining = (int) $asOf->diffInDays($end, false);
            if ($daysRemaining >= 0 && $daysRemaining <= 14) {
                if ($overallTarget <= 0 || $collectionPercentage === null || $collectionPercentage < 100) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getDashboard(int $tenantId, DonationProject $project): array
    {
        $asOfDate = now()->toDateString();
        $enrolledIds = $this->getEnrolledFamilyIds($project, $asOfDate);

        $exemptCount = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->where('is_exempt', true)
            ->count();

        $familyTargets = $this->buildFamilyProgressRows($tenantId, $project, $enrolledIds, $asOfDate);

        $completedFamilies = 0;
        $partialFamilies = 0;
        $totalFamilyTarget = 0;

        foreach ($familyTargets as $row) {
            $totalFamilyTarget += $row['raw_target'];

            if ($row['status'] === 'completed') {
                $completedFamilies++;
            } elseif ($row['status'] === 'partial') {
                $partialFamilies++;
            }
        }

        $familyTargets = array_map(
            static fn (array $row): array => array_diff_key($row, ['raw_target' => true, 'status' => true]),
            $familyTargets
        );

        $installmentOutstanding = ContributionBalance::sumOutstanding(
            ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)
        );

        $explicitTarget = (float) $project->target_amount;
        $overallTarget = $explicitTarget > 0 ? $explicitTarget : $totalFamilyTarget;

        $collected = (float) $project->raised_amount;
        $collectionPercentage = $overallTarget > 0 ? round(($collected / $overallTarget) * 100, 2) : 0;

        return [
            'project' => $project->load('fund'),
            'totals' => [
                'overall_target' => round($overallTarget, 2),
                'family_target_total' => round($totalFamilyTarget, 2),
                'collected' => round($collected, 2),
                'outstanding' => round(max(0, $overallTarget - $collected), 2),
                'installment_outstanding' => round($installmentOutstanding, 2),
                'collection_percentage' => $collectionPercentage,
            ],
            'families' => [
                'enrolled' => count($enrolledIds),
                'completed' => $completedFamilies,
                'partial' => $partialFamilies,
                'exempt' => $exemptCount,
            ],
            'family_progress' => $familyTargets,
            'installments' => [
                'total' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->count(),
                'paid' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->where('status', 'paid')->count(),
                'pending' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->whereIn('status', ['pending', 'partially_paid'])->count(),
            ],
        ];
    }

    /**
     * Enrolled-family progress for the project detail table, searched, filtered, sorted, and paginated server-side.
     *
     * @param  array{search: string, status: string|null, sort: string, direction: string, page: int, per_page: int}  $options
     * @return array{paginator: LengthAwarePaginator, bcc_options: array<int, array{id: string, name: string}>}
     */
    public function paginateFamilyProgress(
        int $tenantId,
        DonationProject $project,
        DashboardBccFilter $bccFilter,
        array $options
    ): array {
        $asOfDate = now()->toDateString();
        $enrolledIds = $this->getEnrolledFamilyIds($project, $asOfDate);
        $rows = $this->buildFamilyProgressRows($tenantId, $project, $enrolledIds, $asOfDate);
        $families = $this->loadProgressFamilies($tenantId, $enrolledIds);
        $bccNames = $this->bccNamesFor($tenantId, $families);

        $needle = mb_strtolower($options['search']);
        $filtered = [];
        foreach ($rows as $row) {
            if ($options['status'] !== null && $row['status'] !== $options['status']) {
                continue;
            }

            $family = $families[$row['family_id']] ?? null;
            if ($family === null) {
                if ($bccFilter->isActive || $needle !== '') {
                    continue;
                }
            } elseif (! $bccFilter->familyBelongsToFilter($family)) {
                continue;
            }

            $headName = $family !== null ? FamilyHeadDisplayName::resolve($family) : null;
            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $family->family_code,
                    $family->family_name,
                    $family->head_of_family,
                    $headName,
                ])));
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }

            unset($row['raw_target']);
            $bccId = $family?->bcc_id !== null ? (string) $family->bcc_id : null;
            $filtered[] = array_merge($row, [
                'family_code' => $family?->family_code,
                'family_name' => $family?->family_name,
                'head_of_family' => $headName,
                'bcc_id' => $bccId,
                'bcc_name' => $bccId !== null ? ($bccNames[$bccId] ?? null) : null,
            ]);
        }

        $this->sortFamilyProgressRows($filtered, $options['sort'], $options['direction']);

        $perPage = $options['per_page'];
        $page = $options['page'];
        $paginator = new LengthAwarePaginator(
            array_values(array_slice($filtered, ($page - 1) * $perPage, $perPage)),
            count($filtered),
            $perPage,
            $page
        );

        return [
            'paginator' => $paginator,
            'bcc_options' => $this->bccFilterOptions($families, $bccNames),
        ];
    }

    /**
     * @param  array<int, string>  $enrolledIds
     * @return array<int, array{
     *     family_id: string,
     *     target_amount: float,
     *     amount_collected: float,
     *     outstanding_amount: float,
     *     completion_percentage: float|int,
     *     status: string,
     *     raw_target: float
     * }>
     */
    private function buildFamilyProgressRows(int $tenantId, DonationProject $project, array $enrolledIds, string $asOfDate): array
    {
        $targetsByFamily = $this->positiveFamilyTargets($project, $enrolledIds, $asOfDate);
        $assignmentCollected = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->selectRaw('family_id, SUM(amount_collected) as collected')
            ->groupBy('family_id')
            ->pluck('collected', 'family_id');
        $installmentPaid = ProjectInstallmentDue::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->selectRaw('family_id, SUM(amount_paid) as paid')
            ->groupBy('family_id')
            ->pluck('paid', 'family_id');

        $rows = [];
        foreach ($enrolledIds as $familyId) {
            $target = $targetsByFamily[$familyId] ?? 0;
            $fromAssignment = (float) ($assignmentCollected[$familyId] ?? 0);
            $collected = $fromAssignment > 0
                ? $fromAssignment
                : (float) ($installmentPaid[$familyId] ?? 0);
            $outstanding = max(0, $target - $collected);

            $status = 'not_started';
            if ($target > 0 && $collected >= $target) {
                $status = 'completed';
            } elseif ($collected > 0) {
                $status = 'partial';
            }

            $rows[] = [
                'family_id' => $familyId,
                'target_amount' => round($target, 2),
                'amount_collected' => round($collected, 2),
                'outstanding_amount' => round($outstanding, 2),
                'completion_percentage' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
                'status' => $status,
                'raw_target' => (float) $target,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $familyIds
     * @return array<string, Family>
     */
    private function loadProgressFamilies(int $tenantId, array $familyIds): array
    {
        $families = [];
        foreach (array_chunk($familyIds, 1000) as $chunk) {
            Family::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $chunk)
                ->select(['id', 'tenant_id', 'family_code', 'family_name', 'head_of_family', 'bcc_id'])
                ->with(['members' => function ($query): void {
                    $query->whereIn('relationship_to_head', ['self', 'head']);
                }])
                ->get()
                ->each(function (Family $family) use (&$families): void {
                    $families[(string) $family->id] = $family;
                });
        }

        return $families;
    }

    /**
     * @param  array<string, Family>  $families
     * @return array<string, string>
     */
    private function bccNamesFor(int $tenantId, array $families): array
    {
        $bccIds = array_values(array_unique(array_filter(array_map(
            static fn (Family $family): ?string => $family->bcc_id !== null ? (string) $family->bcc_id : null,
            $families
        ))));

        if ($bccIds === []) {
            return [];
        }

        return DB::table('bccs')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $bccIds)
            ->whereNull('deleted_at')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  array<string, Family>  $families
     * @param  array<string, string>  $bccNames
     * @return array<int, array{id: string, name: string}>
     */
    private function bccFilterOptions(array $families, array $bccNames): array
    {
        $hasUnassigned = false;
        foreach ($families as $family) {
            if ($family->bcc_id === null) {
                $hasUnassigned = true;
                break;
            }
        }

        asort($bccNames, SORT_NATURAL | SORT_FLAG_CASE);
        $options = [];
        if ($hasUnassigned) {
            $options[] = ['id' => DashboardBccFilter::UNASSIGNED, 'name' => 'Unassigned Area'];
        }
        foreach ($bccNames as $id => $name) {
            $options[] = ['id' => (string) $id, 'name' => $name];
        }

        return $options;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sortFamilyProgressRows(array &$rows, string $sort, string $direction): void
    {
        $factor = $direction === 'desc' ? -1 : 1;
        $textKey = static fn (array $row): string => mb_strtolower((string) ($row['head_of_family'] ?? $row['family_name'] ?? ''));

        usort($rows, static function (array $a, array $b) use ($sort, $factor, $textKey): int {
            $result = match ($sort) {
                'family_name' => strnatcasecmp($textKey($a), $textKey($b)),
                'family_code' => strnatcasecmp((string) ($a['family_code'] ?? ''), (string) ($b['family_code'] ?? '')),
                default => ((float) $a[$sort]) <=> ((float) $b[$sort]),
            };

            if ($result !== 0) {
                return $result * $factor;
            }

            return strnatcasecmp((string) ($a['family_code'] ?? ''), (string) ($b['family_code'] ?? ''))
                ?: strcmp((string) $a['family_id'], (string) $b['family_id']);
        });
    }

    public function recordCollection(int $tenantId, DonationProject $project, string $familyId, string|float|int $amount): void
    {
        $project->raised_amount = MoneyMath::add($project->raised_amount ?? 0, $amount);
        $project->save();

        $assignment = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->latest('effective_from')
            ->first();

        if ($assignment) {
            $assignment->amount_collected = MoneyMath::add($assignment->amount_collected ?? 0, $amount);
            $assignment->save();
        }

        $this->maybeMarkCompleted($project->fresh());
    }

    public function getFamilyProjectSummary(int $tenantId, string $familyId): array
    {
        $asOfDate = now()->toDateString();
        $activeProjects = DonationProject::forTenant($tenantId)
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('name')
            ->get();

        $projects = [];
        $totalTarget = 0;
        $totalCollected = 0;
        $totalOutstanding = 0;
        $totalInstallmentOutstanding = 0;

        foreach ($activeProjects as $project) {
            $enrolledIds = $this->getEnrolledFamilyIds($project, $asOfDate);
            if (! in_array($familyId, $enrolledIds, true)) {
                continue;
            }

            $target = $this->resolveFamilyTarget($project, $familyId, $asOfDate) ?? 0;
            $collected = $this->resolveFamilyCollected($tenantId, $project->id, $familyId);
            $outstanding = max(0, $target - $collected);
            $installmentOutstanding = ContributionBalance::sumOutstanding(
                ProjectInstallmentDue::forTenant($tenantId)
                    ->where('project_id', $project->id)
                    ->where('family_id', $familyId)
            );

            $projects[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_code' => $project->code,
                'assignment_mode' => $project->assignment_mode,
                'target_amount' => round($target, 2),
                'amount_collected' => round($collected, 2),
                'outstanding_amount' => round($outstanding, 2),
                'installment_outstanding' => round($installmentOutstanding, 2),
                'completion_percentage' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
                'status' => $project->status,
            ];

            $totalTarget += $target;
            $totalCollected += $collected;
            $totalOutstanding += $outstanding;
            $totalInstallmentOutstanding += $installmentOutstanding;
        }

        $outstandingInstallments = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->with('project:id,name,code')
            ->orderBy('due_date')
            ->limit(20)
            ->get()
            ->map(function (ProjectInstallmentDue $due): array {
                return [
                    'id' => $due->id,
                    'project_id' => $due->project_id,
                    'project_name' => $due->project?->name,
                    'installment_label' => $due->installment_label,
                    'due_date' => $due->due_date?->toDateString(),
                    'amount_due' => (float) $due->amount_due,
                    'amount_paid' => (float) $due->amount_paid,
                    'outstanding_amount' => ContributionBalance::outstandingForDue($due),
                    'status' => $due->status,
                ];
            });

        return [
            'projects' => $projects,
            'outstanding_installments' => $outstandingInstallments,
            'totals' => [
                'target_total' => round($totalTarget, 2),
                'collected' => round($totalCollected, 2),
                'outstanding' => round($totalOutstanding, 2),
                'installment_outstanding' => round($totalInstallmentOutstanding, 2),
            ],
        ];
    }

    public function reverseCollection(int $tenantId, DonationProject $project, string $familyId, string|float|int $amount): void
    {
        $project->raised_amount = MoneyMath::floorAtZero(MoneyMath::subtract($project->raised_amount ?? 0, $amount));
        $project->save();

        $assignment = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->latest('effective_from')
            ->first();

        if ($assignment) {
            $assignment->amount_collected = MoneyMath::floorAtZero(MoneyMath::subtract($assignment->amount_collected ?? 0, $amount));
            $assignment->save();
        }
    }

    public function maybeMarkCompleted(DonationProject $project): void
    {
        if ($project->status !== 'active') {
            return;
        }

        $dashboard = $this->getDashboard($project->tenant_id, $project);
        $overallTarget = $dashboard['totals']['overall_target'];
        $collected = $dashboard['totals']['collected'];

        if ($overallTarget > 0 && $collected >= $overallTarget) {
            $project->status = 'completed';
            $project->completed_at = now();
            $project->save();
        }
    }

    private function resolveFamilyCollected(int $tenantId, string $projectId, string $familyId): float
    {
        $assignmentCollected = (float) ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $familyId)
            ->sum('amount_collected');

        if ($assignmentCollected > 0) {
            return $assignmentCollected;
        }

        return (float) ProjectInstallmentDue::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $familyId)
            ->sum('amount_paid');
    }

    private function resolveOverallFundingTarget(DonationProject $project, string $asOfDate): float
    {
        $explicit = (float) $project->target_amount;
        if ($explicit > 0) {
            return $explicit;
        }

        $totalFamilyTarget = 0.0;
        foreach ($this->getEnrolledFamilyIds($project, $asOfDate) as $familyId) {
            $totalFamilyTarget += (float) ($this->resolveFamilyTarget($project, $familyId, $asOfDate) ?? 0);
        }

        return $totalFamilyTarget;
    }

    private function findActiveAssignment(DonationProject $project, string $familyId, string $asOfDate): ?ProjectFamilyAssignment
    {
        return ProjectFamilyAssignment::forTenant($project->tenant_id)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
