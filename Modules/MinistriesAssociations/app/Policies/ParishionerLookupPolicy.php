<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;

class ParishionerLookupPolicy
{
    public function lookup(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('ministries.manage_members');
    }
}
