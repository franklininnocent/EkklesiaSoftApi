<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

/**
 * Central gate for who may view audit trails (read APIs and audit-derived UI payloads).
 */
final class AuditLogViewerAuthorization
{
    /**
     * Parish product audit logs (BCC, Ministries, sacrament download history, etc.).
     */
    public static function canViewTenantAudit(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->tenant_id !== null && (bool) $user->is_primary_admin;
    }

    /**
     * Complete platform audit trails (bishops, dioceses, cross-tenant insights, subscription history).
     */
    public static function canViewPlatformCompleteAudit(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin();
    }

    /**
     * Support Center operational session events (who entered a parish, what they did).
     */
    public static function canViewSupportOperationalAudit(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRole(Role::SUPPORT_ADMIN);
    }
}
