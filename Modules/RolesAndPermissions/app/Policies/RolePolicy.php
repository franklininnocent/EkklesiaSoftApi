<?php

namespace Modules\RolesAndPermissions\Policies;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view') || $user->isTenantAdmin();
    }

    public function view(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (!$user->tenant_id) {
            return false;
        }

        return $role->tenant_id === $user->tenant_id || $role->isGlobal();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create') || $user->isTenantAdmin();
    }

    public function update(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (!$user->tenant_id) {
            return false;
        }

        return $role->tenant_id === $user->tenant_id && $user->hasPermission('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (!$user->tenant_id) {
            return false;
        }

        return $role->tenant_id === $user->tenant_id && $user->hasPermission('roles.delete');
    }
}
