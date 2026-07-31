<?php

namespace Modules\RolesAndPermissions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;

class TenantPermissionService
{
    private const REQUIRED_ADMIN_PERMISSION_NAMES = [
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'permissions.assign',
        'roles.assign',
        'users.view',
        'users.update',
    ];

    public function __construct(private PermissionAuditService $auditService)
    {
    }

    public function syncRolePermissions(User $actor, Role $role, array $permissionIds): int
    {
        $this->assertTenantOwnedRole($actor, $role);

        $permissions = Permission::whereIn('id', $permissionIds)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->get();

        if ($permissions->count() !== count($permissionIds)) {
            throw new \RuntimeException('One or more permissions are invalid.', 422);
        }

        foreach ($permissions as $permission) {
            if (!$permission->isTenantAssignable()) {
                throw new \RuntimeException("Permission '{$permission->name}' is platform-only and cannot be assigned in tenant scope.", 422);
            }

            if (!is_null($permission->tenant_id) && $permission->tenant_id !== $actor->tenant_id) {
                throw new \RuntimeException("Permission '{$permission->name}' does not belong to your tenant.", 422);
            }
        }

        if (!$actor->isSuperAdmin()) {
            $actorPermissions = $actor->getAllPermissions()->pluck('name')->toArray();
            foreach ($permissions as $permission) {
                if (!in_array($permission->name, $actorPermissions, true)) {
                    throw new \RuntimeException("Permission escalation blocked. You cannot assign '{$permission->name}'.", 403);
                }
            }
        }

        if ($role->isTenantAdministratorRole()) {
            $permissionNames = $permissions->pluck('name')->toArray();
            $missing = array_values(array_diff(self::REQUIRED_ADMIN_PERMISSION_NAMES, $permissionNames));
            if (!empty($missing)) {
                throw new \RuntimeException('Church Administrator role must retain critical management permissions: ' . implode(', ', $missing), 422);
            }
        }

        DB::transaction(function () use ($role, $permissionIds, $actor) {
            $role->permissions()->sync($permissionIds);
            $role->clearUsersPermissionCache();
            $this->auditService->logBulkPermissionAssignment($role, $permissionIds, $actor);
        });

        return count($permissionIds);
    }

    private function assertTenantOwnedRole(User $actor, Role $role): void
    {
        if (!$actor->tenant_id) {
            throw new \RuntimeException('Tenant context required.', 403);
        }

        if ($role->tenant_id !== $actor->tenant_id || !$role->isTenantRole()) {
            throw new \RuntimeException('Role does not belong to your tenant.', 403);
        }
    }
}
