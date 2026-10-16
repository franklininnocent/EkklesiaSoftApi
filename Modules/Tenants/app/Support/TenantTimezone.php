<?php

namespace Modules\Tenants\Support;

use Modules\Tenants\Models\Tenant;

/**
 * Request-scoped parish timezone lookup. Dashboard and date helpers call this
 * many times per request; Tenant::find must not repeat for the same tenant.
 */
final class TenantTimezone
{
    /** @var array<int, string> */
    private static array $memo = [];

    public static function forTenantId(int $tenantId): string
    {
        if ($tenantId <= 0) {
            return self::fallback();
        }

        if (array_key_exists($tenantId, self::$memo)) {
            return self::$memo[$tenantId];
        }

        $tenant = Tenant::query()->find($tenantId);
        $fromTenant = $tenant?->getSetting('timezone');
        $timezone = is_string($fromTenant) && $fromTenant !== ''
            ? $fromTenant
            : self::fallback();

        self::$memo[$tenantId] = $timezone;

        return $timezone;
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function fallback(): string
    {
        return (string) config('tenants.default_settings.timezone', 'Asia/Kolkata');
    }
}
