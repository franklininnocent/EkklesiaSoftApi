<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Modules\Tenants\Models\Tenant;

/**
 * Platform analytics aggregations: adoption, usage, features, trends.
 */
class AdminMinistriesAnalyticsService
{
    public function __construct(
        private readonly AdminMinistriesTenantAnalyticsService $tenantAnalytics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adoption(AdminMinistriesWindow $window): array
    {
        $rows = $this->tenantAnalytics->buildRows($window);
        $counts = $this->countAdoptionFlags($rows);

        return [
            'window' => $this->windowPayload($window),
            'funnel' => [
                'all_tenants' => $counts['total_tenants'],
                'module_enabled' => $counts['module_enabled'],
                'not_started' => $counts['not_started'],
                'activated' => $counts['activated'],
                'active' => $counts['active'],
                'highly_engaged' => $counts['highly_engaged'],
                'inactive' => $counts['inactive'],
                'declining' => $counts['declining'],
            ],
            'rates' => [
                'enabled_of_all' => $this->rate($counts['module_enabled'], $counts['total_tenants']),
                'activated_of_enabled' => $this->rate($counts['activated'], $counts['module_enabled']),
                'active_of_activated' => $this->rate($counts['active'], $counts['activated']),
                'highly_engaged_of_active' => $this->rate($counts['highly_engaged'], $counts['active']),
            ],
            'definitions' => AdminMinistriesDefinitions::toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(AdminMinistriesWindow $window): array
    {
        $rows = $this->tenantAnalytics->buildRows($window);
        $enabled = $rows->where('module_status', 'enabled');
        $activated = $enabled->where('activated', true);

        $totalActions = (int) $activated->sum('current_events');
        $priorActions = (int) $activated->sum('prior_events');
        $activeTenants = $activated->where('active', true)->count();
        $activeUsers = (int) $activated->sum('active_users_count');

        $buckets = [
            '0' => 0,
            '1_4' => 0,
            '5_9' => 0,
            '10_plus' => 0,
        ];
        foreach ($activated as $row) {
            $n = (int) $row['current_events'];
            if ($n === 0) {
                $buckets['0']++;
            } elseif ($n <= 4) {
                $buckets['1_4']++;
            } elseif ($n <= 9) {
                $buckets['5_9']++;
            } else {
                $buckets['10_plus']++;
            }
        }

        $trendCounts = ['up' => 0, 'flat' => 0, 'down' => 0];
        foreach ($activated as $row) {
            $trend = (string) ($row['usage_trend'] ?? 'flat');
            if (! isset($trendCounts[$trend])) {
                $trend = 'flat';
            }
            $trendCounts[$trend]++;
        }

        $top = $activated
            ->sortByDesc('current_events')
            ->take(15)
            ->values()
            ->map(static function (array $row): array {
                return [
                    'tenant_id' => $row['tenant_id'],
                    'tenant_name' => $row['tenant_name'],
                    'tenant_slug' => $row['tenant_slug'],
                    'window_actions' => $row['current_events'],
                    'prior_actions' => $row['prior_events'],
                    'active_users_count' => $row['active_users_count'],
                    'usage_trend' => $row['usage_trend'],
                    'adoption_status' => $row['primary_adoption_status'] ?? $row['adoption_status'] ?? null,
                ];
            })
            ->all();

        return [
            'window' => $this->windowPayload($window),
            'summary' => [
                'enabled_tenants' => $enabled->count(),
                'activated_tenants' => $activated->count(),
                'active_tenants' => $activeTenants,
                'meaningful_actions' => $totalActions,
                'prior_meaningful_actions' => $priorActions,
                'usage_trend' => $this->tenantAnalytics->trend($totalActions, $priorActions),
                'active_users' => $activeUsers,
            ],
            'frequency_buckets' => $buckets,
            'trend_counts' => $trendCounts,
            'top_tenants' => $top,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function features(AdminMinistriesWindow $window, ?string $category = null): array
    {
        $enabledTenantIds = Tenant::query()
            ->get(['id', 'name', 'slug', 'features'])
            ->filter(static fn (Tenant $t) => $t->supportsMinistriesAssociations())
            ->values();

        $enabledCount = $enabledTenantIds->count();
        $enabledIdSet = $enabledTenantIds->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $eventRows = MinistriesAuditLog::query()
            ->select(['tenant_id', 'event'])
            ->whereIn('tenant_id', $enabledIdSet ?: [0])
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->get();

        $entityEvidence = $this->entityEvidenceTenantIds($enabledIdSet);

        $categories = [];
        foreach (AdminMinistriesDefinitions::FEATURE_EVENT_MAP as $cat => $events) {
            $eventSet = array_flip($events);
            $tenantsWithEvidence = [];

            foreach ($eventRows as $row) {
                if (! isset($eventSet[$row->event])) {
                    continue;
                }
                $tenantsWithEvidence[(int) $row->tenant_id] = true;
            }

            foreach ($entityEvidence[$cat] ?? [] as $tenantId) {
                $tenantsWithEvidence[(int) $tenantId] = true;
            }

            $withEvidence = count($tenantsWithEvidence);
            $categories[$cat] = [
                'category' => $cat,
                'tenants_with_evidence' => $withEvidence,
                'enabled_tenants' => $enabledCount,
                'adoption_percent' => $this->rate($withEvidence, $enabledCount),
                'tenant_ids' => array_map('intval', array_keys($tenantsWithEvidence)),
            ];
        }

        $payload = [
            'window' => $this->windowPayload($window),
            'enabled_tenants' => $enabledCount,
            'categories' => array_values($categories),
        ];

        if ($category !== null && $category !== '') {
            if (! isset($categories[$category])) {
                throw new \InvalidArgumentException('Unknown feature category.');
            }
            $ids = $categories[$category]['tenant_ids'];
            $byId = $enabledTenantIds->keyBy('id');
            $payload['drilldown'] = [
                'category' => $category,
                'tenants' => collect($ids)->map(static function (int $id) use ($byId): array {
                    $tenant = $byId->get($id);

                    return [
                        'tenant_id' => $id,
                        'tenant_name' => $tenant?->name ?? ('Tenant #'.$id),
                        'tenant_slug' => $tenant?->slug ?? '',
                    ];
                })->values()->all(),
            ];
        }

        // Strip heavy id lists from summary unless drilldown requested
        $payload['categories'] = array_map(static function (array $row): array {
            unset($row['tenant_ids']);

            return $row;
        }, $payload['categories']);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function trends(AdminMinistriesWindow $window): array
    {
        $granularity = $window->days <= 30 ? 'day' : 'week';
        $series = $this->eventSeries($window, $granularity);
        $priorSeries = $this->eventSeries(
            new AdminMinistriesWindow(
                $window->days,
                $window->priorStart,
                $window->priorEnd,
                $window->priorStart->subDays($window->days),
                $window->priorStart,
            ),
            $granularity
        );

        $currentTotal = array_sum(array_column($series, 'meaningful_actions'));
        $priorTotal = array_sum(array_column($priorSeries, 'meaningful_actions'));
        $currentActiveTenants = array_sum(array_column($series, 'active_tenants'));
        // active_tenants per bucket is not additive uniquely — sum is ok as activity intensity

        return [
            'window' => $this->windowPayload($window),
            'granularity' => $granularity,
            'series' => $series,
            'comparison' => [
                'current_meaningful_actions' => $currentTotal,
                'prior_meaningful_actions' => $priorTotal,
                'usage_trend' => $this->tenantAnalytics->trend($currentTotal, $priorTotal),
                'bucket_active_tenant_sum' => $currentActiveTenants,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countAdoptionFlags(Collection $rows): array
    {
        return [
            'total_tenants' => $rows->count(),
            'module_enabled' => $rows->where('module_status', 'enabled')->count(),
            'not_started' => $rows->where('not_started', true)->count(),
            'activated' => $rows->where('activated', true)->count(),
            'active' => $rows->where('active', true)->count(),
            'highly_engaged' => $rows->where('highly_engaged', true)->count(),
            'inactive' => $rows->where('inactive', true)->count(),
            'declining' => $rows->where('declining', true)->count(),
        ];
    }

    /**
     * @param  list<int>  $enabledIds
     * @return array<string, list<int>>
     */
    private function entityEvidenceTenantIds(array $enabledIds): array
    {
        if ($enabledIds === []) {
            return [
                'organizations' => [],
                'members' => [],
                'leadership' => [],
                'guests' => [],
                'taxonomies' => [],
            ];
        }

        $orgs = DB::table('ma_organizations')
            ->whereNull('deleted_at')
            ->whereIn('tenant_id', $enabledIds)
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $members = DB::table('ma_memberships')
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->whereIn('tenant_id', $enabledIds)
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $leaders = DB::table('ma_leadership_terms')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereIn('tenant_id', $enabledIds)
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $guests = DB::table('ma_guest_members')
            ->whereNull('deleted_at')
            ->whereIn('tenant_id', $enabledIds)
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        // Taxonomy usage: category/type/position rows exist (tenant configured taxonomies)
        $taxTenantIds = collect()
            ->merge(DB::table('ma_organization_categories')->whereNull('deleted_at')->whereIn('tenant_id', $enabledIds)->pluck('tenant_id'))
            ->merge(DB::table('ma_organization_types')->whereNull('deleted_at')->whereIn('tenant_id', $enabledIds)->pluck('tenant_id'))
            ->merge(DB::table('ma_positions')->whereNull('deleted_at')->whereIn('tenant_id', $enabledIds)->pluck('tenant_id'))
            ->unique()
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'organizations' => $orgs,
            'members' => $members,
            'leadership' => $leaders,
            'guests' => $guests,
            'taxonomies' => $taxTenantIds,
        ];
    }

    /**
     * @return list<array{period: string, label: string, meaningful_actions: int, active_tenants: int}>
     */
    private function eventSeries(AdminMinistriesWindow $window, string $granularity): array
    {
        $driver = DB::connection()->getDriverName();
        if ($granularity === 'week') {
            $periodExpr = $driver === 'pgsql'
                ? "to_char(date_trunc('week', created_at), 'YYYY-MM-DD')"
                : "strftime('%Y-W%W', created_at)";
        } else {
            $periodExpr = $driver === 'pgsql'
                ? 'to_char(created_at, \'YYYY-MM-DD\')'
                : "strftime('%Y-%m-%d', created_at)";
        }

        $rows = MinistriesAuditLog::query()
            ->selectRaw("{$periodExpr} as period")
            ->selectRaw('COUNT(*) as meaningful_actions')
            ->selectRaw('COUNT(DISTINCT tenant_id) as active_tenants')
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $rows->map(static function ($row): array {
            $period = (string) $row->period;

            return [
                'period' => $period,
                'label' => $period,
                'meaningful_actions' => (int) $row->meaningful_actions,
                'active_tenants' => (int) $row->active_tenants,
            ];
        })->all();
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function windowPayload(AdminMinistriesWindow $window): array
    {
        return [
            'days' => $window->days,
            'current_start' => $window->currentStart->toIso8601String(),
            'current_end' => $window->currentEnd->toIso8601String(),
            'prior_start' => $window->priorStart->toIso8601String(),
            'prior_end' => $window->priorEnd->toIso8601String(),
        ];
    }
}
