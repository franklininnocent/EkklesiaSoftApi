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

        return $viewer->hasPermission('bishops.view');
    }
}
