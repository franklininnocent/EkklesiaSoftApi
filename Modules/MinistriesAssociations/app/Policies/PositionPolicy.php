<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\Position;

class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'ministries.configure');
    }

    public function update(User $user, Position $position): bool
    {
        return $this->allows($user, 'ministries.configure')
            && EffectiveTenant::matches($user, $position->tenant_id);
    }

    public function updateStatus(User $user, Position $position): bool
    {
        return $this->update($user, $position);
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
