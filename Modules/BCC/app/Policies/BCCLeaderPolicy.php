<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\BCC\Models\BCCLeader;
use Modules\Tenants\Support\EffectiveTenant;

class BCCLeaderPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'bcc.view');
    }

    public function assign(User $user): bool
    {
        return $this->allows($user, 'bcc.manage_leadership');
    }

    public function terminate(User $user, BCCLeader $leader): bool
    {
        return $this->allows($user, 'bcc.manage_leadership')
            && ($leader->tenant_id === null || EffectiveTenant::matches($user, $leader->tenant_id));
    }
}
