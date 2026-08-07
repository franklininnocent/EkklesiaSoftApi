<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;

class FamilyMinistriesPolicy
{
    public function viewAffiliations(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('ministries.view');
    }

    public function enroll(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('ministries.manage_members');
    }
}
