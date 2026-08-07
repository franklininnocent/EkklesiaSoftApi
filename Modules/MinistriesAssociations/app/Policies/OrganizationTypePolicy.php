<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\OrganizationType;

class OrganizationTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }

    public function update(User $user, OrganizationType $type): bool
    {
        return $this->allows($user, 'ministries.configure')
            && EffectiveTenant::matches($user, $type->tenant_id);
    }

    public function updateStatus(User $user, OrganizationType $type): bool
    {
        return $this->update($user, $type);
    }

    public function seedDefaults(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
