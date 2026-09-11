<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\User;

final class ChurchMediaAuthorization
{
    public static function canViewTenantMedia(int $tenantId, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        if ((int) $viewer->tenant_id !== $tenantId) {
            return false;
        }

        return $viewer->hasPermission('church.settings.view')
            || $viewer->hasPermission('church.settings.edit')
            || $viewer->isTenantAdmin();
    }

    public static function canViewPopeMedia(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        return $viewer->hasPermission('pope.manage_pope_details')
            || $viewer->hasPermission('manage_pope_details');
    }
}
