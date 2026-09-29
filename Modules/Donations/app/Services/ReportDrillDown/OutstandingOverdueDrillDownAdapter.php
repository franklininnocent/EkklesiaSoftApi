<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;

class OutstandingOverdueDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'outstanding_overdue';

    /** @var array<string, array{slice_id: string, uses_floored: bool}> */
    private const ELEMENT_SLICE_MAP = [
        'overdue_amount' => ['slice_id' => 'overdue', 'uses_floored' => true],
        'overdue_family_count' => ['slice_id' => 'overdue', 'uses_floored' => true],
        'remaining_collectable' => ['slice_id' => 'remaining_collectable', 'uses_floored' => true],
        'next_14_days_amount' => ['slice_id' => 'upcoming_14d', 'uses_floored' => true],
        'later_remaining_amount' => ['slice_id' => 'later_remaining', 'uses_floored' => true],
        'pending_dues' => ['slice_id' => 'total_outstanding', 'uses_floored' => false],
    ];

    public function __construct(
        private readonly ExecutiveReportMetricsService $executiveMetrics
    ) {}

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return self::ELEMENT_SLICE_MAP[$dataElementId]['slice_id'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function supportedSorts(): array
    {
        return [
            'outstanding_amount',
            'family_name',
            'family_code',
            'oldest_due_date',
            'due_count',
            'bcc_name',
            'days_overdue',
        ];
    }

    public function graphId(): string
    {
        return self::GRAPH_ID;
    }

    public function supportedDataElementIds(): array
    {
        return array_keys(self::ELEMENT_SLICE_MAP);
    }

    public function supportedSliceIds(): array
    {
        return ['overdue', 'remaining_collectable', 'upcoming_14d', 'later_remaining', 'total_outstanding'];
    }

    public function build(int $tenantId, array $validated): array
    {
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $elementConfig = self::ELEMENT_SLICE_MAP[$dataElementId] ?? null;

        if ($elementConfig === null || $elementConfig['slice_id'] !== $sliceId) {
            throw new \InvalidArgumentException('Invalid data element for this slice.');
        }

        $dashboardRange = $this->dashboardRange($tenantId, $validated);
        $businessDate = $dashboardRange?->asOf ?? DonationBusinessDate::today($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $usesFloored = $elementConfig['uses_floored'];
        $sumExpr = $usesFloored
            ? ContributionBalance::flooredOutstandingSumSqlExpression()
            : ContributionBalance::rawOutstandingSumSqlExpression();

        $graphOutstanding = $this->executiveMetrics->sumPendingDuesCollectable($tenantId, $businessDate);
        $overdueTotals = $this->executiveMetrics->overdueAttentionTotals($tenantId, $businessDate);
        $graphOverdueAmount = (float) $overdueTotals['total_overdue_amount'];
        $graphOverdueFamilyCount = (int) $overdueTotals['count'];
        $graphRemaining = $this->executiveMetrics->remainingCollectableRecordAmount($tenantId, $businessDate);
        $residualRemaining = $this->executiveMetrics->remainingCollectableResidual($graphOutstanding, $graphOverdueAmount);
        $dueSchedule = $this->executiveMetrics->dueSchedulePartition($tenantId, $dashboardRange);

        $parishToday = DonationBusinessDate::today($tenantId);
        [$scopeCallback, $expectedAmount, $expectedCount, $why, $workspaceQuery] = $this->resolveSliceMeta(
            $sliceId,
            $tenantId,
            $businessDate,
            $parishToday,
            $graphOutstanding,
            $graphOverdueAmount,
            $graphOverdueFamilyCount,
            $graphRemaining,
            $dueSchedule
        );

        $diagnostics = [];
        if ($sliceId === 'remaining_collectable') {
            if (! MoneyMath::equals($residualRemaining, $graphRemaining)) {
                $diagnostics['residual_formula_amount'] = $residualRemaining;
                $diagnostics['record_scope_amount'] = $graphRemaining;
            }
        }

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $bccFilter = isset($filters['bcc_id']) ? (string) $filters['bcc_id'] : '';
        $sort = (string) ($validated['sort'] ?? 'outstanding_amount');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $innerQuery = $this->buildFamilyAggregateSubquery($tenantId, $scopeCallback, $sumExpr);
        $outerQuery = $this->buildOuterFamilyQuery($innerQuery, $tenantId);
        $this->applySearch($outerQuery, $search);
        $this->applyBccFilter($outerQuery, $bccFilter, $tenantId);

        $filteredSummary = $this->buildFilteredSummary($outerQuery);
        $totalFamilies = $this->countFilteredFamilies($outerQuery);

        $sortColumn = $this->resolveSortColumn($sort, $sliceId);
        $outerQuery->orderBy($sortColumn, $direction);

        $rows = $outerQuery
            ->forPage($page, $perPage)
            ->get();

        $businessDay = Carbon::parse($businessDate, $timezone)->startOfDay();
        $items = $rows->map(function ($row) use ($businessDay, $sliceId) {
            $oldest = $row->oldest_due_date ?? null;
            $daysOverdue = null;
            if ($sliceId === 'overdue' && $oldest) {
                $daysOverdue = (int) Carbon::parse($oldest)->diffInDays($businessDay);
            }

            return [
                'family_id' => $row->family_id,
                'family_name' => $row->family_name ?: 'Family record unavailable',
                'head_of_family' => $row->head_of_family,
                'family_code' => $row->family_code,
                'bcc_id' => $row->bcc_id,
                'bcc_name' => $row->bcc_name ?? 'Unassigned Area',
                'outstanding_amount' => MoneyMath::toApiNumber($row->outstanding_amount ?? 0),
                'due_count' => (int) ($row->due_count ?? 0),
                'oldest_due_date' => $oldest,
                'days_overdue' => $daysOverdue,
            ];
        })->values()->all();

        $bccOptions = $this->buildBccFilterOptions($tenantId, $scopeCallback, $sumExpr);

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => $dataElementId,
            'slice_id' => $sliceId,
            'dimension' => 'family',
            'record_kind' => 'family',
            'point_kind' => 'actual',
            'value_kind' => 'money',
            'title' => $this->titleFor($sliceId, $dataElementId),
            'why_this_number' => $why,
            'expected_amount' => $expectedAmount,
            'expected_count' => $expectedCount,
            'amount_expression' => $usesFloored ? 'floored' : 'raw',
            'workspace_path' => '/donations/dues',
            'workspace_query' => $workspaceQuery,
            'columns' => $this->columnsFor($sliceId),
            'supported_actions' => ['view_family', 'collect', 'open_workspace'],
        ]);

        if ($diagnostics !== []) {
            $context['diagnostics'] = $diagnostics;
        }

        return [
            'context' => $context,
            'summary' => [
                'family_count' => $totalFamilies,
                'record_count' => $totalFamilies,
                'due_count' => $filteredSummary['due_count'],
                'amount_total' => $filteredSummary['amount_total'],
            ],
            'filter_options' => [
                'bccs' => $bccOptions,
            ],
            'data' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalFamilies,
                'last_page' => (int) max(1, ceil($totalFamilies / $perPage)),
                'data' => $items,
            ],
        ];
    }

    /**
     * @return array{0: callable(Builder): Builder, 1: float, 2: int, 3: string, 4: array<string, string>}
     */
    private function resolveSliceMeta(
        string $sliceId,
        int $tenantId,
        string $businessDate,
        string $parishToday,
        float $graphOutstanding,
        float $graphOverdueAmount,
        int $graphOverdueFamilyCount,
        float $graphRemaining,
        array $dueSchedule
    ): array {
        return match ($sliceId) {
            'overdue' => [
                fn (Builder $q) => ContributionBalance::scopeOverdue($q, $businessDate),
                $graphOverdueAmount,
                $graphOverdueFamilyCount,
                'Families with mandatory church contributions past the due date. The amount uses the same overdue rules as the Financial Dashboard.',
                ['overdue_only' => '1'],
            ],
            'total_outstanding' => [
                fn (Builder $q) => ContributionBalance::scopeCollectable($q, $businessDate),
                $graphOutstanding,
                $this->countFamiliesInScope($tenantId, fn (Builder $q) => ContributionBalance::scopeCollectable($q, $businessDate), $businessDate),
                'All collectable mandatory contributions still outstanding for this parish as of the business date.',
                [],
            ],
            'remaining_collectable' => [
                fn (Builder $q) => ContributionBalance::scopeRemainingCollectable($q, $businessDate),
                $graphRemaining,
                $this->countFamiliesInScope($tenantId, fn (Builder $q) => ContributionBalance::scopeRemainingCollectable($q, $businessDate), $businessDate),
                'Collectable contributions that are not yet overdue — full remaining collectable scope.',
                [],
            ],
            'upcoming_14d' => [
                fn (Builder $q) => ContributionBalance::scopeDueNextDays($q, $parishToday, 14),
                (float) ($dueSchedule['next_14_days_amount'] ?? 0),
                (int) ($dueSchedule['next_14_days_family_count'] ?? 0),
                'Open mandatory dues due within the next 14 parish days from today (not overdue). Disjoint from the overdue slice.',
                [],
            ],
            'later_remaining' => [
                fn (Builder $q) => ContributionBalance::scopeDueAfterDays($q, $parishToday, 14),
                (float) ($dueSchedule['later_remaining_amount'] ?? 0),
                (int) ($dueSchedule['later_remaining_family_count'] ?? 0),
                'Collectable dues with due dates more than 14 parish days away.',
                [],
            ],
            default => throw new \InvalidArgumentException('Invalid slice.'),
        };
    }

    private function countFamiliesInScope(int $tenantId, callable $scopeFn, string $businessDate): int
    {
        $query = ContributionDue::forTenant($tenantId);
        $scopeFn($query);

        return (int) $query->distinct()->count('family_id');
    }

    private function buildFamilyAggregateSubquery(int $tenantId, callable $scopeFn, string $sumExpr): QueryBuilder
    {
        $query = ContributionDue::forTenant($tenantId);
        $scopeFn($query);

        return $query
            ->selectRaw("family_id, {$sumExpr} as outstanding_amount, COUNT(*) as due_count, MIN(due_date) as oldest_due_date")
            ->groupBy('family_id')
            ->toBase();
    }

    private function buildOuterFamilyQuery(QueryBuilder $innerQuery, int $tenantId): QueryBuilder
    {
        return DB::query()
            ->fromSub($innerQuery, 'family_agg')
            ->leftJoin('families', function ($join) use ($tenantId): void {
                $join->on('families.id', '=', 'family_agg.family_id')
                    ->where('families.tenant_id', '=', $tenantId);
            })
            ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->select([
                'family_agg.family_id',
                'family_agg.outstanding_amount',
                'family_agg.due_count',
                'family_agg.oldest_due_date',
                'families.family_name',
                'families.head_of_family',
                'families.family_code',
                'families.bcc_id',
                'bccs.name as bcc_name',
            ]);
    }

    private function applySearch(QueryBuilder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes(mb_substr($search, 0, 80), '%_\\').'%';
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query->where(function (QueryBuilder $inner) use ($term, $likeOp): void {
            $inner->where('families.family_name', $likeOp, $term)
                ->orWhere('families.head_of_family', $likeOp, $term)
                ->orWhere('families.family_code', $likeOp, $term);
        });
    }

    private function applyBccFilter(QueryBuilder $query, string $bccFilter, int $tenantId): void
    {
        if ($bccFilter === '') {
            return;
        }

        if ($bccFilter === 'unassigned') {
            $query->whereNull('families.bcc_id');

            return;
        }

        if (! $this->bccBelongsToTenant($bccFilter, $tenantId)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('families.bcc_id', $bccFilter);
    }

    private function bccBelongsToTenant(string $bccId, int $tenantId): bool
    {
        return DB::table('bccs')
            ->where('id', $bccId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function countFilteredFamilies(QueryBuilder $outerQuery): int
    {
        $sub = (clone $outerQuery)
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->select('family_agg.family_id');

        return (int) DB::query()->fromSub($sub, 'filtered_families')->count();
    }

    /**
     * @return array{due_count: int, amount_total: float}
     */
    private function buildFilteredSummary(QueryBuilder $outerQuery): array
    {
        $sub = (clone $outerQuery)
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->select([
                'family_agg.due_count',
                'family_agg.outstanding_amount',
            ]);

        $row = DB::query()
            ->fromSub($sub, 'filtered_rows')
            ->selectRaw('COALESCE(SUM(due_count), 0) as due_count')
            ->selectRaw('COALESCE(SUM(outstanding_amount), 0) as amount_total')
            ->first();

        return [
            'due_count' => (int) ($row->due_count ?? 0),
            'amount_total' => MoneyMath::toApiNumber($row->amount_total ?? 0),
        ];
    }

    private function resolveSortColumn(string $sort, string $sliceId): string
    {
        $allowed = [
            'outstanding_amount' => 'family_agg.outstanding_amount',
            'family_name' => 'families.family_name',
            'family_code' => 'families.family_code',
            'oldest_due_date' => 'family_agg.oldest_due_date',
            'due_count' => 'family_agg.due_count',
            'bcc_name' => 'bccs.name',
        ];

        if ($sliceId === 'overdue') {
            $allowed['days_overdue'] = 'family_agg.oldest_due_date';
        }

        if (! isset($allowed[$sort])) {
            throw new \InvalidArgumentException('Invalid sort field.');
        }

        if ($sort === 'days_overdue') {
            return 'family_agg.oldest_due_date';
        }

        return $allowed[$sort];
    }

    /**
     * @return array<int, array{id: string|null, name: string}>
     */
    private function buildBccFilterOptions(int $tenantId, callable $scopeFn, string $sumExpr): array
    {
        $innerQuery = $this->buildFamilyAggregateSubquery($tenantId, $scopeFn, $sumExpr);
        $rows = DB::query()
            ->fromSub($innerQuery, 'family_agg')
            ->leftJoin('families', function ($join) use ($tenantId): void {
                $join->on('families.id', '=', 'family_agg.family_id')
                    ->where('families.tenant_id', '=', $tenantId);
            })
            ->leftJoin('bccs', 'bccs.id', '=', 'families.bcc_id')
            ->selectRaw('families.bcc_id as bcc_id, COALESCE(bccs.name, ?) as bcc_name', ['Unassigned Area'])
            ->distinct()
            ->orderBy('bcc_name')
            ->get();

        $options = [];
        $hasUnassigned = false;
        foreach ($rows as $row) {
            if ($row->bcc_id === null) {
                $hasUnassigned = true;

                continue;
            }
            $options[] = ['id' => (string) $row->bcc_id, 'name' => (string) $row->bcc_name];
        }

        if ($hasUnassigned) {
            array_unshift($options, ['id' => 'unassigned', 'name' => 'Unassigned Area']);
        }

        return $options;
    }

    private function titleFor(string $sliceId, string $dataElementId): string
    {
        if ($dataElementId === 'overdue_family_count') {
            return 'Outstanding & overdue → Families overdue';
        }

        return match ($sliceId) {
            'overdue' => 'Due schedule → Overdue',
            'remaining_collectable' => 'Due schedule → Remaining collectable',
            'upcoming_14d' => 'Due schedule → Next 14 days',
            'later_remaining' => 'Due schedule → Later remaining',
            'total_outstanding' => 'Due schedule → Total outstanding',
            default => 'Due schedule',
        };
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function columnsFor(string $sliceId): array
    {
        $base = [
            ['key' => 'family', 'label' => 'Family'],
            ['key' => 'head', 'label' => 'Family head'],
            ['key' => 'bcc', 'label' => 'BCC / Community'],
            ['key' => 'outstanding_amount', 'label' => 'Outstanding'],
            ['key' => 'due_count', 'label' => 'Dues'],
            ['key' => 'oldest_due_date', 'label' => 'Oldest due date'],
        ];

        if ($sliceId === 'overdue') {
            $base[] = ['key' => 'days_overdue', 'label' => 'Days overdue'];
        }

        return $base;
    }
}
