<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\GuestMember;

class GuestMemberPolicy
{
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

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
