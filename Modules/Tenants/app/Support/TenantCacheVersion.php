<?php

namespace Modules\Tenants\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-tenant cache versioning — bump invalidates versioned keys without scanning.
 */
final class TenantCacheVersion
{
    public static function key(int $tenantId): string
    {
        return "tenant:{$tenantId}:cache_version";
    }

    public static function current(int $tenantId): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        return (int) Cache::get(self::key($tenantId), 0);
    }

    public static function bump(int $tenantId): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        return (int) Cache::increment(self::key($tenantId));
    }

    /**
     * Build a tenant-versioned cache key for a domain namespace.
     */
    public static function scopedKey(int $tenantId, string $domain, string $suffix): string
    {
        $version = self::current($tenantId);

        return sprintf('%s:tenant_%d:v_%d:%s', $domain, $tenantId, $version, $suffix);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(int $tenantId, string $domain, string $suffix, int $ttlSeconds, Closure $callback): mixed
    {
        return Cache::remember(
            self::scopedKey($tenantId, $domain, $suffix),
            $ttlSeconds,
            $callback
        );
    }
}
