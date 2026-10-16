<?php

namespace Modules\ApplicationAccess\Support;

use Modules\Authentication\Models\User;

/**
 * Resolves Application Access permissions without hasPermission() platform-scope gate.
 */
final class ApplicationAccessAuthorization
{
    /**
     * @return list<string>
     */
    public static function assignedPermissionNames(User $user): array
    {
        $names = $user->getAllPermissions(false)->pluck('name')->all();

        if ($user->role_id) {
            $legacyRole = $user->relationLoaded('role')
                ? $user->role
                : $user->role()->with(['permissions' => function ($query) {
                    $query->where('permissions.active', 1)->whereNull('permissions.deleted_at');
                }])->first();

            if ($legacyRole) {
                foreach ($legacyRole->permissions as $permission) {
                    if ($permission->active === 1 && $permission->deleted_at === null) {
                        $names[] = $permission->name;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    public static function hasAny(User $user, array $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($permissions === []) {
            $permissions = ['application_access.view'];
        }

        $assigned = self::assignedPermissionNames($user);

        foreach ($permissions as $permission) {
            if (in_array($permission, $assigned, true)) {
                return true;
            }
        }

        return false;
    }

    public static function missing(User $user, array $permissions): array
    {
        if ($user->isSuperAdmin()) {
            return [];
        }

        $assigned = self::assignedPermissionNames($user);
        $missing = [];

        foreach ($permissions as $permission) {
            if (! in_array($permission, $assigned, true)) {
                $missing[] = $permission;
            }
        }

        return $missing;
    }
}
