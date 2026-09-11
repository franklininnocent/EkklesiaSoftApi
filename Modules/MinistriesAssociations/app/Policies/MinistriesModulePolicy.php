<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class MinistriesModulePolicy
{
    use AuthorizesTenantPermission;

    public function viewStatus(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }
}
