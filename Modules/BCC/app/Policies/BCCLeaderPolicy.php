<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCCLeader;
use Modules\Tenants\Support\EffectiveTenant;

class BCCLeaderPolicy
{
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

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
