<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Support\Collection;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;

class AdminMinistriesOverviewService
{
    private const RECENT_ACTIVITY_LIMIT = 25;

    public function __construct(
        private readonly AdminMinistriesAttentionService $attention,
        private readonly AdminMinistriesTenantAnalyticsService $tenantAnalytics,
        private readonly AdminMinistriesHealthService $healthService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $windowDays = AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS): array
    {
        $window = AdminMinistriesWindow::fromDays($windowDays);
        $classified = $this->classifyTenants($window);
        $entityTotals = $this->entityTotals();
        $health = $this->healthService->indicators();
        $meaningfulActions = $this->meaningfulActionCount($window);
        $activeUsers = $this->activeActorCount($window);

        $counts = $this->countFlags($classified);

        $kpis = [
            'total_tenants' => $this->metric($counts['total_tenants']),
            'module_enabled' => $this->metric($counts['module_enabled']),
            'not_started' => $this->metric($counts['not_started']),
            'activated' => $this->metric($counts['activated']),
            'active' => $this->metric($counts['active']),
            'highly_engaged' => $this->metric($counts['highly_engaged']),
            'inactive' => $this->metric($counts['inactive']),
            'declining' => $this->metric($counts['declining']),
            'organizations' => $this->metric($entityTotals['organizations']),
            'active_organizations' => $this->metric($entityTotals['active_organizations']),
            'active_memberships' => $this->metric($entityTotals['active_memberships']),
            'active_leadership' => $this->metric($entityTotals['active_leadership']),
            'meaningful_actions' => $this->metric($meaningfulActions),
            'active_users' => $this->metric($activeUsers, true, 'Distinct actors on meaningful actions in window'),
            'data_health_issues' => $this->metric(
                $health['orgs_without_active_members']
                + $health['active_orgs_without_leadership']
                + $health['stale_active_orgs']
            ),
        ];

        $funnel = [
            'all_tenants' => $counts['total_tenants'],
            'module_enabled' => $counts['module_enabled'],
            'activated' => $counts['activated'],
            'active' => $counts['active'],
            'highly_engaged' => $counts['highly_engaged'],
        ];

        return [
            'phase' => 1,
            'status' => 'ready',
            'message' => 'Platform overview metrics for Ministries & Associations.',
            'window_days' => $window->days,
            'window' => [
                'days' => $window->days,
                'current_start' => $window->currentStart->toIso8601String(),
                'current_end' => $window->currentEnd->toIso8601String(),
                'prior_start' => $window->priorStart->toIso8601String(),
                'prior_end' => $window->priorEnd->toIso8601String(),
            ],
            'definitions' => AdminMinistriesDefinitions::toArray(),
            'kpis' => $kpis,
            'funnel' => $funnel,
            'health' => $health,
            'attention' => $this->attention->build($classified, $health),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /**
     * Standalone Attention Required payload (same cards as Overview).
     *
     * @return array<string, mixed>
     */
    public function attention(int $windowDays = AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS): array
    {
        $window = AdminMinistriesWindow::fromDays($windowDays);

        return [
            'window_days' => $window->days,
            'attention' => $this->attentionItems($windowDays),
        ];
    }

    /**
     * Standalone recent meaningful-activity feed (same rows as Overview).
     *
     * @return array<string, mixed>
     */
    public function activity(): array
    {
        return [
            'limit' => self::RECENT_ACTIVITY_LIMIT,
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attentionItems(int $windowDays = AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS): array
    {
        $window = AdminMinistriesWindow::fromDays($windowDays);
        $classified = $this->classifyTenants($window);
        $health = $this->healthService->indicators();

        return $this->attention->build($classified, $health);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function classifyTenants(AdminMinistriesWindow $window): Collection
    {
        return $this->tenantAnalytics->buildRows($window);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @return array<string, int>
     */
    private function countFlags(Collection $classified): array
    {
        return [
            'total_tenants' => $classified->count(),
            'module_enabled' => $classified->where('module_status', 'enabled')->count(),
            'not_started' => $classified->where('not_started', true)->count(),
            'activated' => $classified->where('activated', true)->count(),
            'active' => $classified->where('active', true)->count(),
            'highly_engaged' => $classified->where('highly_engaged', true)->count(),
            'inactive' => $classified->where('inactive', true)->count(),
            'declining' => $classified->where('declining', true)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function entityTotals(): array
    {
        return [
            'organizations' => Organization::query()->whereNull('deleted_at')->count(),
            'active_organizations' => Organization::query()
                ->whereNull('deleted_at')
                ->where('status', Organization::STATUS_ACTIVE)
                ->count(),
            'active_memberships' => OrganizationMembership::query()
                ->whereNull('deleted_at')
                ->where('is_current', true)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->count(),
            'active_leadership' => LeadershipTerm::query()
                ->whereNull('deleted_at')
                ->where('status', LeadershipTerm::STATUS_ACTIVE)
                ->count(),
        ];
    }

    private function meaningfulActionCount(AdminMinistriesWindow $window): int
    {
        return MinistriesAuditLog::query()
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->count();
    }

    private function activeActorCount(AdminMinistriesWindow $window): int
    {
        return (int) MinistriesAuditLog::query()
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->whereNotNull('actor_user_id')
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->distinct()
            ->count('actor_user_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivity(): array
    {
        return MinistriesAuditLog::query()
            ->with([
                'tenant:id,name,slug',
                'actor:id,name,email',
            ])
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(static function (MinistriesAuditLog $log): array {
                return [
                    'id' => $log->id,
                    'occurred_at' => optional($log->created_at)?->toIso8601String(),
                    'event' => $log->event,
                    'event_label' => str_replace(['.', '_'], [' — ', ' '], $log->event),
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'organization_id' => $log->organization_id,
                    'tenant' => $log->tenant ? [
                        'id' => $log->tenant->id,
                        'name' => $log->tenant->name,
                        'slug' => $log->tenant->slug,
                    ] : null,
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * @return array{value: int|null, available: bool, definition?: string}
     */
    private function metric(?int $value, bool $available = true, ?string $definition = null): array
    {
        $metric = [
            'value' => $available ? $value : null,
            'available' => $available,
        ];
        if ($definition !== null) {
            $metric['definition'] = $definition;
        }

        return $metric;
    }
}
