<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantHierarchyService;

class DioceseRollupDashboardService
{
    public function __construct(
        private readonly TenantHierarchyService $hierarchyService,
        private readonly FinancialHealthService $healthService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $rootTenantId): array
    {
        $root = Tenant::query()->findOrFail($rootTenantId);

        if (!$this->hierarchyService->supportsHierarchy()) {
            return [
                'available' => false,
                'message' => 'Diocese rollup requires the tenant hierarchy migration. Run php artisan migrate.',
                'root' => $this->hierarchyService->summarizeNode($root),
                'consolidated' => null,
                'parishes' => [],
            ];
        }

        $parishNodes = $this->resolveParishNodes($root);
        $scopeTenantIds = $this->hierarchyService->descendantIds($rootTenantId, true);

        if (count($scopeTenantIds) <= 1 && $parishNodes->isEmpty()) {
            return [
                'available' => false,
                'message' => 'No child parishes are configured for rollup reporting.',
                'root' => $this->hierarchyService->summarizeNode($root),
                'consolidated' => null,
                'parishes' => [],
            ];
        }

        $consolidated = $this->buildConsolidatedMetrics($scopeTenantIds);
        $parishRows = $parishNodes->map(fn (Tenant $parish) => $this->buildParishRow($parish))->values()->all();
        $trend = $this->buildConsolidatedTrend($scopeTenantIds);

        $participationRate = $consolidated['active_families'] > 0
            ? round(($consolidated['participating_families'] / $consolidated['active_families']) * 100, 1)
            : 0.0;

        $financialHealth = $this->healthService->buildChurchScore([
            'participation_rate' => $participationRate,
            'overdue_ratio_pct' => $consolidated['overdue_ratio_pct'],
            'project_momentum_pct' => $consolidated['project_momentum_pct'],
            'collection_growth_pct' => $consolidated['collection_growth_pct'],
            'overdue_family_count' => $consolidated['overdue_family_count'],
        ]);

        return [
            'available' => true,
            'root' => $this->hierarchyService->summarizeNode($root),
            'scope' => [
                'tenant_count' => count($scopeTenantIds),
                'parish_count' => count($parishRows),
                'tenant_ids' => $scopeTenantIds,
            ],
            'financial_health' => $financialHealth,
            'consolidated' => $consolidated,
            'collection_trend' => $trend,
            'parishes' => $parishRows,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    private function resolveParishNodes(Tenant $root)
    {
        $directChildren = Tenant::query()
            ->where('parent_tenant_id', $root->id)
            ->orderBy('name')
            ->get();

        if ($directChildren->isNotEmpty()) {
            return $directChildren;
        }

        $prefix = $root->hierarchy_path ?: (string) $root->id;

        return Tenant::query()
            ->where('id', '!=', $root->id)
            ->where('hierarchy_path', 'like', $prefix . '.%')
            ->where('tenant_tier', TenantHierarchyService::TIER_PARISH)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param array<int> $tenantIds
     * @return array<string, mixed>
     */
    private function buildConsolidatedMetrics(array $tenantIds): array
    {
        $totalCollected = (float) DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded')
            ->sum('amount');

        $pendingDues = (float) ContributionDue::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->get()
            ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due));

        $currentMonthStart = now()->startOfMonth()->toDateString();
        $previousMonthStart = now()->copy()->subMonth()->startOfMonth()->toDateString();
        $previousMonthEnd = now()->copy()->subMonth()->endOfMonth()->toDateString();

        $currentMonth = (float) DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $currentMonthStart)
            ->sum('amount');

        $previousMonth = (float) DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$previousMonthStart, $previousMonthEnd])
            ->sum('amount');

        $growthPct = $previousMonth > 0
            ? round((($currentMonth - $previousMonth) / $previousMonth) * 100, 1)
            : ($currentMonth > 0 ? 100.0 : 0.0);

        $activeFamilies = Family::query()->whereIn('tenant_id', $tenantIds)->where('status', 'active')->count();
        $participatingFamilies = DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', now()->subDays(90)->toDateString())
            ->pluck('family_id')
            ->unique()
            ->count();

        $today = now()->toDateString();
        $overdueAmount = (float) ContributionDue::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->get()
            ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due));

        $overdueFamilies = ContributionDue::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->pluck('family_id')
            ->unique()
            ->count();

        $overdueRatioPct = $pendingDues > 0 ? min(100, round(($overdueAmount / $pendingDues) * 100, 1)) : 0.0;

        $projects = DonationProject::query()->whereIn('tenant_id', $tenantIds)->where('status', 'active')->get();
        $projectMomentum = $projects->avg(function (DonationProject $project): float {
            $target = max((float) $project->target_amount, 1);

            return min(100, ((float) $project->raised_amount / $target) * 100);
        }) ?? 0.0;

        return [
            'total_collected' => round($totalCollected, 2),
            'pending_dues' => round($pendingDues, 2),
            'overdue_amount' => round($overdueAmount, 2),
            'current_month_collected' => round($currentMonth, 2),
            'previous_month_collected' => round($previousMonth, 2),
            'collection_growth_pct' => $growthPct,
            'active_families' => $activeFamilies,
            'participating_families' => $participatingFamilies,
            'overdue_family_count' => $overdueFamilies,
            'overdue_ratio_pct' => $overdueRatioPct,
            'project_momentum_pct' => round((float) $projectMomentum, 1),
            'active_projects' => $projects->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParishRow(Tenant $parish): array
    {
        $tenantId = (int) $parish->id;
        $scopeIds = $this->hierarchyService->descendantIds($tenantId, true);
        $metrics = $this->buildConsolidatedMetrics($scopeIds);

        $participationRate = $metrics['active_families'] > 0
            ? round(($metrics['participating_families'] / $metrics['active_families']) * 100, 1)
            : 0.0;

        $health = $this->healthService->buildChurchScore([
            'participation_rate' => $participationRate,
            'overdue_ratio_pct' => $metrics['overdue_ratio_pct'],
            'project_momentum_pct' => $metrics['project_momentum_pct'],
            'collection_growth_pct' => $metrics['collection_growth_pct'],
            'overdue_family_count' => $metrics['overdue_family_count'],
        ]);

        return array_merge($this->hierarchyService->summarizeNode($parish), [
            'metrics' => $metrics,
            'participation_rate' => $participationRate,
            'health_score' => $health['score'],
            'health_status' => $health['status'],
            'health_label' => $health['label'],
        ]);
    }

    /**
     * @param array<int> $tenantIds
     * @return array<int, array<string, mixed>>
     */
    private function buildConsolidatedTrend(array $tenantIds): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $payments = DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
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
}
