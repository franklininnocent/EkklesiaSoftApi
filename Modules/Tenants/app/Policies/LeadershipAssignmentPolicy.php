<?php

namespace Modules\Tenants\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\Tenants\Support\TenantContext;

class LeadershipAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowsRead($user);
    }

    public function assign(User $user): bool
    {
        return $this->allowsManage($user);
    }

    public function handover(User $user): bool
    {
        return $this->allowsManage($user);
    }

    public function terminate(User $user, LeadershipAssignment $assignment): bool
    {
        return $this->allowsManage($user)
            && EffectiveTenant::matches($user, $assignment->tenant_id);
    }

    public function update(User $user, LeadershipAssignment $assignment): bool
    {
        return $this->allowsManage($user)
            && EffectiveTenant::matches($user, $assignment->tenant_id);
    }

    private function allowsRead(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return app(TenantContext::class)->effectiveTenantId() !== null;
    }

    private function allowsManage(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ($user->isTenantAdmin() || ($user->is_primary_admin ?? false)) {
            return true;
        }

        return $user->hasPermission('church.settings.edit')
            || $user->hasPermission('church.settings.create');
    }
}
