<?php

namespace Modules\EcclesiasticalData\Policies\Concerns;

use Modules\Authentication\Models\User;

trait AuthorizesEcclesiasticalPermission
{
    /**
     * Platform ecclesiastical permissions require an Ekklesia role.
     */
    protected function allowsPlatform(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (! $user->hasEkklesiaRole()) {
            return false;
        }

        if ($user->is_primary_admin) {
            return true;
        }

        $legacy = $this->legacyPermissionName($permission);

        return $user->hasPermission($permission)
            || ($legacy !== null && $user->hasPermission($legacy));
    }

    protected function legacyPermissionName(string $permission): ?string
    {
        return match ($permission) {
            'bishops.view' => 'view_bishops',
            'bishops.create' => 'create_bishops',
            'bishops.update' => 'edit_bishops',
            'bishops.archive' => 'delete_bishops',
            'dioceses.view' => 'view_dioceses',
            'dioceses.create' => 'create_dioceses',
            'dioceses.update' => 'edit_dioceses',
            'dioceses.delete' => 'delete_dioceses',
            default => null,
        };
    }
}
