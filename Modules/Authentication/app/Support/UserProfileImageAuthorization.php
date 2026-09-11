<?php

namespace Modules\Authentication\Support;

use Modules\Authentication\Models\User;

final class UserProfileImageAuthorization
{
    public static function canView(User $subject, ?User $viewer): bool
    {
        if ($subject->profile_image_path === null || $subject->profile_image_path === '' || ! $subject->tenant_id) {
            return false;
        }

        if ($viewer === null) {
            return false;
        }

        if ($viewer->id === $subject->id) {
            return true;
        }

        if ($viewer->isSuperAdmin() || $viewer->isEkklesiaAdmin()) {
            return true;
        }

        if ($viewer->tenant_id !== $subject->tenant_id) {
            return false;
        }

        return $viewer->hasPermission('users.view')
            || $viewer->hasPermission('users.update')
            || $viewer->isTenantAdmin();
    }
}
