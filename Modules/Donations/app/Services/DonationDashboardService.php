<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantHierarchyService;

class DonationDashboardService
{
    public function __construct(
        private readonly FinancialHealthService $healthService,
        private readonly TenantHierarchyService $hierarchyService,
        private readonly DashboardInsightsService $insightsService
    ) {}

    public function getSummary(int $tenantId): array
    {
        $base = $this->buildBaseTotals($tenantId);
        $families = $this->buildFamilyMetrics($tenantId);
        $periodCollections = $this->buildPeriodCollections($tenantId);
        $trend = $this->buildCollectionTrend(
            $tenantId,
            (float) ($periodCollections['current_month_collected'] ?? 0)
        );
        $attentionList = $this->buildAttentionList($tenantId);
        $recentActivity = $this->buildRecentActivity($tenantId);
        $projectSummaries = $this->buildProjectSummaries($tenantId);

        $previousMonth = $periodCollections['previous_month_collected'];
        $currentMonth = $periodCollections['current_month_collected'];
        $growthPct = $previousMonth > 0
            ? round((($currentMonth - $previousMonth) / $previousMonth) * 100, 1)
            : ($currentMonth > 0 ? 100.0 : 0.0);

        $outstanding = (float) $base['totals']['pending_dues'];
        $overdueAmount = $attentionList['total_overdue_amount'];
        $overdueRatioPct = $outstanding > 0 ? min(100, round(($overdueAmount / $outstanding) * 100, 1)) : 0.0;

        $avgProjectPct = collect($projectSummaries)->avg('funding_percentage') ?? 0;

        $monthStart = (string) ($periodCollections['period']['month_start'] ?? DonationBusinessDate::monthStart($tenantId));
        $monthEnd = (string) ($periodCollections['period']['month_end'] ?? DonationBusinessDate::monthEnd($tenantId));

        $paymentCount = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->count();

        $averageContribution = $paymentCount > 0
            ? round($currentMonth / $paymentCount, 2)
            : 0.0;

        $planCompliance = $this->buildPlanCompliancePct($tenantId);
        $collectionPerformancePct = $this->buildCollectionPerformancePct($currentMonth, $previousMonth, $planCompliance);
        $collectionChart = $this->buildCollectionPerformanceChart($tenantId, $trend);

        $financialHealth = $this->healthService->buildChurchScore([
            'participation_rate' => $families['participation_rate'],
            'overdue_ratio_pct' => $overdueRatioPct,
            'project_momentum_pct' => (float) $avgProjectPct,
            'collection_growth_pct' => $growthPct,
            'overdue_family_count' => $attentionList['count'],
        ]);

        $healthScores = [
            'financial' => $financialHealth,
            'collection_performance' => [
                'score' => $collectionPerformancePct,
                'label' => $this->scoreLabel($collectionPerformancePct),
                'status' => $this->scoreStatus($collectionPerformancePct),
            ],
            'family_engagement' => [
                'score' => (float) $families['participation_rate'],
                'label' => $this->scoreLabel((float) $families['participation_rate']),
                'status' => $this->scoreStatus((float) $families['participation_rate']),
            ],
            'project_funding' => [
                'score' => round((float) $avgProjectPct, 1),
                'label' => $this->scoreLabel((float) $avgProjectPct),
                'status' => $this->scoreStatus((float) $avgProjectPct),
            ],
        ];

        $kpis = [
            'average_contribution' => $averageContribution,
            'collection_growth_pct' => $growthPct,
            'plan_compliance_pct' => $planCompliance,
            'contributing_families_delta' => (int) ($families['contributing_families_delta'] ?? 0),
            'previous_month_collected' => $previousMonth,
        ];

        $payload = array_merge($base, [
            'financial_health' => $financialHealth,
            'health_scores' => $healthScores,
            'kpis' => $kpis,
            'collection_performance_chart' => $collectionChart,
            'families' => $families,
            'period_collections' => $periodCollections,
            'collection_trend' => $trend,
            'families_requiring_attention' => $attentionList['families'],
            'attention_summary' => [
                'count' => $attentionList['count'],
                'total_overdue_amount' => $attentionList['total_overdue_amount'],
            ],
            'recent_activity' => $recentActivity,
            'active_project_summaries' => $projectSummaries,
            'tenant_context' => $this->buildTenantContext($tenantId),
        ]);

        $payload['proactive_insights'] = $this->insightsService->buildProactiveInsights($payload);

        return $payload;
    }

