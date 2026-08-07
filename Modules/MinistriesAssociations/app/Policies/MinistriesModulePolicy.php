<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;

class MinistriesModulePolicy
{
    public function viewStatus(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('ministries.view');
    }
}
