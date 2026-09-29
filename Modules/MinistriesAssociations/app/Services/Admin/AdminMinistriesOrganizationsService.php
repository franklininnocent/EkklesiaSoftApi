<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;

class AdminMinistriesOrganizationsService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        /** @var LengthAwarePaginator<int, Organization> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())
            ->map(fn (Organization $org): array => $this->transform($org))
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
        ];
    }

    /**
     * @return Builder<Organization>
     */
    private function baseQuery(): Builder
    {
        $meaningful = AdminMinistriesDefinitions::MEANINGFUL_EVENTS;
        $eventList = collect($meaningful)
            ->map(static fn (string $event): string => "'".str_replace("'", "''", $event)."'")
            ->implode(',');

        return Organization::query()
            ->from('ma_organizations')
            ->whereNull('ma_organizations.deleted_at')
            ->with([
                'tenant:id,name,slug',
                'category:id,name,code',
                'type:id,name,code',
            ])
            ->select('ma_organizations.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM ma_memberships m
                  WHERE m.organization_id = ma_organizations.id
                    AND m.is_current = true
                    AND m.status = ?
                    AND m.deleted_at IS NULL) as members_count',
                [OrganizationMembership::STATUS_ACTIVE]
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM ma_leadership_terms lt
                  WHERE lt.organization_id = ma_organizations.id
                    AND lt.status = ?
                    AND lt.deleted_at IS NULL) as leaders_count',
                [LeadershipTerm::STATUS_ACTIVE]
            )
            ->selectRaw(
                "(SELECT MAX(a.created_at) FROM ma_audit_logs a
                  WHERE a.organization_id = ma_organizations.id
                    AND a.event IN ({$eventList})) as last_activity_at"
            );
    }

    /**
     * @param  Builder<Organization>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('ma_organizations.name', 'like', $like)
                    ->orWhere('ma_organizations.code', 'like', $like)
                    ->orWhereHas('tenant', function (Builder $tenant) use ($like): void {
                        $tenant->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like);
                    });
            });
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('ma_organizations.tenant_id', (int) $filters['tenant_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('ma_organizations.status', (string) $filters['status']);
        }

        if (! empty($filters['type_id'])) {
            $query->where('ma_organizations.type_id', (string) $filters['type_id']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('ma_organizations.category_id', (string) $filters['category_id']);
        }

        $health = $filters['health'] ?? null;
        if ($health) {
            $this->applyHealthFilter($query, (string) $health);
        }
    }

    /**
     * @param  Builder<Organization>  $query
     */
    private function applyHealthFilter(Builder $query, string $health): void
    {
        $staleCutoff = now('UTC')->subDays(AdminMinistriesDefinitions::STALE_ORG_DAYS);
        $meaningful = AdminMinistriesDefinitions::MEANINGFUL_EVENTS;

        match ($health) {
            'without_members' => $query->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('ma_memberships')
                    ->whereColumn('ma_memberships.organization_id', 'ma_organizations.id')
                    ->where('ma_memberships.is_current', true)
                    ->where('ma_memberships.status', OrganizationMembership::STATUS_ACTIVE)
                    ->whereNull('ma_memberships.deleted_at');
            }),
            'without_leadership' => $query
                ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
                ->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('ma_leadership_terms')
                        ->whereColumn('ma_leadership_terms.organization_id', 'ma_organizations.id')
                        ->where('ma_leadership_terms.status', LeadershipTerm::STATUS_ACTIVE)
                        ->whereNull('ma_leadership_terms.deleted_at');
                }),
            'stale' => $query
                ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
                ->where('ma_organizations.updated_at', '<', $staleCutoff)
                ->whereNotExists(function ($sub) use ($staleCutoff, $meaningful): void {
                    $sub->select(DB::raw(1))
                        ->from('ma_audit_logs')
                        ->whereColumn('ma_audit_logs.organization_id', 'ma_organizations.id')
                        ->whereIn('ma_audit_logs.event', $meaningful)
                        ->where('ma_audit_logs.created_at', '>=', $staleCutoff);
                }),
            'critical' => $query
                ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
                ->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('ma_leadership_terms')
                        ->whereColumn('ma_leadership_terms.organization_id', 'ma_organizations.id')
                        ->where('ma_leadership_terms.status', LeadershipTerm::STATUS_ACTIVE)
                        ->whereNull('ma_leadership_terms.deleted_at');
                }),
            'attention' => $query->where(function (Builder $outer) use ($staleCutoff, $meaningful): void {
                $outer->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('ma_memberships')
                        ->whereColumn('ma_memberships.organization_id', 'ma_organizations.id')
                        ->where('ma_memberships.is_current', true)
                        ->where('ma_memberships.status', OrganizationMembership::STATUS_ACTIVE)
                        ->whereNull('ma_memberships.deleted_at');
                })->orWhere(function (Builder $stale) use ($staleCutoff, $meaningful): void {
                    $stale->where('ma_organizations.status', Organization::STATUS_ACTIVE)
                        ->where('ma_organizations.updated_at', '<', $staleCutoff)
                        ->whereNotExists(function ($sub) use ($staleCutoff, $meaningful): void {
                            $sub->select(DB::raw(1))
                                ->from('ma_audit_logs')
                                ->whereColumn('ma_audit_logs.organization_id', 'ma_organizations.id')
                                ->whereIn('ma_audit_logs.event', $meaningful)
                                ->where('ma_audit_logs.created_at', '>=', $staleCutoff);
                        });
                });
            }),
            'ok' => $query
                ->whereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('ma_memberships')
                        ->whereColumn('ma_memberships.organization_id', 'ma_organizations.id')
                        ->where('ma_memberships.is_current', true)
                        ->where('ma_memberships.status', OrganizationMembership::STATUS_ACTIVE)
                        ->whereNull('ma_memberships.deleted_at');
                })
                ->where(function (Builder $ok) use ($staleCutoff, $meaningful): void {
                    $ok->where('ma_organizations.status', '!=', Organization::STATUS_ACTIVE)
                        ->orWhere(function (Builder $activeOk) use ($staleCutoff, $meaningful): void {
                            $activeOk->whereExists(function ($sub): void {
                                $sub->select(DB::raw(1))
                                    ->from('ma_leadership_terms')
                                    ->whereColumn('ma_leadership_terms.organization_id', 'ma_organizations.id')
                                    ->where('ma_leadership_terms.status', LeadershipTerm::STATUS_ACTIVE)
                                    ->whereNull('ma_leadership_terms.deleted_at');
                            })->where(function (Builder $fresh) use ($staleCutoff, $meaningful): void {
                                $fresh->where('ma_organizations.updated_at', '>=', $staleCutoff)
                                    ->orWhereExists(function ($sub) use ($staleCutoff, $meaningful): void {
                                        $sub->select(DB::raw(1))
                                            ->from('ma_audit_logs')
                                            ->whereColumn('ma_audit_logs.organization_id', 'ma_organizations.id')
                                            ->whereIn('ma_audit_logs.event', $meaningful)
                                            ->where('ma_audit_logs.created_at', '>=', $staleCutoff);
                                    });
                            });
                        });
                }),
            default => null,
        };
    }

    /**
     * @param  Builder<Organization>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? 'name');
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sort === 'tenant_name') {
            $query->orderByRaw(
                '(SELECT name FROM tenants WHERE tenants.id = ma_organizations.tenant_id) '.$direction
            )->orderBy('ma_organizations.id');

            return;
        }

        $map = [
            'name' => 'ma_organizations.name',
            'status' => 'ma_organizations.status',
            'created_at' => 'ma_organizations.created_at',
            'updated_at' => 'ma_organizations.updated_at',
            'members_count' => 'members_count',
            'leaders_count' => 'leaders_count',
            'last_activity_at' => 'last_activity_at',
        ];

        $column = $map[$sort] ?? 'ma_organizations.name';
        $query->orderBy($column, $direction)->orderBy('ma_organizations.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Organization $org): array
    {
        $members = (int) ($org->members_count ?? 0);
        $leaders = (int) ($org->leaders_count ?? 0);
        $lastActivity = $org->last_activity_at
            ? \Carbon\Carbon::parse($org->last_activity_at)->utc()->toIso8601String()
            : null;

        $indicators = $this->orgIndicators($org, $members, $leaders, $lastActivity);

        return [
            'id' => $org->id,
            'name' => $org->name,
            'code' => $org->code,
            'status' => $org->status,
            'tenant' => $org->tenant ? [
                'id' => $org->tenant->id,
                'name' => $org->tenant->name,
                'slug' => $org->tenant->slug,
            ] : null,
            'category' => $org->category ? [
                'id' => $org->category->id,
                'name' => $org->category->name,
                'code' => $org->category->code,
            ] : null,
            'type' => $org->type ? [
                'id' => $org->type->id,
                'name' => $org->type->name,
                'code' => $org->type->code,
            ] : null,
            'members_count' => $members,
            'leaders_count' => $leaders,
            'created_at' => optional($org->created_at)?->toIso8601String(),
            'updated_at' => optional($org->updated_at)?->toIso8601String(),
            'last_activity_at' => $lastActivity,
            'health_flag' => $indicators['health_flag'],
            'health' => [
                'without_members' => $indicators['without_members'],
                'without_leadership' => $indicators['without_leadership'],
                'stale' => $indicators['stale'],
            ],
        ];
    }

    /**
     * @return array{without_members: bool, without_leadership: bool, stale: bool, health_flag: string}
     */
    private function orgIndicators(Organization $org, int $members, int $leaders, ?string $lastActivity): array
    {
        $staleCutoff = now('UTC')->subDays(AdminMinistriesDefinitions::STALE_ORG_DAYS);
        $withoutMembers = $members === 0;
        $withoutLeadership = $org->status === Organization::STATUS_ACTIVE && $leaders === 0;
        $stale = $org->status === Organization::STATUS_ACTIVE
            && $org->updated_at !== null
            && $org->updated_at->lt($staleCutoff)
            && ($lastActivity === null || \Carbon\Carbon::parse($lastActivity)->lt($staleCutoff));

        $flag = 'ok';
        if ($withoutLeadership) {
            $flag = 'critical';
        } elseif ($withoutMembers || $stale) {
            $flag = 'attention';
        }

        return [
            'without_members' => $withoutMembers,
            'without_leadership' => $withoutLeadership,
            'stale' => $stale,
            'health_flag' => $flag,
        ];
    }
}