    private function scoreLabel(float $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent',
            $score >= 60 => 'Good',
            $score >= 40 => 'Fair',
            default => 'Needs Attention',
        };
    }

    private function scoreStatus(float $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 50 => 'attention',
            default => 'risk',
        };
    }

    private function buildCollectionPerformancePct(float $currentMonth, float $previousMonth, float $planCompliance): float
    {
        $growthScore = $previousMonth > 0
            ? min(100, max(0, 50 + ((($currentMonth - $previousMonth) / $previousMonth) * 50)))
            : ($currentMonth > 0 ? 85.0 : 40.0);

        return round(($growthScore * 0.6) + ($planCompliance * 0.4), 1);
    }

    private function buildPlanCompliancePct(int $tenantId): float
    {
        $monthStart = DonationBusinessDate::monthStart($tenantId);
        $monthEnd = DonationBusinessDate::monthEnd($tenantId);

        $assessed = (float) ContributionDue::forTenant($tenantId)
            ->whereBetween('due_date', [$monthStart, $monthEnd])
            ->sum('amount_due');

        if ($assessed <= 0) {
            return 100.0;
        }

        $collected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->sum('amount');

        return round(min(100, ($collected / $assessed) * 100), 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trend
     * @return array<string, mixed>
     */
    private function buildCollectionPerformanceChart(int $tenantId, array $trend): array
    {
        $collectedPoints = [];
        $outstandingPoints = [];
        $targetPoints = [];

        $periodKeys = array_map(static fn (array $row): string => (string) $row['period'], $trend);
        $monthlyTargets = $this->buildMonthlyDueTargets($tenantId, $periodKeys);

        foreach ($trend as $row) {
            $period = (string) $row['period'];
            $collected = (float) $row['collected'];
            $target = (float) ($monthlyTargets[$period] ?? 0);

            if ($target <= 0) {
                $target = max($collected, (float) ($row['collected'] ?? 0));
            }

            $collectedPoints[] = ['period' => $period, 'label' => $row['label'], 'value' => round($collected, 2)];
            $periodOutstanding = max(0, round($target - $collected, 2));
            $outstandingPoints[] = ['period' => $period, 'label' => $row['label'], 'value' => $periodOutstanding];
            $targetPoints[] = ['period' => $period, 'label' => $row['label'], 'value' => round($target, 2)];
        }

        return [
            'granularity' => 'month',
            'series' => [
                ['key' => 'collected', 'label' => 'Collected', 'points' => $collectedPoints],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'points' => $outstandingPoints],
                ['key' => 'target', 'label' => 'Target', 'points' => $targetPoints],
            ],
        ];
    }

    /**
     * @param  list<string>  $periodKeys  YYYY-MM values
     * @return array<string, float>
     */
    private function buildMonthlyDueTargets(int $tenantId, array $periodKeys): array
    {
        if ($periodKeys === []) {
            return [];
        }

        $monthExpr = $this->sqlYearMonthExpression('due_date');
        $start = Carbon::createFromFormat('Y-m', min($periodKeys))->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', max($periodKeys))->endOfMonth()->toDateString();

        return ContributionDue::forTenant($tenantId)
            ->whereBetween('due_date', [$start, $end])
            ->selectRaw("{$monthExpr} as period, COALESCE(SUM(amount_due), 0) as target")
            ->groupBy('period')
            ->pluck('target', 'period')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    private function sqlYearMonthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR({$column}, 'YYYY-MM')"
            : "strftime('%Y-%m', {$column})";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildTenantContext(int $tenantId): ?array
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return null;
        }

        $tier = $this->hierarchyService->supportsHierarchy()
            ? ($tenant->tenant_tier ?? 'parish')
            : 'parish';

        return [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'tier' => $tier,
            'hierarchy_path' => $this->hierarchyService->supportsHierarchy()
                ? $tenant->hierarchy_path
                : (string) $tenant->id,
            'parent_tenant_id' => $this->hierarchyService->supportsHierarchy()
                ? $tenant->parent_tenant_id
                : null,
            'currency_code' => ($this->hierarchyService->supportsHierarchy()
                ? $tenant->currency_code
                : null) ?? 'INR',
            'visibility_scope' => $this->resolveVisibilityScope($tier),
            'supports_child_rollup' => $this->hierarchyService->supportsHierarchy()
                && in_array($tier, ['diocese', 'parish'], true),
        ];
    }

    private function resolveVisibilityScope(string $tier): string
    {
        return match ($tier) {
            'branch', 'mission' => 'branch_only',
            'diocese', 'organization' => 'diocese_and_children',
            'parish' => 'parish_and_branches',
            default => 'tenant_only',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaseTotals(int $tenantId): array
    {
        $succeededByMethod = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->selectRaw(
                "method, COALESCE(SUM(amount), 0) as total, COALESCE(SUM(CASE WHEN source_type IN ('voluntary', 'recurring_schedule') THEN amount ELSE 0 END), 0) as voluntary"
            )
            ->groupBy('method')
            ->get();

        $totalCollected = '0.00';
        $voluntaryCollected = '0.00';
        foreach ($succeededByMethod as $row) {
            $totalCollected = MoneyMath::add($totalCollected, $row->total);
            $voluntaryCollected = MoneyMath::add($voluntaryCollected, $row->voluntary);
        }

        $totalRefunded = MoneyMath::normalize(
            DonationRefund::forTenant($tenantId)->where('status', 'completed')->sum('amount')
        );

        $businessDate = \Modules\Donations\Support\DonationBusinessDate::today($tenantId);
        $pendingDues = ContributionBalance::sumCollectable(
            ContributionDue::forTenant($tenantId),
            $businessDate
        );

        $activeProjects = DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->count();

        $voluntaryEntries = Donation::forTenant($tenantId)->count();
        $anonymousDonations = Donation::forTenant($tenantId)->where('is_anonymous', true)->count();
        $activeRecurring = RecurringDonationSchedule::forTenant($tenantId)->where('status', 'active')->count();
        $pledgedOutstanding = MoneyMath::normalize(
            Donation::forTenant($tenantId)
                ->whereIn('status', ['pledged', 'partially_paid'])
                ->selectRaw('COALESCE(SUM(CASE WHEN pledged_amount > collected_amount THEN pledged_amount - collected_amount ELSE 0 END), 0) as outstanding')
                ->value('outstanding')
        );

        $collectionsByMethod = $succeededByMethod
            ->pluck('total', 'method')
            ->map(fn ($sum) => MoneyMath::toApiNumber($sum));

        $voluntaryByCategory = Donation::query()
            ->from('donations')
            ->leftJoin('donation_categories', 'donation_categories.id', '=', 'donations.donation_category_id')
            ->where('donations.tenant_id', $tenantId)
            ->where('donations.collected_amount', '>', 0)
            ->selectRaw('donations.donation_category_id as category_id, COALESCE(donation_categories.name, ?) as category_name, COALESCE(SUM(donations.collected_amount), 0) as collected', ['Uncategorized'])
            ->groupBy('donations.donation_category_id', 'donation_categories.name')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'category_name' => (string) $row->category_name,
                'collected' => MoneyMath::toApiNumber($row->collected),
            ])
            ->values()
            ->all();

        return [
            'totals' => [
                'collected' => MoneyMath::toApiNumber($totalCollected),
                'refunded' => MoneyMath::toApiNumber($totalRefunded),
                'net' => MoneyMath::toApiNumber(MoneyMath::subtract($totalCollected, $totalRefunded)),
                'pending_dues' => MoneyMath::toApiNumber($pendingDues),
                'active_projects' => $activeProjects,
                'voluntary_collected' => MoneyMath::toApiNumber($voluntaryCollected),
                'voluntary_pledged_outstanding' => MoneyMath::toApiNumber($pledgedOutstanding),
                'voluntary_entries' => $voluntaryEntries,
                'anonymous_donations' => $anonymousDonations,
                'active_recurring_schedules' => $activeRecurring,
            ],
            'collections_by_method' => $collectionsByMethod,
            'voluntary_by_category' => $voluntaryByCategory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFamilyMetrics(int $tenantId): array
    {
        $familyCounts = Family::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active', ['active'])
            ->first();
        $totalFamilies = (int) ($familyCounts->total ?? 0);
        $activeFamilies = (int) ($familyCounts->active ?? 0);
        $activeFamilyIds = Family::query()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');

        $windowStart = DonationBusinessDate::subDays($tenantId, 89);
        $previousWindowStart = DonationBusinessDate::subDays($tenantId, 179);
        $previousWindowEnd = DonationBusinessDate::subDays($tenantId, 90);

        $participatingFamilies = $this->countDistinctPayingFamilies(
            $tenantId,
            clone $activeFamilyIds,
            $windowStart
        );
        $previousParticipatingFamilies = $this->countDistinctPayingFamilies(
            $tenantId,
            clone $activeFamilyIds,
            $previousWindowStart,
            $previousWindowEnd
        );

        $participationRate = $activeFamilies > 0
            ? min(100.0, round(($participatingFamilies / $activeFamilies) * 100, 1))
            : 0.0;

        return [
            'total' => $totalFamilies,
            'active' => $activeFamilies,
            'participating_last_90_days' => $participatingFamilies,
            'participation_rate' => $participationRate,
            'contributing_families_delta' => $participatingFamilies - $previousParticipatingFamilies,
        ];
    }

    /**
     * Distinct active families with a succeeded payment in the window.
     * Two payments for one family count as one family.
     */
    private function countDistinctPayingFamilies(
        int $tenantId,
        Builder $activeFamilyIds,
        string $start,
        ?string $end = null
    ): int {
        $query = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $activeFamilyIds);

        if ($end === null) {
            $query->whereDate('payment_date', '>=', $start);
        } else {
            $query->whereBetween('payment_date', [$start, $end]);
        }

        return (int) $query->selectRaw('COUNT(DISTINCT family_id) as aggregate')->value('aggregate');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodCollections(int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $currentMonthStart = DonationBusinessDate::monthStart($tenantId);
        $currentMonthEnd = DonationBusinessDate::monthEnd($tenantId);
        $currentMonth = Carbon::parse($today, $timezone);
        $previousMonthStart = $currentMonth->copy()->subMonth()->startOfMonth()->toDateString();
        $previousMonthEnd = $currentMonth->copy()->subMonth()->endOfMonth()->toDateString();
        $fyBounds = DonationBusinessDate::currentFinancialYearBounds($tenantId, $today);
        $fyStart = $fyBounds['start'];

        $currentMonthCollected = $this->sumSucceededPayments($tenantId, $currentMonthStart, $currentMonthEnd);
        $previousMonthCollected = $this->sumSucceededPayments($tenantId, $previousMonthStart, $previousMonthEnd);
        $annualCollected = $this->sumSucceededPayments($tenantId, $fyStart, $fyBounds['end']);

        $fyStartCarbon = Carbon::parse($fyStart, $timezone);

        return [
            'financial_year' => sprintf('%s-%s', $fyStartCarbon->format('Y'), Carbon::parse($fyBounds['end'], $timezone)->format('Y')),
            'current_month_collected' => MoneyMath::toApiNumber($currentMonthCollected),
            'previous_month_collected' => MoneyMath::toApiNumber($previousMonthCollected),
            'annual_collected' => MoneyMath::toApiNumber($annualCollected),
            'collections_by_method_this_month' => $this->buildCollectionsByMethodForRange($tenantId, $currentMonthStart, $currentMonthEnd),
            'period' => [
                'month_start' => $currentMonthStart,
                'month_end' => $currentMonthEnd,
                'timezone' => $timezone,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCollectionTrend(int $tenantId, float $currentMonthCollected): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $currentMonthEnd = DonationBusinessDate::monthEnd($tenantId);
        $start = Carbon::now($timezone)->subMonths(11)->startOfMonth();
        $startDate = $start->toDateString();
        $currentPeriodKey = Carbon::parse(DonationBusinessDate::today($tenantId), $timezone)->format('Y-m');
        $monthExpr = $this->sqlYearMonthExpression('payment_date');

        $rows = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$startDate, $currentMonthEnd])
            ->selectRaw("{$monthExpr} as period, COALESCE(SUM(amount), 0) as collected")
            ->groupByRaw($monthExpr)
            ->get();

        $collectedByMonth = [];
        foreach ($rows as $row) {
            $key = trim((string) $row->period);
            if ($key === '') {
                continue;
            }
            $collectedByMonth[$key] = MoneyMath::toApiNumber($row->collected);
        }

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'collected' => $key === $currentPeriodKey
                    ? $currentMonthCollected
                    : (float) ($collectedByMonth[$key] ?? 0),
            ];
        }

        return $buckets;
    }

    private function sumSucceededPayments(int $tenantId, string $startDate, string $endDate): string
    {
        return MoneyMath::normalize(
            DonationPayment::forTenant($tenantId)
                ->where('status', 'succeeded')
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->sum('amount')
        );
    }

    /**
     * @return array{families: array<int, array<string, mixed>>, count: int, total_overdue_amount: float}
     */
    private function buildAttentionList(int $tenantId): array
    {
        $businessDate = DonationBusinessDate::today($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $outstandingExpr = $this->outstandingSumExpression();

        $overdueQuery = ContributionDue::forTenant($tenantId);
        ContributionBalance::scopeOverdue($overdueQuery, $businessDate);

        $familyAggregates = $overdueQuery
            ->selectRaw("family_id, {$outstandingExpr} as overdue_amount, COUNT(*) as overdue_count, MIN(due_date) as oldest_due_date")
            ->groupBy('family_id')
            ->orderByDesc('overdue_amount')
            ->get();

        $topAggregates = $familyAggregates->take(10);
        $topFamilyIds = $topAggregates->pluck('family_id')->filter()->values()->all();

        $oldestDueByFamily = [];
        if ($topFamilyIds !== []) {
            $detailQuery = ContributionDue::forTenant($tenantId)
                ->whereIn('family_id', $topFamilyIds);
            ContributionBalance::scopeOverdue($detailQuery, $businessDate);
            $detailQuery
                ->with(['family:id,family_name,family_code', 'plan:id,name'])
                ->orderBy('due_date')
                ->get()
                ->groupBy('family_id')
                ->each(function ($dues, $familyId) use (&$oldestDueByFamily): void {
                    $oldestDueByFamily[$familyId] = $dues->first();
                });
        }

        $businessDay = Carbon::parse($businessDate, $timezone)->startOfDay();

        $families = $topAggregates->map(function ($aggregate) use ($oldestDueByFamily, $businessDay) {
            $familyId = $aggregate->family_id;
            $firstDue = $oldestDueByFamily[$familyId] ?? null;
            $oldestDueDate = $aggregate->oldest_due_date;
            $daysOverdue = $oldestDueDate
                ? (int) Carbon::parse($oldestDueDate)->diffInDays($businessDay)
                : 0;

            return [
                'family_id' => $familyId,
                'family_name' => $firstDue?->family?->family_name,
                'family_code' => $firstDue?->family?->family_code,
                'overdue_amount' => MoneyMath::toApiNumber($aggregate->overdue_amount),
                'overdue_count' => (int) $aggregate->overdue_count,
                'days_overdue' => $daysOverdue,
                'oldest_due_label' => $firstDue?->plan?->name ?? $firstDue?->period_label,
            ];
        })->values()->all();

        $totalOverdue = '0';
        foreach ($familyAggregates as $aggregate) {
            $totalOverdue = MoneyMath::add($totalOverdue, $aggregate->overdue_amount ?? 0);
        }

        return [
            'families' => $families,
            'count' => $familyAggregates->count(),
            'total_overdue_amount' => MoneyMath::toApiNumber($totalOverdue),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentActivity(int $tenantId): array
    {
        return DonationPayment::forTenant($tenantId)
            ->with(['family:id,family_name,family_code', 'receipt'])
            ->orderByDesc('payment_date')
            ->limit(10)
            ->get()
            ->map(fn (DonationPayment $payment) => [
                'id' => $payment->id,
                'type' => 'payment',
                'family_id' => $payment->family_id,
                'family_name' => $payment->family?->family_name,
                'family_code' => $payment->family?->family_code,
                'payer_name' => $payment->is_anonymous ? 'Anonymous' : $payment->payer_name,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'date' => $payment->payment_date?->toDateString(),
                'receipt_number' => $payment->receipt?->receipt_number,
                'status' => $payment->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectSummaries(int $tenantId): array
    {
        return DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(function (DonationProject $project): array {
                $target = max((float) $project->target_amount, 1);
                $raised = (float) $project->raised_amount;

                return [
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'target_amount' => round($target, 2),
                    'collected' => round($raised, 2),
                    'remaining' => round(max(0, $target - $raised), 2),
                    'funding_percentage' => round(min(100, ($raised / $target) * 100), 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFamilyVoluntarySummary(int $tenantId, string $familyId): array
    {
        $baseQuery = Donation::forTenant($tenantId)->where('family_id', $familyId);

        $lifetimeCollected = (float) (clone $baseQuery)->sum('collected_amount');
        $pledgedOutstanding = (float) (clone $baseQuery)
            ->whereIn('status', ['pledged', 'partially_paid'])
            ->get()
            ->sum(fn (Donation $donation) => max((float) $donation->pledged_amount - (float) $donation->collected_amount, 0));

        $recentDonations = (clone $baseQuery)
            ->with(['category:id,name', 'donor:id,name'])
            ->orderByDesc('received_at')
            ->limit(20)
            ->get()
            ->map(fn (Donation $donation) => [
                'id' => $donation->id,
                'title' => $donation->title,
                'category' => $donation->category?->name,
                'donor' => $donation->is_anonymous ? 'Anonymous' : $donation->donor?->name,
                'status' => $donation->status,
                'pledged_amount' => (float) $donation->pledged_amount,
                'collected_amount' => (float) $donation->collected_amount,
                'received_at' => $donation->received_at?->toDateString(),
                'is_anonymous' => (bool) $donation->is_anonymous,
            ])->values()->all();

        return [
            'totals' => [
                'collected' => round($lifetimeCollected, 2),
                'pledged_outstanding' => round($pledgedOutstanding, 2),
                'entries' => (int) (clone $baseQuery)->count(),
            ],
            'recent_donations' => $recentDonations,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function buildCollectionsByMethodForRange(int $tenantId, string $startDate, string $endDate): array
    {
        return DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($sum) => MoneyMath::toApiNumber($sum))
            ->all();
    }

    private function outstandingSumExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'COALESCE(SUM(GREATEST(amount_due - amount_paid, 0)), 0)'
            : 'COALESCE(SUM(CASE WHEN amount_due > amount_paid THEN amount_due - amount_paid ELSE 0 END), 0)';
    }
}
