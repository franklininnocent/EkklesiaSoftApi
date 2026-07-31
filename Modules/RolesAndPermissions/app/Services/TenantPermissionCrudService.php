<?php

namespace Modules\RolesAndPermissions\Services;

use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;

class TenantPermissionCrudService
{
    public function createTenantPermission(User $actor, array $payload): Permission
    {
        $this->ensureTenantActor($actor);

        $exists = Permission::where('name', $payload['name'])->exists();
        if ($exists) {
            throw new \RuntimeException('Permission name already exists.', 422);
        }

        return Permission::create([
            'name' => $payload['name'],
            'display_name' => $payload['display_name'] ?? $payload['name'],
            'description' => $payload['description'] ?? null,
            'module' => $payload['module'] ?? 'TenantCustom',
            'scope' => Permission::SCOPE_TENANT,
            'category' => $payload['category'] ?? 'custom',
            'tenant_id' => $actor->tenant_id,
            'is_custom' => true,
            'active' => $payload['active'] ?? 1,
        ]);
    }

    public function updateTenantPermission(User $actor, Permission $permission, array $payload): Permission
    {
        $this->assertTenantOwnedPermission($actor, $permission);

        if (!$permission->isCustom()) {
            throw new \RuntimeException('System permissions cannot be modified. Create a custom permission instead.', 403);
        }

        $permission->update($payload);

        return $permission;
    }

    public function deleteTenantPermission(User $actor, Permission $permission): void
    {
        $this->assertTenantOwnedPermission($actor, $permission);

        if (!$permission->isCustom()) {
            throw new \RuntimeException('System permissions cannot be deleted.', 403);
        }

        $permission->delete();
    }

    private function ensureTenantActor(User $actor): void
    {
        if (!$actor->tenant_id) {
            throw new \RuntimeException('Tenant context required.', 403);
        }
    }

    private function assertTenantOwnedPermission(User $actor, Permission $permission): void
    {
        $this->ensureTenantActor($actor);

        if ($permission->tenant_id !== $actor->tenant_id) {
            throw new \RuntimeException('Permission does not belong to your tenant.', 403);
        }
    }
}
