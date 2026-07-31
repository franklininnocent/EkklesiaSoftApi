<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantHierarchyService;

class DonationDashboardService
{
    public function __construct(
        private readonly FinancialHealthService $healthService,
        private readonly TenantHierarchyService $hierarchyService,
        private readonly DashboardInsightsService $insightsService
    ) {
    }

    public function getSummary(int $tenantId): array
    {
        $base = $this->buildBaseTotals($tenantId);
        $families = $this->buildFamilyMetrics($tenantId);
        $periodCollections = $this->buildPeriodCollections($tenantId);
        $trend = $this->buildCollectionTrend($tenantId);
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

        $paymentCount = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', now()->startOfMonth()->toDateString())
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
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

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
     * @param array<int, array<string, mixed>> $trend
     * @return array<string, mixed>
     */
    private function buildCollectionPerformanceChart(int $tenantId, array $trend): array
    {
        $collectedPoints = [];
        $outstandingPoints = [];
        $targetPoints = [];

        foreach ($trend as $row) {
            $period = (string) $row['period'];
            $collected = (float) $row['collected'];
            $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString();
            $monthEnd = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();

            $target = (float) ContributionDue::forTenant($tenantId)
                ->whereBetween('due_date', [$monthStart, $monthEnd])
                ->sum('amount_due');

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
     * @return array<string, mixed>|null
     */
    private function buildTenantContext(int $tenantId): ?array
    {
        $tenant = Tenant::query()->find($tenantId);
        if (!$tenant) {
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
        $totalCollected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->sum('amount');

        $totalRefunded = (float) DonationRefund::forTenant($tenantId)
            ->where('status', 'completed')
            ->sum('amount');

        $pendingDues = ContributionBalance::sumOutstanding(
            ContributionDue::forTenant($tenantId)
        );

        $activeProjects = DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->count();

        $voluntaryCollected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereIn('source_type', ['voluntary', 'recurring_schedule'])
            ->sum('amount');

        $voluntaryEntries = Donation::forTenant($tenantId)->count();
        $anonymousDonations = Donation::forTenant($tenantId)->where('is_anonymous', true)->count();
        $activeRecurring = RecurringDonationSchedule::forTenant($tenantId)->where('status', 'active')->count();
        $pledgedOutstanding = Donation::forTenant($tenantId)
            ->whereIn('status', ['pledged', 'partially_paid'])
            ->get()
            ->sum(fn (Donation $donation) => max((float) $donation->pledged_amount - (float) $donation->collected_amount, 0));

        $collectionsByMethod = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->get()
            ->groupBy('method')
            ->map(fn ($payments) => round((float) $payments->sum('amount'), 2));

        $voluntaryByCategory = Donation::forTenant($tenantId)
            ->with('category:id,name')
            ->where('collected_amount', '>', 0)
            ->get()
            ->groupBy(fn (Donation $donation) => $donation->donation_category_id ?? 'uncategorized')
            ->map(function ($group, $categoryId) {
                $first = $group->first();

                return [
                    'category_id' => $categoryId === 'uncategorized' ? null : $categoryId,
                    'category_name' => $first->category?->name ?? 'Uncategorized',
                    'collected' => round((float) $group->sum('collected_amount'), 2),
                ];
            })
            ->values()
            ->all();

        return [
            'totals' => [
                'collected' => round($totalCollected, 2),
                'refunded' => round($totalRefunded, 2),
                'net' => round($totalCollected - $totalRefunded, 2),
                'pending_dues' => round($pendingDues, 2),
                'active_projects' => $activeProjects,
                'voluntary_collected' => round($voluntaryCollected, 2),
                'voluntary_pledged_outstanding' => round((float) $pledgedOutstanding, 2),
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
        $totalFamilies = Family::query()->where('tenant_id', $tenantId)->count();
        $activeFamilies = Family::query()->where('tenant_id', $tenantId)->where('status', 'active')->count();

        $participatingFamilies = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', now()->subDays(90)->toDateString())
            ->pluck('family_id')
            ->unique()
            ->count();

        $previousParticipatingFamilies = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereBetween('payment_date', [
                now()->subDays(180)->toDateString(),
                now()->subDays(91)->toDateString(),
            ])
            ->pluck('family_id')
            ->unique()
            ->count();

        $participationRate = $activeFamilies > 0
            ? round(($participatingFamilies / $activeFamilies) * 100, 1)
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
     * @return array<string, mixed>
     */
    private function buildPeriodCollections(int $tenantId): array
    {
        $fyStart = $this->resolveFinancialYearStart($tenantId);
        $currentMonthStart = now()->startOfMonth();
        $previousMonthStart = now()->copy()->subMonth()->startOfMonth();
        $previousMonthEnd = now()->copy()->subMonth()->endOfMonth();

        $currentMonthCollected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $currentMonthStart->toDateString())
            ->sum('amount');

        $previousMonthCollected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$previousMonthStart->toDateString(), $previousMonthEnd->toDateString()])
            ->sum('amount');

        $annualCollected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $fyStart->toDateString())
            ->sum('amount');

        return [
            'financial_year' => sprintf('%s-%s', $fyStart->format('Y'), $fyStart->copy()->addYear()->subDay()->format('Y')),
            'current_month_collected' => round($currentMonthCollected, 2),
            'previous_month_collected' => round($previousMonthCollected, 2),
            'annual_collected' => round($annualCollected, 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCollectionTrend(int $tenantId): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $payments = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->get();

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[$key] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'collected' => 0.0,
            ];
        }

        foreach ($payments as $payment) {
            $key = $payment->payment_date?->format('Y-m');
            if ($key && isset($buckets[$key])) {
                $buckets[$key]['collected'] += (float) $payment->amount;
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['collected'] = round($row['collected'], 2);

            return $row;
        }, $buckets));
    }

    /**
     * @return array{families: array<int, array<string, mixed>>, count: int, total_overdue_amount: float}
     */
    private function buildAttentionList(int $tenantId): array
    {
        $today = now()->toDateString();
        $dues = ContributionDue::forTenant($tenantId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->with(['family:id,family_name,family_code', 'plan:id,name'])
            ->get();

        $grouped = $dues->groupBy('family_id')->map(function ($familyDues, $familyId) {
            $firstDue = $familyDues->sortBy('due_date')->first();
            $overdueAmount = round((float) $familyDues->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)), 2);
            $daysOverdue = $firstDue?->due_date
                ? (int) $firstDue->due_date->diffInDays(now()->startOfDay())
                : 0;

            return [
                'family_id' => $familyId,
                'family_name' => $firstDue?->family?->family_name,
                'family_code' => $firstDue?->family?->family_code,
                'overdue_amount' => $overdueAmount,
                'overdue_count' => $familyDues->count(),
                'days_overdue' => $daysOverdue,
                'oldest_due_label' => $firstDue?->plan?->name ?? $firstDue?->period_label,
            ];
        })->sortByDesc('overdue_amount')->values();

        $top = $grouped->take(10)->values()->all();

        return [
            'families' => $top,
            'count' => $grouped->count(),
            'total_overdue_amount' => round((float) $grouped->sum('overdue_amount'), 2),
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

    private function resolveFinancialYearStart(int $tenantId): \Carbon\Carbon
    {
        $settings = DonationSetting::forTenant($tenantId)->first();
        $month = (int) ($settings?->financial_year_start_month ?? 1);
        $day = (int) ($settings?->financial_year_start_day ?? 1);
        $now = now();
        $fyStart = $now->copy()->setMonth($month)->setDay($day)->startOfDay();

        if ($now->lt($fyStart)) {
            $fyStart->subYear();
        }

        return $fyStart;
    }
}
