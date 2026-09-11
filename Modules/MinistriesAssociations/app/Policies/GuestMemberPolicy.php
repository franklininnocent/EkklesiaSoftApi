<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\GuestMember;

class GuestMemberPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function view(User $user, GuestMember $guestMember): bool
    {
        return $this->allows($user, 'ministries.view')
            && EffectiveTenant::matches($user, $guestMember->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.manage_members');
    }

    public function update(User $user, GuestMember $guestMember): bool
    {
        return $this->allows($user, 'ministries.manage_members')
            && EffectiveTenant::matches($user, $guestMember->tenant_id);
    }

    public function linkParishioner(User $user, GuestMember $guestMember): bool
    {
        return $this->update($user, $guestMember);
    }
}
