<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\TenantHierarchyService;

class DioceseRollupDashboardService
{
    public function __construct(
        private readonly TenantHierarchyService $hierarchyService,
        private readonly FinancialHealthService $healthService,
        private readonly ChurchCurrencyResolver $currencyResolver,
        private readonly ExecutiveReportMetricsService $executiveMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $rootTenantId): array
    {
        $root = Tenant::query()->findOrFail($rootTenantId);

        if (! $this->hierarchyService->supportsHierarchy()) {
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

        $parishScopes = [];
        foreach ($parishNodes as $parish) {
            $parishScopes[(int) $parish->id] = $this->hierarchyService->descendantIds((int) $parish->id, true);
        }

        $metricTenantIds = $scopeTenantIds;
        foreach ($parishScopes as $ids) {
            foreach ($ids as $id) {
                $metricTenantIds[] = (int) $id;
            }
        }
        $metricTenantIds = array_values(array_unique(array_map('intval', $metricTenantIds)));
        $byTenant = $this->metricsByTenant($metricTenantIds);

        $currencyCodes = $this->distinctCurrencyCodes($metricTenantIds);
        $moneyComparable = count($currencyCodes) <= 1;
        $consolidated = $this->composeMetrics($scopeTenantIds, $byTenant, $moneyComparable);
        $parishRows = $parishNodes->map(function (Tenant $parish) use ($parishScopes, $byTenant): array {
            return $this->buildParishRow($parish, $parishScopes[(int) $parish->id] ?? [], $byTenant);
        })->values()->all();
        $trend = $moneyComparable ? $this->buildConsolidatedTrend($scopeTenantIds) : [];

        $participationRate = $consolidated['active_families'] > 0
            ? round(($consolidated['participating_families'] / $consolidated['active_families']) * 100, 1)
            : 0.0;

        $growthAnalysis = $this->executiveMetrics->consolidatedCollectionGrowthAnalysis($scopeTenantIds);
        $projectApplicable = (int) ($consolidated['active_projects'] ?? 0) > 0;
        $financialHealth = $this->healthService->buildChurchScore([
            'participation_rate' => $participationRate,
            'overdue_ratio_pct' => $consolidated['overdue_ratio_pct'],
            'project_momentum_pct' => $consolidated['project_momentum_pct'],
            'project_applicable' => $projectApplicable,
            'collection_growth_pct' => (float) $growthAnalysis['growth_pct'],
            'growth_health_score' => (float) $growthAnalysis['growth_health_score'],
            'overdue_family_count' => $consolidated['overdue_family_count'],
            'current_month_collected' => (float) ($consolidated['current_month_collected'] ?? $growthAnalysis['current_collected'] ?? 0),
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
            'money_comparable' => $moneyComparable,
            'currencies' => $currencyCodes,
            'consolidated' => $consolidated,
            'collection_trend' => $trend,
            'parishes' => $parishRows,
        ];
    }

    /**
     * @param  array<int>  $tenantIds
     * @return array<int, string>
     */
    private function distinctCurrencyCodes(array $tenantIds): array
    {
        $codes = [];
        foreach (array_unique(array_map('intval', $tenantIds)) as $tenantId) {
            $code = $this->currencyResolver->currencyCodeForTenantId($tenantId);
            if ($code !== null && $code !== '') {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @return Collection<int, Tenant>
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
            ->where('hierarchy_path', 'like', $prefix.'.%')
            ->where('tenant_tier', TenantHierarchyService::TIER_PARISH)
            ->orderBy('name')
            ->get();
    }

    /**
     * One pass of additive metrics keyed by tenant. Parish rows sum the tenants
     * already in their descendant set instead of repeating every aggregate.
     *
     * @param  array<int>  $tenantIds
     * @return array<int, array<string, float|int>>
     */
    private function metricsByTenant(array $tenantIds): array
    {
        $blank = [
            'total_collected' => 0.0,
            'pending_dues' => 0.0,
            'overdue_amount' => 0.0,
            'current_month_collected' => 0.0,
            'previous_month_collected' => 0.0,
            'active_families' => 0,
            'participating_families' => 0,
            'overdue_family_count' => 0,
            'active_projects' => 0,
            'project_progress_sum' => 0.0,
            'project_count_for_avg' => 0,
        ];

        $byTenant = [];
        foreach ($tenantIds as $tenantId) {
            $byTenant[(int) $tenantId] = $blank;
        }

        if ($tenantIds === []) {
            return $byTenant;
        }

        $payments = DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded');

        foreach ((clone $payments)->selectRaw('tenant_id, COALESCE(SUM(amount), 0) as total')->groupBy('tenant_id')->get() as $row) {
            $byTenant[(int) $row->tenant_id]['total_collected'] = (float) $row->total;
        }

        $currentMonthStart = now()->startOfMonth()->toDateString();
        $previousMonthStart = now()->copy()->subMonth()->startOfMonth()->toDateString();
        $previousMonthEnd = now()->copy()->subMonth()->endOfMonth()->toDateString();

        foreach ((clone $payments)->whereDate('payment_date', '>=', $currentMonthStart)->selectRaw('tenant_id, COALESCE(SUM(amount), 0) as total')->groupBy('tenant_id')->get() as $row) {
            $byTenant[(int) $row->tenant_id]['current_month_collected'] = (float) $row->total;
        }

        foreach ((clone $payments)->whereBetween('payment_date', [$previousMonthStart, $previousMonthEnd])->selectRaw('tenant_id, COALESCE(SUM(amount), 0) as total')->groupBy('tenant_id')->get() as $row) {
            $byTenant[(int) $row->tenant_id]['previous_month_collected'] = (float) $row->total;
        }

        foreach ((clone $payments)
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', now()->subDays(90)->toDateString())
            ->selectRaw('tenant_id, COUNT(DISTINCT family_id) as participating')
            ->groupBy('tenant_id')
            ->get() as $row) {
            $byTenant[(int) $row->tenant_id]['participating_families'] = (int) $row->participating;
        }

        $idsByBusinessDate = [];
        foreach ($tenantIds as $tenantId) {
            $idsByBusinessDate[DonationBusinessDate::today((int) $tenantId)][] = (int) $tenantId;
        }

        foreach ($idsByBusinessDate as $businessDate => $ids) {
            $rows = ContributionBalance::scopeCollectable(
                ContributionDue::query()->whereIn('tenant_id', $ids),
                (string) $businessDate
            )
                ->selectRaw('tenant_id, COALESCE(SUM(amount_due - amount_paid), 0) as outstanding')
                ->groupBy('tenant_id')
                ->get();

            foreach ($rows as $row) {
                $byTenant[(int) $row->tenant_id]['pending_dues'] = MoneyMath::toApiNumber($row->outstanding);
            }
        }

        $today = now()->toDateString();
        $overdueRows = ContributionDue::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->selectRaw(
                'tenant_id, COALESCE(SUM(CASE WHEN COALESCE(amount_paid, 0) > COALESCE(amount_due, 0) THEN 0 ELSE COALESCE(amount_due, 0) - COALESCE(amount_paid, 0) END), 0) as overdue_amount, COUNT(DISTINCT family_id) + MAX(CASE WHEN family_id IS NULL THEN 1 ELSE 0 END) as overdue_families'
            )
            ->groupBy('tenant_id')
            ->get();

        foreach ($overdueRows as $row) {
            $byTenant[(int) $row->tenant_id]['overdue_amount'] = (float) $row->overdue_amount;
            $byTenant[(int) $row->tenant_id]['overdue_family_count'] = (int) $row->overdue_families;
        }

        foreach (Family::query()->whereIn('tenant_id', $tenantIds)->where('status', 'active')->selectRaw('tenant_id, COUNT(*) as active_families')->groupBy('tenant_id')->get() as $row) {
            $byTenant[(int) $row->tenant_id]['active_families'] = (int) $row->active_families;
        }

        $projects = DonationProject::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'active')
            ->get(['tenant_id', 'target_amount', 'raised_amount']);

        foreach ($projects as $project) {
            $tenantId = (int) $project->tenant_id;
            if (! isset($byTenant[$tenantId])) {
                continue;
            }
            $target = max((float) $project->target_amount, 1);
            $byTenant[$tenantId]['project_progress_sum'] += min(100, ((float) $project->raised_amount / $target) * 100);
            $byTenant[$tenantId]['project_count_for_avg']++;
            $byTenant[$tenantId]['active_projects']++;
        }

        return $byTenant;
    }

    /**
     * @param  array<int>  $tenantIds
     * @param  array<int, array<string, float|int>>  $byTenant
     * @return array<string, mixed>
     */
    private function composeMetrics(array $tenantIds, array $byTenant, bool $moneyComparable = true): array
    {
        $totalCollected = '0.00';
        $pendingDues = '0.00';
        $overdueAmount = '0.00';
        $currentMonth = '0.00';
        $previousMonth = '0.00';
        $activeFamilies = 0;
        $participatingFamilies = 0;
        $overdueFamilies = 0;
        $activeProjects = 0;
        $progressSum = 0.0;
        $progressCount = 0;

        foreach (array_unique(array_map('intval', $tenantIds)) as $tenantId) {
            $row = $byTenant[$tenantId] ?? null;
            if ($row === null) {
                continue;
            }

            if ($moneyComparable) {
                $totalCollected = MoneyMath::add($totalCollected, (string) $row['total_collected']);
                $pendingDues = MoneyMath::add($pendingDues, (string) $row['pending_dues']);
                $overdueAmount = MoneyMath::add($overdueAmount, (string) $row['overdue_amount']);
                $currentMonth = MoneyMath::add($currentMonth, (string) $row['current_month_collected']);
                $previousMonth = MoneyMath::add($previousMonth, (string) $row['previous_month_collected']);
            }
            $activeFamilies += (int) $row['active_families'];
            $participatingFamilies += (int) $row['participating_families'];
            $overdueFamilies += (int) $row['overdue_family_count'];
            $activeProjects += (int) $row['active_projects'];
            $progressSum += (float) $row['project_progress_sum'];
            $progressCount += (int) $row['project_count_for_avg'];
        }

        $currentMonthFloat = (float) $currentMonth;
        $previousMonthFloat = (float) $previousMonth;
        $pendingFloat = (float) $pendingDues;
        $overdueFloat = (float) $overdueAmount;

        $growthPct = 0.0;
        if ($moneyComparable) {
            $growthPct = (float) $this->executiveMetrics->consolidatedCollectionGrowthAnalysis(
                array_unique(array_map('intval', $tenantIds))
            )['growth_pct'];
        }

        $projectMomentum = $progressCount > 0 ? $progressSum / $progressCount : 0.0;
        $overdueRatioPct = $moneyComparable && $pendingFloat > 0
            ? min(100, round(($overdueFloat / $pendingFloat) * 100, 1))
            : 0.0;

        return [
            'total_collected' => $moneyComparable ? MoneyMath::toApiNumber($totalCollected) : null,
            'pending_dues' => $moneyComparable ? MoneyMath::toApiNumber($pendingDues) : null,
            'overdue_amount' => $moneyComparable ? MoneyMath::toApiNumber($overdueAmount) : null,
            'current_month_collected' => $moneyComparable ? MoneyMath::toApiNumber($currentMonth) : null,
            'previous_month_collected' => $moneyComparable ? MoneyMath::toApiNumber($previousMonth) : null,
            'collection_growth_pct' => $growthPct,
            'active_families' => $activeFamilies,
            'participating_families' => $participatingFamilies,
            'overdue_family_count' => $overdueFamilies,
            'overdue_ratio_pct' => $overdueRatioPct,
            'project_momentum_pct' => round((float) $projectMomentum, 1),
            'active_projects' => $activeProjects,
        ];
    }

    /**
     * @param  array<int>  $scopeIds
     * @param  array<int, array<string, float|int>>  $byTenant
     * @return array<string, mixed>
     */
    private function buildParishRow(Tenant $parish, array $scopeIds, array $byTenant): array
    {
        $metrics = $this->composeMetrics($scopeIds, $byTenant, true);

        $participationRate = $metrics['active_families'] > 0
            ? round(($metrics['participating_families'] / $metrics['active_families']) * 100, 1)
            : 0.0;

        $growthAnalysis = $this->executiveMetrics->consolidatedCollectionGrowthAnalysis($scopeIds);
        $projectApplicable = (int) ($metrics['active_projects'] ?? 0) > 0;
        $health = $this->healthService->buildChurchScore([
            'participation_rate' => $participationRate,
            'overdue_ratio_pct' => $metrics['overdue_ratio_pct'],
            'project_momentum_pct' => $metrics['project_momentum_pct'],
            'project_applicable' => $projectApplicable,
            'collection_growth_pct' => (float) $growthAnalysis['growth_pct'],
            'growth_health_score' => (float) $growthAnalysis['growth_health_score'],
            'overdue_family_count' => $metrics['overdue_family_count'],
            'current_month_collected' => (float) ($metrics['current_month_collected'] ?? $growthAnalysis['current_collected'] ?? 0),
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
     * @param  array<int>  $tenantIds
     * @return array<int, array<string, mixed>>
     */
    private function buildConsolidatedTrend(array $tenantIds): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $monthExpr = DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR(payment_date, 'YYYY-MM')"
            : "strftime('%Y-%m', payment_date)";

        $collectedByMonth = DonationPayment::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->selectRaw("{$monthExpr} as period, COALESCE(SUM(amount), 0) as collected")
            ->groupByRaw($monthExpr)
            ->get()
            ->mapWithKeys(fn ($row) => [trim((string) $row->period) => $row->collected]);

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[$key] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'collected' => (float) ($collectedByMonth[$key] ?? 0),
            ];
        }

        return array_values(array_map(function (array $row): array {
            $row['collected'] = round($row['collected'], 2);

            return $row;
        }, $buckets));
    }
}
