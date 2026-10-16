<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\Tenants\Support\EffectiveTenant;

class BccFamilyMembershipPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'bcc.view');
    }

    public function view(User $user, BccFamilyMembership $membership): bool
    {
        return $this->allows($user, 'bcc.view')
            && EffectiveTenant::matches($user, $membership->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'bcc.manage_members');
    }

    public function update(User $user, BccFamilyMembership $membership): bool
    {
        return $this->allows($user, 'bcc.manage_members')
            && EffectiveTenant::matches($user, $membership->tenant_id);
    }
}
