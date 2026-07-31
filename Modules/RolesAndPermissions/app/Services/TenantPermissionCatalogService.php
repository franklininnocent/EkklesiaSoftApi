<?php

namespace Modules\RolesAndPermissions\Services;

use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;

class TenantPermissionCatalogService
{
    public function listForTenant(User $actor, bool $grouped = true)
    {
        if (!$actor->tenant_id) {
            throw new \RuntimeException('Tenant context required.', 403);
        }

        $permissions = Permission::query()
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->whereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH])
            ->where(function ($query) use ($actor) {
                $query->where(function ($subQuery) {
                    $subQuery->whereNull('tenant_id')->where('is_custom', false);
                })->orWhere(function ($subQuery) use ($actor) {
                    $subQuery->where('tenant_id', $actor->tenant_id)->where('is_custom', true);
                });
            })
            ->orderBy('module')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        if (!$grouped) {
            return $permissions;
        }

        return $permissions->groupBy('module')->map(function ($items, $module) {
            return [
                'module' => $module,
                'permissions' => $items->values(),
            ];
        })->values();
    }
}
