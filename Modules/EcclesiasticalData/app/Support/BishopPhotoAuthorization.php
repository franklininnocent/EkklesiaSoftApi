<?php

namespace Modules\EcclesiasticalData\Support;

use Modules\Authentication\Models\User;

final class BishopPhotoAuthorization
{
    public static function canView(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        if ($viewer->isTenantAdmin() || ($viewer->is_primary_admin ?? false)) {
            return true;
        }

        foreach (['bishops.view', 'bishops.view_own_requests', 'bishops.submit_update_request', 'church.settings.view'] as $permission) {
            if ($viewer->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
