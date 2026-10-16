<?php

namespace Modules\RolesAndPermissions\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\RolesAndPermissions\Models\Permission;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('permissions.view') || $user->isTenantAdmin();
    }

    public function view(User $user, Permission $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $effective = EffectiveTenant::id($user);
        if ($effective === null) {
            return false;
        }

        if ($permission->scope === Permission::SCOPE_PLATFORM) {
            return false;
        }

        return is_null($permission->tenant_id) || $permission->tenant_id === $effective;
    }

    public function assign(User $user): bool
    {
        return $user->hasPermission('permissions.assign') || $user->isTenantAdmin();
    }
}
