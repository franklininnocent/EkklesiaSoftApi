<?php

namespace Modules\Family\app\Support;

use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;

final class FamilyProfileImageAuthorization
{
    public static function canView(Family $family, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        if ((int) $viewer->tenant_id !== (int) $family->tenant_id) {
            return false;
        }

        return $viewer->hasPermission('families.view')
            || $viewer->isTenantAdmin();
    }
}
