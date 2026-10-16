<?php

namespace Modules\MassIntentions\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class MassIntentionsModulePolicy
{
    use AuthorizesTenantPermission;

    public function viewStatus(User $user): bool
    {
        return $this->allows($user, 'mass.intentions.view');
    }
}
