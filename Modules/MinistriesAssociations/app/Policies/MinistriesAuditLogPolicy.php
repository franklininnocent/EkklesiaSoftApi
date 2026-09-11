<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;

class MinistriesAuditLogPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'ministries.view');
    }

    public function view(User $user, MinistriesAuditLog $auditLog): bool
    {
        return $this->allows($user, 'ministries.view')
            && EffectiveTenant::matches($user, $auditLog->tenant_id);
    }
}
