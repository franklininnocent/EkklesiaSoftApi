<?php

namespace Modules\RolesAndPermissions\Policies;

use Modules\Authentication\Models\User;
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

        if (!$user->tenant_id) {
            return false;
        }

        if ($permission->scope === Permission::SCOPE_PLATFORM) {
            return false;
        }

        return is_null($permission->tenant_id) || $permission->tenant_id === $user->tenant_id;
    }

    public function assign(User $user): bool
    {
        return $user->hasPermission('permissions.assign') || $user->isTenantAdmin();
    }
}
