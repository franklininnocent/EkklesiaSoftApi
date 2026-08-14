<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Support\EffectiveTenant;

class BCCPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'bcc.view');
    }

    public function view(User $user, BCC $bcc): bool
    {
        return $this->allows($user, 'bcc.view')
            && EffectiveTenant::matches($user, $bcc->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'bcc.create');
    }

    public function update(User $user, BCC $bcc): bool
    {
        return $this->allows($user, 'bcc.edit')
            && EffectiveTenant::matches($user, $bcc->tenant_id);
    }

    public function delete(User $user, BCC $bcc): bool
    {
        return $this->allows($user, 'bcc.delete')
            && EffectiveTenant::matches($user, $bcc->tenant_id);
    }

    private function allows(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
