<?php

namespace Modules\Tenants\Support;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Explicit tenant scoping helpers for queries that do not use BelongsToTenant models.
 */
final class TenantQueryGuard
{
    public static function effectiveTenantId(): ?int
    {
        try {
            $tenantId = app(TenantContext::class)->effectiveTenantId();
        } catch (\Throwable) {
            return null;
        }

        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        return $tenantId;
    }

    /**
     * @throws HttpException
     */
    public static function requireEffectiveTenantId(): int
    {
        return app(TenantContext::class)->requireEffectiveTenantId();
    }

    public static function apply(Builder $query, string $column = 'tenant_id'): Builder
    {
        $tenantId = self::effectiveTenantId();

        if ($tenantId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $tenantId);
    }

    public static function matchesResourceTenant(int|string|null $resourceTenantId): bool
    {
        if ($resourceTenantId === null || $resourceTenantId === '') {
            return false;
        }

        $effective = self::effectiveTenantId();

        return $effective !== null && (int) $resourceTenantId === $effective;
    }
}
