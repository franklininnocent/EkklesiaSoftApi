<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Support\AdminMinistriesAdoptionClassifier;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Modules\Tenants\Models\Tenant;

/**
 * Shared tenant-level Ministries Insights analytics (Overview + Tenants list/detail).
 */
class AdminMinistriesTenantAnalyticsService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buildRows(AdminMinistriesWindow $window): Collection
    {
        $tenants = Tenant::query()
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'features']);

        $orgCounts = $this->countByTenant(
            Organization::query()->whereNull('deleted_at'),
            'organizations_count'
        );
        $memberCounts = $this->countByTenant(
            OrganizationMembership::query()
                ->whereNull('deleted_at')
                ->where('is_current', true)
                ->where('status', OrganizationMembership::STATUS_ACTIVE),
            'members_count'
        );
        $leaderCounts = $this->countByTenant(
            LeadershipTerm::query()
                ->whereNull('deleted_at')
                ->where('status', LeadershipTerm::STATUS_ACTIVE),
            'leaders_count'
        );

        $currentEvents = $this->eventCountsByTenant($window->currentStart, $window->currentEnd);
        $priorEvents = $this->eventCountsByTenant($window->priorStart, $window->priorEnd);
        $categoryCounts = $this->featureCategoryCountsByTenant($window->currentStart, $window->currentEnd);
        $activeUsers = $this->activeActorsByTenant($window->currentStart, $window->currentEnd);
        $lastActivity = $this->lastActivityByTenant();
        $activationDates = $this->activationDatesByTenant();

        $healthByTenant = $this->healthByTenant();

        return $tenants->map(function (Tenant $tenant) use (
            $orgCounts,
            $memberCounts,
            $leaderCounts,
            $currentEvents,
            $priorEvents,
            $categoryCounts,
            $activeUsers,
            $lastActivity,
            $activationDates,
            $healthByTenant,
        ) {
            $tenantId = (int) $tenant->id;
            $enabled = $tenant->supportsMinistriesAssociations();
            $orgCount = (int) ($orgCounts[$tenantId] ?? 0);
            $current = (int) ($currentEvents[$tenantId] ?? 0);
            $prior = (int) ($priorEvents[$tenantId] ?? 0);
            $categoriesUsed = (int) ($categoryCounts[$tenantId] ?? 0);

            $flags = AdminMinistriesAdoptionClassifier::classify([
                'module_enabled' => $enabled,
                'organizations_count' => $orgCount,
                'current_events' => $current,
                'prior_events' => $prior,
                'feature_categories_used' => $categoriesUsed,
            ]);

            $health = $healthByTenant[$tenantId] ?? [
                'orgs_without_active_members' => 0,
                'active_orgs_without_leadership' => 0,
                'stale_active_orgs' => 0,
            ];

            return array_merge([
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
                'organizations_count' => $orgCount,
                'members_count' => (int) ($memberCounts[$tenantId] ?? 0),
                'leaders_count' => (int) ($leaderCounts[$tenantId] ?? 0),
                'active_users_count' => (int) ($activeUsers[$tenantId] ?? 0),
                'current_events' => $current,
                'prior_events' => $prior,
                'feature_categories_used' => $categoriesUsed,
                'usage_trend' => $this->trend($current, $prior),
                'last_activity_at' => $lastActivity[$tenantId] ?? null,
                'activation_date' => $activationDates[$tenantId] ?? null,
                'health' => $health,
                'health_flag' => $this->healthFlag($flags, $health),
            ], $flags);
        })->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function filterRows(Collection $rows, array $filters): Collection
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $moduleStatus = $filters['module_status'] ?? null;
        $adoptionStatus = $filters['adoption_status'] ?? null;
        $usageStatus = $filters['usage_status'] ?? null;
        $healthFlag = $filters['health'] ?? ($filters['health_flag'] ?? null);
        $lastFrom = $filters['last_activity_from'] ?? null;
        $lastTo = $filters['last_activity_to'] ?? null;

        return $rows->filter(function (array $row) use (
            $search,
            $moduleStatus,
            $adoptionStatus,
            $usageStatus,
            $healthFlag,
            $lastFrom,
            $lastTo,
        ) {
            if ($search !== '') {
                $hay = mb_strtolower($row['tenant_name'].' '.$row['tenant_slug']);
                if (! str_contains($hay, mb_strtolower($search))) {
                    return false;
                }
            }

            if ($moduleStatus !== null && $moduleStatus !== '' && $row['module_status'] !== $moduleStatus) {
                return false;
            }

            if ($adoptionStatus !== null && $adoptionStatus !== '') {
                if (! $this->matchesAdoptionFilter($row, (string) $adoptionStatus)) {
                    return false;
                }
            }

            if ($usageStatus !== null && $usageStatus !== '') {
                if (($row['usage_trend'] ?? null) !== $usageStatus) {
                    return false;
                }
            }

            if ($healthFlag !== null && $healthFlag !== '') {
                if (! $this->matchesHealthFilter($row, (string) $healthFlag)) {
                    return false;
                }
            }

            if ($lastFrom || $lastTo) {
                $last = $row['last_activity_at'] ?? null;
                if ($last === null) {
                    return false;
                }
                if ($lastFrom && strcmp((string) $last, (string) $lastFrom) < 0) {
                    return false;
                }
                if ($lastTo && strcmp((string) $last, (string) $lastTo) > 0) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function sortRows(Collection $rows, ?string $sort, string $direction = 'asc'): Collection
    {
        $allowed = [
            'tenant_name',
            'module_status',
            'primary_adoption_status',
            'organizations_count',
            'members_count',
            'leaders_count',
            'active_users_count',
            'current_events',
            'last_activity_at',
            'health_flag',
            'usage_trend',
        ];
        $sort = in_array($sort, $allowed, true) ? $sort : 'tenant_name';
        $desc = strtolower($direction) === 'desc';

        return $rows->sortBy(function (array $row) use ($sort) {
            $value = $row[$sort] ?? null;
            if ($value === null) {
                return $desc ? -INF : INF;
            }

            return is_string($value) ? mb_strtolower($value) : $value;
        }, SORT_REGULAR, $desc)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(Collection $rows, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = $rows->forPage($page, $perPage)->values();

        return [
            'data' => $slice->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? 0 : (($page - 1) * $perPage) + $slice->count(),
            ],
        ];
    }

    /**
     * @return Collection<int|string, int>
     */
    public function eventCountsByTenant($start, $end): Collection
    {
        return MinistriesAuditLog::query()
            ->selectRaw('tenant_id, COUNT(*) as event_count')
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->groupBy('tenant_id')
            ->pluck('event_count', 'tenant_id');
    }

    /**
     * @return array<int, int>
     */
    public function featureCategoryCountsByTenant($start, $end): array
    {
        $rows = MinistriesAuditLog::query()
            ->select(['tenant_id', 'event'])
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->distinct()
            ->get();

        $byTenant = [];
        foreach ($rows->groupBy('tenant_id') as $tenantId => $events) {
            $categories = [];
            foreach ($events as $row) {
                $category = AdminMinistriesAdoptionClassifier::featureCategoryForEvent((string) $row->event);
                if ($category !== null) {
                    $categories[$category] = true;
                }
            }
            $byTenant[(int) $tenantId] = count($categories);
        }

        return $byTenant;
    }

    /**
     * @return array<string, array{used: bool, event_count: int}>
     */
    public function featureAdoptionForTenant(int $tenantId, AdminMinistriesWindow $window): array
    {
        $counts = MinistriesAuditLog::query()
            ->selectRaw('event, COUNT(*) as event_count')
            ->where('tenant_id', $tenantId)
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->groupBy('event')
            ->pluck('event_count', 'event');

        $orgExists = Organization::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
        $memberExists = OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->exists();
        $leaderExists = LeadershipTerm::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->exists();
        $guestExists = DB::table('ma_guest_members')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();

        $result = [];
        foreach (AdminMinistriesDefinitions::FEATURE_EVENT_MAP as $category => $events) {
            $eventCount = 0;
            foreach ($events as $event) {
                $eventCount += (int) ($counts[$event] ?? 0);
            }

            $entityEvidence = match ($category) {
                'organizations' => $orgExists,
                'members' => $memberExists,
                'leadership' => $leaderExists,
                'guests' => $guestExists,
                default => false,
            };

            $result[$category] = [
                'used' => $eventCount > 0 || $entityEvidence,
                'event_count' => $eventCount,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivityForTenant(int $tenantId, int $limit = 25): array
    {
        return MinistriesAuditLog::query()
            ->with(['actor:id,name'])
            ->where('tenant_id', $tenantId)
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->orderByDesc('created_at')
            ->limit($limit)
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
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * Active days with at least one meaningful action in the window.
     */
    public function activeDaysForTenant(int $tenantId, AdminMinistriesWindow $window): int
    {
        return (int) MinistriesAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as active_days')
            ->value('active_days');
    }

    /**
     * @return array{orgs_without_active_members: int, active_orgs_without_leadership: int, stale_active_orgs: int}
     */
    public function healthForTenant(int $tenantId): array
    {
        return $this->healthByTenant()[$tenantId] ?? [
            'orgs_without_active_members' => 0,
            'active_orgs_without_leadership' => 0,
            'stale_active_orgs' => 0,
        ];
    }

    public function trend(int $current, int $prior): string
    {
        if ($prior === 0) {
            return $current > 0 ? 'up' : 'flat';
        }

        $ratio = $current / $prior;
        if ($ratio >= 1.1) {
            return 'up';
        }
        if ($ratio <= 0.9) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * @param  array<string, mixed>  $flags
     * @param  array{orgs_without_active_members: int, active_orgs_without_leadership: int, stale_active_orgs: int}  $health
     */
    public function healthFlag(array $flags, array $health): string
    {
        if (($flags['module_status'] ?? null) === 'disabled') {
            return 'ok';
        }

        if ($health['active_orgs_without_leadership'] > 0) {
            return 'critical';
        }

        if (
            ($flags['inactive'] ?? false)
            || ($flags['declining'] ?? false)
            || ($flags['not_started'] ?? false)
            || $health['orgs_without_active_members'] > 0
            || $health['stale_active_orgs'] > 0
        ) {
            return 'attention';
        }

        return 'ok';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Support\Collection<int|string, int>
     */
    private function countByTenant($query, string $alias): Collection
    {
        return $query
            ->selectRaw("tenant_id, COUNT(*) as {$alias}")
            ->groupBy('tenant_id')
            ->pluck($alias, 'tenant_id');
    }

    /**
     * @return array<int, int>
     */
    private function activeActorsByTenant($start, $end): array
    {
        return MinistriesAuditLog::query()
            ->selectRaw('tenant_id, COUNT(DISTINCT actor_user_id) as actors')
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->whereNotNull('actor_user_id')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->groupBy('tenant_id')
            ->pluck('actors', 'tenant_id')
            ->map(static fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<int, string|null>
     */
    private function lastActivityByTenant(): array
    {
        return MinistriesAuditLog::query()
            ->selectRaw('tenant_id, MAX(created_at) as last_activity_at')
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->groupBy('tenant_id')
            ->pluck('last_activity_at', 'tenant_id')
            ->map(static function ($value) {
                if ($value === null) {
                    return null;
                }

                return \Carbon\Carbon::parse($value)->utc()->toIso8601String();
            })
            ->all();
    }

    /**
     * @return array<int, string|null>
     */
    private function activationDatesByTenant(): array
    {
        return Organization::query()
            ->selectRaw('tenant_id, MIN(created_at) as activation_date')
            ->whereNull('deleted_at')
            ->groupBy('tenant_id')
            ->pluck('activation_date', 'tenant_id')
            ->map(static function ($value) {
                if ($value === null) {
                    return null;
                }

                return \Carbon\Carbon::parse($value)->utc()->toIso8601String();
            })
            ->all();
    }

    /**
     * @return array<int, array{orgs_without_active_members: int, active_orgs_without_leadership: int, stale_active_orgs: int}>
     */
    private function healthByTenant(): array
    {
        $withoutMembers = Organization::query()
            ->selectRaw('ma_organizations.tenant_id, COUNT(*) as c')
            ->whereNull('ma_organizations.deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('ma_memberships')
                    ->whereColumn('ma_memberships.organization_id', 'ma_organizations.id')
                    ->where('ma_memberships.is_current', true)
                    ->where('ma_memberships.status', OrganizationMembership::STATUS_ACTIVE)
                    ->whereNull('ma_memberships.deleted_at');
            })
            ->groupBy('ma_organizations.tenant_id')
            ->pluck('c', 'tenant_id');

        $withoutLeadership = Organization::query()
            ->selectRaw('ma_organizations.tenant_id, COUNT(*) as c')
            ->whereNull('ma_organizations.deleted_at')
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('ma_leadership_terms')
                    ->whereColumn('ma_leadership_terms.organization_id', 'ma_organizations.id')
                    ->where('ma_leadership_terms.status', LeadershipTerm::STATUS_ACTIVE)
                    ->whereNull('ma_leadership_terms.deleted_at');
            })
            ->groupBy('ma_organizations.tenant_id')
            ->pluck('c', 'tenant_id');

        $staleCutoff = now('UTC')->subDays(AdminMinistriesDefinitions::STALE_ORG_DAYS);
        $stale = Organization::query()
            ->selectRaw('ma_organizations.tenant_id, COUNT(*) as c')
            ->whereNull('ma_organizations.deleted_at')
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->where('ma_organizations.updated_at', '<', $staleCutoff)
            ->whereNotExists(function ($query) use ($staleCutoff): void {
                $query->select(DB::raw(1))
                    ->from('ma_audit_logs')
                    ->whereColumn('ma_audit_logs.organization_id', 'ma_organizations.id')
                    ->whereIn('ma_audit_logs.event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
                    ->where('ma_audit_logs.created_at', '>=', $staleCutoff);
            })
            ->groupBy('ma_organizations.tenant_id')
            ->pluck('c', 'tenant_id');

        $tenantIds = collect($withoutMembers->keys())
            ->merge($withoutLeadership->keys())
            ->merge($stale->keys())
            ->unique();

        $result = [];
        foreach ($tenantIds as $tenantId) {
            $result[(int) $tenantId] = [
                'orgs_without_active_members' => (int) ($withoutMembers[$tenantId] ?? 0),
                'active_orgs_without_leadership' => (int) ($withoutLeadership[$tenantId] ?? 0),
                'stale_active_orgs' => (int) ($stale[$tenantId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesAdoptionFilter(array $row, string $adoptionStatus): bool
    {
        return match ($adoptionStatus) {
            'disabled' => ($row['module_status'] ?? null) === 'disabled',
            'not_started' => (bool) ($row['not_started'] ?? false),
            'activated' => (bool) ($row['activated'] ?? false),
            'active' => (bool) ($row['active'] ?? false),
            'highly_engaged' => (bool) ($row['highly_engaged'] ?? false),
            'inactive' => (bool) ($row['inactive'] ?? false),
            'declining' => (bool) ($row['declining'] ?? false),
            default => ($row['primary_adoption_status'] ?? null) === $adoptionStatus,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesHealthFilter(array $row, string $health): bool
    {
        $h = $row['health'] ?? [];

        return match ($health) {
            'ok', 'attention', 'critical' => ($row['health_flag'] ?? null) === $health,
            'without_members' => ((int) ($h['orgs_without_active_members'] ?? 0)) > 0,
            'without_leadership' => ((int) ($h['active_orgs_without_leadership'] ?? 0)) > 0,
            'stale' => ((int) ($h['stale_active_orgs'] ?? 0)) > 0,
            default => false,
        };
    }
}
