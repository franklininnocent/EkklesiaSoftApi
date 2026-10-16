<?php

namespace Modules\RolesAndPermissions\Policies;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;

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

        $effective = EffectiveTenant::id($user);
        if ($effective === null) {
            return false;
        }

        return $role->tenant_id === $effective || $role->isGlobal();
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

        $effective = EffectiveTenant::id($user);
        if ($effective === null) {
            return false;
        }

        return $role->tenant_id === $effective && $user->hasPermission('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $effective = EffectiveTenant::id($user);
        if ($effective === null) {
            return false;
        }

        return $role->tenant_id === $effective && $user->hasPermission('roles.delete');
    }
}
