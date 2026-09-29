<?php

namespace Modules\BCC\Policies;

use Modules\Authentication\Models\User;
use Modules\BCC\Models\BccAuditLog;
use Modules\Tenants\Support\AuditLogViewerAuthorization;
use Modules\Tenants\Support\EffectiveTenant;

class BccAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return AuditLogViewerAuthorization::canViewTenantAudit($user);
    }

    public function view(User $user, BccAuditLog $log): bool
    {
        return AuditLogViewerAuthorization::canViewTenantAudit($user)
            && EffectiveTenant::matches($user, $log->tenant_id);
    }
}
