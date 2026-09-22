<?php

namespace Modules\MinistriesAssociations\Policies;

use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\Tenants\Support\AuditLogViewerAuthorization;
use Modules\Tenants\Support\EffectiveTenant;

class MinistriesAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return AuditLogViewerAuthorization::canViewTenantAudit($user);
    }

    public function view(User $user, MinistriesAuditLog $auditLog): bool
    {
        return AuditLogViewerAuthorization::canViewTenantAudit($user)
            && EffectiveTenant::matches($user, $auditLog->tenant_id);
    }
}
