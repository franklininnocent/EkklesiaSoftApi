<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use Modules\Tenants\Models\Tenant;

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

        return $user->tenant_id !== null
            && (bool) $user->is_primary_admin
            && self::tenantPlanIncludesAuditLog((int) $user->tenant_id);
    }

    /**
     * Commercial check layered on top of the role check: the church's plan must include AUDIT_LOG.
     * Without the Subscriptions module bound (or in legacy/shadow mode) this stays permissive.
     */
    private static function tenantPlanIncludesAuditLog(int $tenantId): bool
    {
        if (! app()->bound(TenantEntitlementGate::class)) {
            return true;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant === null || app(TenantEntitlementGate::class)->allows($tenant, 'AUDIT_LOG');
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
