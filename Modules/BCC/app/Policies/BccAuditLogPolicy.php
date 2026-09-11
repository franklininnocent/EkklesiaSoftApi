<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\BCC\Models\BccAuditLog;
use Modules\Tenants\Support\EffectiveTenant;

class BccAuditLogPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'bcc.view');
    }

    public function view(User $user, BccAuditLog $log): bool
    {
        return $this->allows($user, 'bcc.view')
            && EffectiveTenant::matches($user, $log->tenant_id);
    }
}
