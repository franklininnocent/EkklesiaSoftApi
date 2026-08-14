<?php

namespace Modules\RolesAndPermissions\Services;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class TenantRoleService
{
    public function __construct(private PermissionAuditService $auditService)
    {
    }

    public function createTenantRole(User $actor, array $payload): Role
    {
        $this->ensureTenantActor($actor);

        $exists = Role::where('tenant_id', $actor->tenant_id)
            ->where('name', $payload['name'])
            ->exists();
        if ($exists) {
            throw new \RuntimeException('Role name must be unique within the tenant.', 422);
        }

        $role = Role::create([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'level' => $payload['level'],
            'tenant_id' => $actor->tenant_id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_CUSTOM,
            'active' => $payload['active'] ?? 1,
        ]);

        $this->auditService->logRoleCreated($role, $actor);

        return $role;
    }

    public function updateTenantRole(User $actor, Role $role, array $payload): Role
    {
        $this->assertTenantOwnedRole($actor, $role);

        if ($role->isTenantAdministratorRole() && isset($payload['name']) && $payload['name'] !== $role->name) {
            throw new \RuntimeException('Church Administrator role name cannot be changed.', 422);
        }

        if ($role->isProtectedSystemRole()) {
            $allowedKeys = ['description'];
            $attemptedKeys = array_keys($payload);
            $disallowed = array_values(array_diff($attemptedKeys, $allowedKeys));

            if (!empty($disallowed)) {
                throw new \RuntimeException('Protected tenant roles cannot be modified.', 403);
            }

            // Description-only updates are allowed for parish clarity (name/level locked).
            if (!array_key_exists('description', $payload)) {
                return $role;
            }
        }

        if (isset($payload['active']) && (int) $payload['active'] === 0 && $role->isTenantAdministratorRole()) {
            throw new \RuntimeException('Church Administrator role cannot be deactivated.', 422);
        }

        $role->update($payload);
        $role->clearUsersPermissionCache();
        $this->auditService->logRoleUpdated($role, $payload, $actor);

        return $role;
    }

    public function deleteTenantRole(User $actor, Role $role): void
    {
        $this->assertTenantOwnedRole($actor, $role);

        if ($role->isTenantAdministratorRole()) {
            throw new \RuntimeException('Church Administrator role cannot be deleted.', 422);
        }

        if ($role->isProtectedSystemRole()) {
            throw new \RuntimeException('Protected tenant roles cannot be deleted.', 403);
        }

        if ($role->assignedUsersCount() > 0) {
            throw new \RuntimeException('Cannot delete role with assigned users. Reassign users first.', 422);
        }

        $role->clearUsersPermissionCache();
        $this->auditService->logRoleDeleted($role, $actor);
        $role->delete();
    }

    public function activateTenantRole(User $actor, Role $role): Role
    {
        $this->assertTenantOwnedRole($actor, $role);

        if ($role->isProtectedSystemRole() && !$role->isTenantAdministratorRole()) {
            throw new \RuntimeException('Protected tenant roles cannot be activated.', 403);
        }

        $role->active = 1;
        $role->save();
        $role->clearUsersPermissionCache();
        $this->auditService->logRoleUpdated($role, ['active' => 1], $actor);

        return $role;
    }

    public function deactivateTenantRole(User $actor, Role $role): Role
    {
        $this->assertTenantOwnedRole($actor, $role);

        if ($role->isTenantAdministratorRole()) {
            throw new \RuntimeException('Church Administrator role cannot be deactivated.', 422);
        }

        if ($role->isProtectedSystemRole()) {
            throw new \RuntimeException('Protected tenant roles cannot be deactivated.', 403);
        }

        $role->active = 0;
        $role->save();
        $role->clearUsersPermissionCache();
        $this->auditService->logRoleUpdated($role, ['active' => 0], $actor);

        return $role;
    }

    private function ensureTenantActor(User $actor): void
    {
        if (!$actor->tenant_id) {
            throw new \RuntimeException('Tenant context required.', 403);
        }
    }

    private function assertTenantOwnedRole(User $actor, Role $role): void
    {
        $this->ensureTenantActor($actor);
        if ($role->tenant_id !== $actor->tenant_id || !$role->isTenantRole()) {
            throw new \RuntimeException('Role does not belong to your tenant.', 403);
        }
    }
}
