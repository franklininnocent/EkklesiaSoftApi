<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\BCC\Models\BccAuditLog;
use Modules\Tenants\Support\EffectiveTenant;

class BccAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'bcc.view');
    }

    public function view(User $user, BccAuditLog $log): bool
    {
        return $this->allows($user, 'bcc.view')
            && EffectiveTenant::matches($user, $log->tenant_id);
    }

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
