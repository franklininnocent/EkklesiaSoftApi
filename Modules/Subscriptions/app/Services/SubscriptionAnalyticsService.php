<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Support\RevenueCalculator;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * Platform-wide subscription figures for the Ekklesia admin console. Lifecycle status always comes
 * from the Tenants SubscriptionService (the single status engine); usage from nightly snapshots.
 */
class SubscriptionAnalyticsService
{
    public const ATTENTION_LEVELS = [
        UsageService::LEVEL_WARNING,
        UsageService::LEVEL_CRITICAL,
        UsageService::LEVEL_AT_LIMIT,
        UsageService::LEVEL_OVER_LIMIT,
    ];

    public function __construct(
        private readonly SubscriptionService $lifecycle,
        private readonly UsageService $usage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $plans = Plan::query()->catalog()->withTrashed()->orderBy('display_order')->get(['id', 'code', 'name', 'is_legacy', 'status']);
        $byPlan = array_fill_keys($plans->pluck('id')->all(), 0);
        $statusCounts = [];
        $assigned = 0;

        $this->eachCurrentSubscription(function (TenantSubscription $s, Tenant $tenant, string $status) use (&$byPlan, &$statusCounts, &$assigned): void {
            $assigned++;
            $byPlan[$s->plan_id] = ($byPlan[$s->plan_id] ?? 0) + 1;
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        });

        $planRows = $plans
            ->map(fn (Plan $p) => [
                'plan_id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'is_legacy' => (bool) $p->is_legacy,
                'status' => $p->status,
                'tenant_count' => $byPlan[$p->id] ?? 0,
            ])
            ->filter(fn (array $row) => ! $row['is_legacy'] || $row['tenant_count'] > 0)
            ->values()
            ->all();

        $attention = $this->latestUsageRows();

        return [
            'total_tenants' => Tenant::query()->count(),
            'assigned_tenants' => $assigned,
            'plans' => $planRows,
            'status_counts' => $statusCounts,
            'open_requests' => SubscriptionUpgradeRequest::query()->whereIn('status', SubscriptionUpgradeRequest::OPEN_STATUSES)->count(),
            'tenants_needing_attention' => collect($attention['rows'])->filter(fn ($r) => in_array($r['level'], self::ATTENTION_LEVELS, true))->pluck('tenant_id')->unique()->count(),
            'usage_measured_on' => $attention['snapshot_date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revenue(): array
    {
        $mrr = [];
        $counted = 0;
        $excluded = ['trial' => 0, 'expired' => 0, 'suspended' => 0, 'unpriced' => 0];
        $byPlan = [];

        $this->eachCurrentSubscription(function (TenantSubscription $s, Tenant $tenant, string $status) use (&$mrr, &$counted, &$excluded, &$byPlan): void {
            if (! RevenueCalculator::counts($status)) {
                $key = match ($status) {
                    SubscriptionService::STATUS_TRIAL => 'trial',
                    SubscriptionService::STATUS_SUSPENDED => 'suspended',
                    default => 'expired',
                };
                $excluded[$key]++;

                return;
            }
            $monthly = RevenueCalculator::monthlyEquivalent($s->contracted_price !== null ? (string) $s->contracted_price : null, $s->billing_interval);
            if ($monthly === null) {
                $excluded['unpriced']++;

                return;
            }
            $currency = $s->currency_code ?: 'INR';
            $mrr[$currency] = RevenueCalculator::add($mrr[$currency] ?? '0', $monthly);
            $planKey = $currency.'|'.$s->plan_id;
            $byPlan[$planKey] = RevenueCalculator::add($byPlan[$planKey] ?? '0', $monthly);
            $counted++;
        });

        $planNames = Plan::query()->withTrashed()->pluck('name', 'id');
        $totals = [];
        foreach ($mrr as $currency => $value) {
            $totals[] = [
                'currency_code' => $currency,
                'mrr' => RevenueCalculator::round($value),
                'arr' => RevenueCalculator::annualize($value),
            ];
        }
        $planRows = [];
        foreach ($byPlan as $key => $value) {
            [$currency, $planId] = explode('|', $key, 2);
            $planRows[] = [
                'plan_id' => (int) $planId,
                'plan_name' => $planNames[(int) $planId] ?? null,
                'currency_code' => $currency,
                'mrr' => RevenueCalculator::round($value),
            ];
        }

        return [
            'label' => RevenueCalculator::LABEL,
            'totals' => $totals,
            'by_plan' => $planRows,
            'counted_tenants' => $counted,
            'excluded' => $excluded,
            'counted_statuses' => RevenueCalculator::COUNTED_STATUSES,
        ];
    }

    /**
     * Churches with their latest measured usage, attention-first.
     *
     * @return array{snapshot_date: ?string, data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function usageList(bool $attentionOnly, int $page, int $perPage): array
    {
        $latest = $this->latestUsageRows();
        $byTenant = [];
        foreach ($latest['rows'] as $row) {
            $byTenant[$row['tenant_id']][] = $row;
        }

        $tenants = Tenant::query()->whereIn('id', array_keys($byTenant))->get(['id', 'name'])->keyBy('id');
        $rank = array_flip(array_merge([UsageService::LEVEL_OK, UsageService::LEVEL_NOTICE], self::ATTENTION_LEVELS));

        $list = [];
        foreach ($byTenant as $tenantId => $rows) {
            $tenant = $tenants[$tenantId] ?? null;
            if (! $tenant) {
                continue;
            }
            $worst = collect($rows)->max(fn ($r) => $rank[$r['level']] ?? 0) ?? 0;
            $worstLevel = array_search($worst, $rank, true) ?: UsageService::LEVEL_OK;
            if ($attentionOnly && ! in_array($worstLevel, self::ATTENTION_LEVELS, true)) {
                continue;
            }
            $list[] = [
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant->name,
                'worst_level' => $worstLevel,
                'rank' => $worst,
                'usage' => array_map(static fn ($r) => array_diff_key($r, ['tenant_id' => true]), $rows),
            ];
        }

        usort($list, static fn ($a, $b) => [$b['rank'], $a['tenant_name']] <=> [$a['rank'], $b['tenant_name']]);
        $total = count($list);
        $slice = array_slice($list, max(0, ($page - 1) * $perPage), $perPage);

        return [
            'snapshot_date' => $latest['snapshot_date'],
            'data' => array_map(static fn ($r) => array_diff_key($r, ['rank' => true]), $slice),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /**
     * @param  callable(TenantSubscription, Tenant, string): void  $callback
     */
    private function eachCurrentSubscription(callable $callback): void
    {
        TenantSubscription::query()->current()->with('tenant')->chunkById(200, function ($rows) use ($callback): void {
            foreach ($rows as $s) {
                $tenant = $s->tenant;
                if (! $tenant) {
                    continue;
                }
                $callback($s, $tenant, $this->lifecycle->resolveStatus($tenant));
            }
        });
    }

    /**
     * @return array{snapshot_date: ?string, rows: list<array<string, mixed>>}
     */
    private function latestUsageRows(): array
    {
        $date = DB::table('tenant_usage_snapshots')->max('snapshot_date');
        if (! $date) {
            return ['snapshot_date' => null, 'rows' => []];
        }

        $rows = DB::table('tenant_usage_snapshots as s')
            ->join('features as f', 'f.id', '=', 's.feature_id')
            ->where('s.snapshot_date', $date)
            ->get(['s.tenant_id', 'f.code', 'f.name', 'f.unit', 's.usage_value', 's.limit_value'])
            ->map(function ($r) {
                $limit = $r->limit_value !== null ? (int) $r->limit_value : null;
                $usage = (int) $r->usage_value;

                return [
                    'tenant_id' => (int) $r->tenant_id,
                    'code' => $r->code,
                    'name' => $r->name,
                    'unit' => $r->unit,
                    'usage' => $usage,
                    'limit' => $limit,
                    'level' => $this->usage->level($usage, $limit),
                ];
            })
            ->all();

        return ['snapshot_date' => substr((string) $date, 0, 10), 'rows' => $rows];
    }
}
