<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class OrganizationMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function view(User $user, OrganizationMembership $membership): bool
    {
        return $this->allows($user, 'ministries.view')
            && EffectiveTenant::matches($user, $membership->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.manage_members');
    }

    public function updateStatus(User $user, OrganizationMembership $membership): bool
    {
        return $this->allows($user, 'ministries.manage_members')
            && EffectiveTenant::matches($user, $membership->tenant_id);
    }

    public function reEnroll(User $user, OrganizationMembership $membership): bool
    {
        return $this->updateStatus($user, $membership);
    }

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
