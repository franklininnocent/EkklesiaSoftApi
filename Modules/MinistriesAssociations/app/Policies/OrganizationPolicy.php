<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\Organization;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'ministries.view')
            && EffectiveTenant::matches($user, $organization->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.create');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'ministries.edit')
            && EffectiveTenant::matches($user, $organization->tenant_id);
    }

    public function updateStatus(User $user, Organization $organization): bool
    {
        return $this->update($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->allows($user, 'ministries.delete')
            && EffectiveTenant::matches($user, $organization->tenant_id);
    }

    public function restore(User $user, Organization $organization): bool
    {
        return $this->delete($user, $organization);
    }

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
