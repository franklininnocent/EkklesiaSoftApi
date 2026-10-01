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
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Modules\Tenants\Services\TenantHierarchyService;

class DonationDashboardService
{
    public function __construct(
        private readonly FinancialHealthService $healthService,
        private readonly TenantHierarchyService $hierarchyService,
        private readonly DashboardInsightsService $insightsService,
        private readonly ExecutiveReportMetricsService $executiveMetrics,
        private readonly DonationDashboardSnapshotBuilder $snapshotBuilder
    ) {}

    public function getSummary(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $base = $this->buildBaseTotals($tenantId, $range, $bccFilter, $projectFilter);
        $families = $this->buildFamilyMetrics($tenantId, $range, $bccFilter, $projectFilter);
        $periodCollections = $this->buildPeriodCollections($tenantId, $range, $bccFilter, $projectFilter);
        $rangeCollected = (float) ($periodCollections['current_month_collected'] ?? 0);
        $trend = $this->buildCollectionTrend($tenantId, $rangeCollected, $range, $bccFilter, $projectFilter);
        $attentionList = $this->buildAttentionList($tenantId, $range, $bccFilter, $projectFilter);
        $recentActivity = $this->buildRecentActivity($tenantId, $bccFilter, $projectFilter);
        $projectSummaries = $this->buildProjectSummaries($tenantId, $projectFilter);

        $previousMonth = $periodCollections['previous_month_collected'];
        $currentMonth = $periodCollections['current_month_collected'];
        if ($range !== null) {
            $comparisonRange = [
                'start' => $range->comparisonStart,
                'end' => $range->comparisonEnd,
            ];
        } else {
            $comparisonRange = $this->executiveMetrics->comparablePreviousMonthCollectionRange($tenantId);
        }
        $comparisonCollected = $this->executiveMetrics->sumSucceededPayments(
            $tenantId,
            $comparisonRange['start'],
            $comparisonRange['end'],
            $bccFilter,
            $projectFilter
        );
        $growthAnalysis = $this->executiveMetrics->growthHealthFromCollections((float) $currentMonth, $comparisonCollected);
        $growthPct = (float) $growthAnalysis['growth_pct'];

        $outstanding = (float) $base['totals']['pending_dues'];
        $overdueAmount = $attentionList['total_overdue_amount'];
        $overdueRatioPct = $outstanding > 0 ? min(100, round(($overdueAmount / $outstanding) * 100, 1)) : 0.0;

        $avgProjectPct = $this->executiveMetrics->averageProjectFundingPct($projectSummaries);
        $projectApplicable = $projectSummaries !== [];

        $monthStart = $range?->dateFrom
            ?? (string) ($periodCollections['period']['month_start'] ?? DonationBusinessDate::monthStart($tenantId));
        $monthEnd = $range?->collectionEnd
            ?? (string) ($periodCollections['period']['month_end'] ?? DonationBusinessDate::monthEnd($tenantId));

        if ($projectFilter->isActive) {
            $paymentCount = $this->executiveMetrics->countDistinctPaymentsWithProjectAllocationsInRange(
                $tenantId,
                $monthStart,
                $monthEnd,
                $bccFilter,
                $projectFilter
            );
        } else {
            $paymentCountQuery = DonationPayment::forTenant($tenantId)
                ->where('status', 'succeeded')
                ->whereBetween('payment_date', [$monthStart, $monthEnd]);
            $bccFilter->applyToDonationPaymentQuery($paymentCountQuery, $tenantId);
            $paymentCount = (int) $paymentCountQuery->count();
        }

        $averageContribution = $paymentCount > 0
            ? round($currentMonth / $paymentCount, 2)
            : 0.0;

        $planCompliance = $this->buildPlanCompliancePct($tenantId, $range, $bccFilter, $projectFilter);
        $collectionPerformancePct = $this->buildCollectionPerformancePct($currentMonth, $previousMonth, $planCompliance);
        $collectionChart = $this->buildCollectionPerformanceChart($tenantId, $trend);

        $financialHealth = $this->healthService->buildChurchScore([
            'participation_rate' => $families['participation_rate'],
            'overdue_ratio_pct' => $overdueRatioPct,
            'project_momentum_pct' => (float) $avgProjectPct,
            'project_applicable' => $projectApplicable,
            'collection_growth_pct' => $growthPct,
            'growth_health_score' => (float) $growthAnalysis['growth_health_score'],
            'overdue_family_count' => $attentionList['count'],
            'current_month_collected' => (float) $currentMonth,
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
            'comparison_available' => (bool) ($growthAnalysis['comparison_available'] ?? false),
            'tiny_base' => (bool) ($growthAnalysis['tiny_base'] ?? false),
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
        $payload['snapshot'] = $this->snapshotBuilder->build(
            $tenantId,
            $periodCollections,
            $families,
            $growthAnalysis,
            $attentionList,
            $recentActivity,
            $outstanding,
            (int) ($base['totals']['active_projects'] ?? 0),
            $range,
            $bccFilter,
            $projectFilter
        );
        $payload['preset_windows'] = DashboardDateRange::presetWindowsForTenant($tenantId);
        if ($range !== null) {
            $payload['applied_range'] = $range->appliedRangePayload();
            $payload['metric_basis'] = $range->metricBasisPayload();
        }
        $appliedBcc = $bccFilter->appliedPayload();
        if ($appliedBcc !== null) {
            $payload['applied_bcc'] = $appliedBcc;
        }
        $appliedProject = $projectFilter->appliedPayload();
        if ($appliedProject !== null) {
            $payload['applied_project'] = $appliedProject;
        }

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

    private function buildPlanCompliancePct(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $monthStart = $range?->dateFrom ?? DonationBusinessDate::monthStart($tenantId);
        $monthEnd = $range !== null
            ? $range->dateTo
            : DonationBusinessDate::monthEnd($tenantId);
        $businessDate = $range?->asOf ?? DonationBusinessDate::today($tenantId);
        $collectionEnd = $monthEnd > $businessDate ? $businessDate : $monthEnd;

        if ($projectFilter->isActive) {
            $assessedQuery = ProjectInstallmentDue::forTenant($tenantId)
                ->whereBetween('due_date', [$monthStart, $monthEnd]);
            $projectFilter->applyToProjectInstallmentDueQuery($assessedQuery, $tenantId);
            $this->executiveMetrics->applyDashboardFamilyScope($assessedQuery, $tenantId, $bccFilter, $projectFilter);
            $assessed = (float) $assessedQuery->sum('amount_due');
            if ($assessed <= 0) {
                return 100.0;
            }
            $collected = $this->executiveMetrics->sumProjectFundingAllocations(
                $tenantId,
                $monthStart,
                $collectionEnd,
                $bccFilter,
                $projectFilter
            );

            return round(min(100, ($collected / $assessed) * 100), 1);
        }

        $assessedQuery = ContributionDue::forTenant($tenantId)
            ->whereBetween('due_date', [$monthStart, $monthEnd]);
        $bccFilter->applyToContributionDueQuery($assessedQuery, $tenantId);
        $assessed = (float) $assessedQuery->sum('amount_due');

        if ($assessed <= 0) {
            return 100.0;
        }

        $collectedQuery = $this->scopeSucceededPaymentsThroughBusinessDate(
            DonationPayment::forTenant($tenantId),
            $businessDate
        )
            ->whereDate('payment_date', '>=', $monthStart)
            ->whereDate('payment_date', '<=', $collectionEnd);
        $bccFilter->applyToDonationPaymentQuery($collectedQuery, $tenantId);
        $collected = (float) $collectedQuery->sum('amount');

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
            'currency_code' => app(ChurchCurrencyResolver::class)
                ->currencyCodeForTenantId($tenantId),
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
    private function buildBaseTotals(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = DonationBusinessDate::today($tenantId);
        $asOf = $range?->asOf ?? $businessDate;

        if ($projectFilter->isActive) {
            $totalCollected = MoneyMath::normalize(
                $this->executiveMetrics->sumProjectFundingAllocations(
                    $tenantId,
                    '2000-01-01',
                    $businessDate,
                    $bccFilter,
                    $projectFilter
                )
            );
            $voluntaryCollected = '0.00';
            $collectionsByMethod = $this->executiveMetrics->sumProjectFundingAllocationsByMethod(
                $tenantId,
                '2000-01-01',
                $businessDate,
                $bccFilter,
                $projectFilter
            );
            $succeededByMethod = collect();
        } else {
            $succeededByMethodQuery = $this->scopeSucceededPaymentsThroughBusinessDate(
                DonationPayment::forTenant($tenantId),
                $businessDate
            );
            $bccFilter->applyToDonationPaymentQuery($succeededByMethodQuery, $tenantId);
            $succeededByMethod = $succeededByMethodQuery
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

            $collectionsByMethod = $succeededByMethod
                ->pluck('total', 'method')
                ->map(fn ($sum) => MoneyMath::toApiNumber($sum));
        }

        $totalRefunded = MoneyMath::normalize(
            DonationRefund::forTenant($tenantId)->where('status', 'completed')->sum('amount')
        );
        $refundsAgainstSucceededPayments = MoneyMath::normalize(
            DonationRefund::query()
                ->from('donation_refunds')
                ->join('donation_payments', 'donation_payments.id', '=', 'donation_refunds.payment_id')
                ->where('donation_refunds.tenant_id', $tenantId)
                ->where('donation_refunds.status', 'completed')
                ->where('donation_payments.status', 'succeeded')
                ->sum('donation_refunds.amount')
        );

        $pendingDues = $this->executiveMetrics->sumPendingDuesCollectable($tenantId, $asOf, $bccFilter, $projectFilter);
        $pendingProjectInstallments = $this->sumProjectInstallmentOutstanding($tenantId);
        $futureDatedPayments = $this->futureDatedSucceededPaymentStats($tenantId, $businessDate);

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
                'net' => MoneyMath::toApiNumber(MoneyMath::subtract($totalCollected, $refundsAgainstSucceededPayments)),
                'pending_dues' => MoneyMath::toApiNumber($pendingDues),
                'pending_project_installments' => MoneyMath::toApiNumber($pendingProjectInstallments),
                'future_dated_payments' => [
                    'count' => $futureDatedPayments['count'],
                    'amount' => MoneyMath::toApiNumber($futureDatedPayments['amount']),
                ],
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
    private function buildFamilyMetrics(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $familyCountsQuery = Family::query()->where('tenant_id', $tenantId);
        $bccFilter->applyToFamilyQuery($familyCountsQuery, $tenantId);
        $projectFilter->applyToFamilyQuery($familyCountsQuery, $tenantId);
        $familyCounts = (clone $familyCountsQuery)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active', ['active'])
            ->first();
        $totalFamilies = (int) ($familyCounts->total ?? 0);
        $activeFamilies = (int) ($familyCounts->active ?? 0);
        $activeFamilyIds = Family::query()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');
        $bccFilter->applyToFamilyQuery($activeFamilyIds, $tenantId);
        $projectFilter->applyToFamilyQuery($activeFamilyIds, $tenantId);

        if ($range !== null) {
            $previous = $range->priorEqualLengthWindow();
            $previousWindowStart = $previous['start'];
            $previousWindowEnd = $previous['end'];
            $participatingFamilies = $this->executiveMetrics->countDistinctParticipatingFamilies($tenantId, $range, $bccFilter, $projectFilter);
        } else {
            $previousWindowStart = DonationBusinessDate::subDays($tenantId, 179);
            $previousWindowEnd = DonationBusinessDate::subDays($tenantId, 90);
            $participatingFamilies = $this->executiveMetrics->countDistinctParticipatingFamilies($tenantId, null, $bccFilter, $projectFilter);
        }
        $previousParticipatingFamilies = $this->countDistinctPayingFamilies(
            $tenantId,
            clone $activeFamilyIds,
            $previousWindowStart,
            $previousWindowEnd,
            $bccFilter,
            $projectFilter
        );

        $participationRate = $activeFamilies <= 0
            ? 0.0
            : min(100.0, round(($participatingFamilies / $activeFamilies) * 100, 1));

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
        ?string $end = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): int {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $query = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $activeFamilyIds);
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);
        $projectFilter->applyToDonationPaymentQuery($query, $tenantId);

        if ($end === null) {
            $query->whereDate('payment_date', '>=', $start)
                ->whereDate('payment_date', '<=', DonationBusinessDate::today($tenantId));
        } else {
            $query->whereBetween('payment_date', [$start, $end]);
        }

        return (int) $query->selectRaw('COUNT(DISTINCT family_id) as aggregate')->value('aggregate');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodCollections(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $currentMonthStart = $range?->dateFrom ?? DonationBusinessDate::monthStart($tenantId);
        $currentMonthEnd = $range?->collectionEnd ?? $today;
        if ($range !== null) {
            $previousMonthStart = $range->comparisonStart;
            $previousMonthEnd = $range->comparisonEnd;
        } else {
            $previousRange = $this->executiveMetrics->previousMonthCollectionRange($tenantId);
            $previousMonthStart = $previousRange['start'];
            $previousMonthEnd = $previousRange['end'];
        }
        $fyPeriod = app(ChurchFinancialPeriodResolver::class)->currentFiscalYear($tenantId, $today);
        $fyStart = $fyPeriod->start;
        $fyBounds = ['start' => $fyPeriod->start, 'end' => $fyPeriod->end];

        $currentMonthCollected = $this->sumSucceededPayments($tenantId, $currentMonthStart, $currentMonthEnd, $bccFilter, $projectFilter);
        $previousMonthCollected = $this->sumSucceededPayments($tenantId, $previousMonthStart, $previousMonthEnd, $bccFilter, $projectFilter);
        $fyCollectionEnd = $fyBounds['end'] > $today ? $today : $fyBounds['end'];
        $annualCollected = $this->sumSucceededPayments($tenantId, $fyStart, $fyCollectionEnd, $bccFilter, $projectFilter);

        return [
            'financial_year' => $fyPeriod->label,
            'financial_year_key' => $fyPeriod->key,
            'financial_year_start' => $fyPeriod->start,
            'financial_year_end' => $fyPeriod->end,
            'current_month_collected' => MoneyMath::toApiNumber($currentMonthCollected),
            'previous_month_collected' => MoneyMath::toApiNumber($previousMonthCollected),
            'annual_collected' => MoneyMath::toApiNumber($annualCollected),
            'collections_by_method_this_month' => $this->buildCollectionsByMethodForRange($tenantId, $currentMonthStart, $currentMonthEnd, $bccFilter, $projectFilter),
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
    private function buildCollectionTrend(
        int $tenantId,
        float $currentMonthCollected,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $buckets = $this->executiveMetrics->trendMonthBuckets($tenantId, $range);
        if ($buckets === []) {
            return [];
        }
        $firstStart = $buckets[0]['start'];
        $lastEnd = $buckets[count($buckets) - 1]['end'];

        if ($projectFilter->isActive) {
            $collectedByMonth = $this->executiveMetrics->sumProjectFundingAllocationsByMonth(
                $tenantId,
                $firstStart,
                $lastEnd,
                $bccFilter,
                $projectFilter
            );
        } else {
            $monthExpr = $this->executiveMetrics->sqlYearMonthExpression('payment_date');
            $rowsQuery = DonationPayment::forTenant($tenantId)
                ->where('status', 'succeeded')
                ->whereBetween('payment_date', [$firstStart, $lastEnd]);
            $bccFilter->applyToDonationPaymentQuery($rowsQuery, $tenantId);
            $rows = $rowsQuery
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
        }

        $result = [];
        foreach ($buckets as $bucket) {
            $key = $bucket['period'];
            $result[] = [
                'period' => $key,
                'label' => $bucket['label'],
                'start' => $bucket['start'],
                'end' => $bucket['end'],
                'is_current' => $bucket['is_current'],
                'collected' => $this->resolveTrendBucketCollected(
                    $bucket,
                    $range,
                    $currentMonthCollected,
                    $collectedByMonth,
                    $key
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  array{period: string, label: string, start: string, end: string, is_current: bool}  $bucket
     * @param  array<string, float>  $collectedByMonth
     */
    private function resolveTrendBucketCollected(
        array $bucket,
        ?DashboardDateRange $range,
        float $currentMonthCollected,
        array $collectedByMonth,
        string $periodKey
    ): float {
        if ($bucket['is_current']) {
            if ($range === null) {
                return $currentMonthCollected;
            }
            if ($bucket['end'] === $range->collectionEnd) {
                return $currentMonthCollected;
            }
        }

        return (float) ($collectedByMonth[$periodKey] ?? 0);
    }

    private function sumSucceededPayments(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): string {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = DonationBusinessDate::today($tenantId);
        $effectiveEnd = $endDate > $businessDate ? $businessDate : $endDate;

        $query = $this->scopeSucceededPaymentsThroughBusinessDate(
            DonationPayment::forTenant($tenantId),
            $businessDate
        )
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $effectiveEnd);
        if ($projectFilter->isActive) {
            return MoneyMath::normalize(
                $this->executiveMetrics->sumSucceededPayments($tenantId, $startDate, $effectiveEnd, $bccFilter, $projectFilter)
            );
        }
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);

        return MoneyMath::normalize($query->sum('amount'));
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function futureDatedSucceededPaymentStats(int $tenantId, string $businessDate): array
    {
        $row = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>', $businessDate)
            ->selectRaw('COUNT(*) as payment_count, COALESCE(SUM(amount), 0) as amount_total')
            ->first();

        return [
            'count' => (int) ($row->payment_count ?? 0),
            'amount' => MoneyMath::normalize($row->amount_total ?? 0),
        ];
    }

    private function sumProjectInstallmentOutstanding(int $tenantId): string
    {
        return MoneyMath::normalize(
            ProjectInstallmentDue::forTenant($tenantId)
                ->whereIn('status', ['pending', 'partially_paid'])
                ->selectRaw(ContributionBalance::flooredOutstandingSumSqlExpression().' as outstanding')
                ->value('outstanding')
        );
    }

    private function scopeSucceededPaymentsThroughBusinessDate(Builder $query, string $businessDate): Builder
    {
        return $query
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '<=', $businessDate);
    }

    /**
     * @return array{families: array<int, array<string, mixed>>, count: int, total_overdue_amount: float}
     */
    private function buildAttentionList(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = $range?->asOf ?? DonationBusinessDate::today($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $outstandingExpr = $this->outstandingSumExpression();

        $overdueTotals = $this->executiveMetrics->overdueAttentionTotals($tenantId, $businessDate, $bccFilter, $projectFilter);
        $familyCount = $overdueTotals['count'];
        $totalOverdueAmount = $overdueTotals['total_overdue_amount'];

        if ($projectFilter->isActive) {
            $overdueQuery = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($overdueQuery, $tenantId);
            $this->executiveMetrics->applyDashboardFamilyScope($overdueQuery, $tenantId, $bccFilter, $projectFilter);
            ContributionBalance::scopeOverdue($overdueQuery, $businessDate);
        } else {
            $overdueQuery = ContributionDue::forTenant($tenantId);
            $bccFilter->applyToContributionDueQuery($overdueQuery, $tenantId);
            ContributionBalance::scopeOverdue($overdueQuery, $businessDate);
        }

        $topAggregates = (clone $overdueQuery)
            ->selectRaw("family_id, {$outstandingExpr} as overdue_amount, COUNT(*) as overdue_count, MIN(due_date) as oldest_due_date")
            ->groupBy('family_id')
            ->orderByDesc('overdue_amount')
            ->limit(10)
            ->get();
        $topFamilyIds = $topAggregates->pluck('family_id')->filter()->values()->all();

        $oldestDueByFamily = [];
        if ($topFamilyIds !== []) {
            if ($projectFilter->isActive) {
                $detailQuery = ProjectInstallmentDue::forTenant($tenantId)
                    ->whereIn('family_id', $topFamilyIds);
                $projectFilter->applyToProjectInstallmentDueQuery($detailQuery, $tenantId);
                $this->executiveMetrics->applyDashboardFamilyScope($detailQuery, $tenantId, $bccFilter, $projectFilter);
                ContributionBalance::scopeOverdue($detailQuery, $businessDate);
                $detailQuery
                    ->with(['family:id,family_name,family_code', 'project:id,name'])
                    ->orderBy('due_date')
                    ->get()
                    ->groupBy('family_id')
                    ->each(function ($dues, $familyId) use (&$oldestDueByFamily): void {
                        $oldestDueByFamily[$familyId] = $dues->first();
                    });
            } else {
                $detailQuery = ContributionDue::forTenant($tenantId)
                    ->whereIn('family_id', $topFamilyIds);
                $bccFilter->applyToContributionDueQuery($detailQuery, $tenantId);
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
                'oldest_due_label' => $firstDue?->plan?->name
                    ?? $firstDue?->installment_label
                    ?? $firstDue?->project?->name
                    ?? $firstDue?->period_label,
            ];
        })->values()->all();

        return [
            'families' => $families,
            'count' => $familyCount,
            'total_overdue_amount' => $totalOverdueAmount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentActivity(int $tenantId, ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null): array
    {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = DonationBusinessDate::today($tenantId);

        $query = $this->scopeSucceededPaymentsThroughBusinessDate(
            DonationPayment::forTenant($tenantId),
            $businessDate
        );
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);
        $projectFilter->applyToDonationPaymentQuery($query, $tenantId);

        return $query
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
    private function buildProjectSummaries(int $tenantId, ?DashboardProjectFilter $projectFilter = null): array
    {
        return $this->executiveMetrics->executiveProjectSummaries($tenantId, $projectFilter);
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
    private function buildCollectionsByMethodForRange(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null, ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = DonationBusinessDate::today($tenantId);
        $effectiveEnd = $endDate > $businessDate ? $businessDate : $endDate;

        if ($projectFilter->isActive) {
            return $this->executiveMetrics->sumProjectFundingAllocationsByMethod(
                $tenantId,
                $startDate,
                $effectiveEnd,
                $bccFilter,
                $projectFilter
            );
        }

        $query = $this->scopeSucceededPaymentsThroughBusinessDate(
            DonationPayment::forTenant($tenantId),
            $businessDate
        )
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $effectiveEnd);
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);

        return $query
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($sum) => MoneyMath::toApiNumber($sum))
            ->all();
    }

    private function outstandingSumExpression(): string
    {
        return ContributionBalance::flooredOutstandingSumSqlExpression();
    }
}
