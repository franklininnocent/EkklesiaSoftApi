<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the tenant id Policies/Gates must compare against.
 *
 * Prefer request-scoped TenantContext (support session aware). When context
 * is unavailable (console/tests), fall back to the actor home tenant_id.
 */
final class EffectiveTenant
{
    public static function id(?Authenticatable $user = null): ?int
    {
        try {
            $context = app(TenantContext::class);
            $fromContext = $context->effectiveTenantId();
            if ($fromContext !== null && $fromContext > 0) {
                return $fromContext;
            }
        } catch (\Throwable) {
            // Container may not have TenantContext bound in some unit tests.
        }

        if ($user !== null && isset($user->tenant_id) && $user->tenant_id !== null) {
            $home = (int) $user->tenant_id;

            return $home > 0 ? $home : null;
        }

        return null;
    }

    public static function matches(?Authenticatable $user, int|string|null $resourceTenantId): bool
    {
        if ($resourceTenantId === null || $resourceTenantId === '') {
            return false;
        }

        $effective = self::id($user);
        if ($effective === null) {
            return false;
        }

        return (int) $resourceTenantId === $effective;
    }
}
