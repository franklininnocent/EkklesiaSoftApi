<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Tenants\Models\Tenant;

class TenantHierarchyService
{
    public const TIER_DIOCESE = 'diocese';
    public const TIER_PARISH = 'parish';
    public const TIER_BRANCH = 'branch';

    public function supportsHierarchy(): bool
    {
        return Schema::hasColumn('tenants', 'parent_tenant_id')
            && Schema::hasColumn('tenants', 'tenant_tier')
            && Schema::hasColumn('tenants', 'hierarchy_path');
    }

    public function assignHierarchy(Tenant $tenant, ?Tenant $parent = null): Tenant
    {
        if (!$this->supportsHierarchy()) {
            return $tenant;
        }

        $tier = $tenant->tenant_tier ?: self::TIER_PARISH;

        if ($parent) {
            $tenant->parent_tenant_id = $parent->id;
            $tenant->tenant_tier = $tier;
            $tenant->hierarchy_path = trim($parent->hierarchy_path . '.' . $tenant->id, '.');
        } else {
            $tenant->parent_tenant_id = null;
            $tenant->tenant_tier = $tier;
            $tenant->hierarchy_path = (string) $tenant->id;
        }

        $tenant->save();

        return $tenant->refresh();
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function descendants(int $tenantId, bool $includeSelf = false): Collection
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        if (!$this->supportsHierarchy()) {
            return $includeSelf ? collect([$tenant]) : collect();
        }

        $prefix = $tenant->hierarchy_path ?: (string) $tenantId;

        $query = Tenant::query()->where(function ($builder) use ($prefix, $includeSelf, $tenantId): void {
            if ($includeSelf) {
                $builder->where('id', $tenantId)
                    ->orWhere('hierarchy_path', 'like', $prefix . '.%');
            } else {
                $builder->where('hierarchy_path', 'like', $prefix . '.%');
            }
        });

        return $query->get();
    }

    /**
     * @return array<int>
     */
    public function descendantIds(int $tenantId, bool $includeSelf = true): array
    {
        return $this->descendants($tenantId, $includeSelf)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function canAccessTenant(int $actorTenantId, int $targetTenantId): bool
    {
        if ($actorTenantId === $targetTenantId) {
            return true;
        }

        if (!$this->supportsHierarchy()) {
            return false;
        }

        $actor = Tenant::query()->find($actorTenantId);
        $target = Tenant::query()->find($targetTenantId);

        if (!$actor || !$target || !$target->hierarchy_path || !$actor->hierarchy_path) {
            return false;
        }

        return str_starts_with($target->hierarchy_path . '.', $actor->hierarchy_path . '.')
            || $target->hierarchy_path === $actor->hierarchy_path;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeNode(Tenant $tenant): array
    {
        $childCount = 0;
        if ($this->supportsHierarchy()) {
            $childCount = Tenant::query()
                ->where('parent_tenant_id', $tenant->id)
                ->count();
        }

        return [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'tier' => $this->supportsHierarchy() ? ($tenant->tenant_tier ?? self::TIER_PARISH) : self::TIER_PARISH,
            'hierarchy_path' => $this->supportsHierarchy() ? $tenant->hierarchy_path : (string) $tenant->id,
            'parent_tenant_id' => $this->supportsHierarchy() ? $tenant->parent_tenant_id : null,
            'child_count' => $childCount,
            'currency_code' => ($this->supportsHierarchy() ? $tenant->currency_code : null) ?? 'INR',
        ];
    }
}
