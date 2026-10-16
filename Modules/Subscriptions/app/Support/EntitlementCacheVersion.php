<?php

namespace Modules\Subscriptions\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Version counters for entitlement caches: bumping invalidates without key scans.
 */
final class EntitlementCacheVersion
{
    private const CATALOG_KEY = 'subscriptions:catalog_version';

    public static function tenantKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:entitlements_version";
    }

    public static function tenant(int $tenantId): int
    {
        return $tenantId > 0 ? (int) Cache::get(self::tenantKey($tenantId), 0) : 0;
    }

    public static function bumpTenant(int $tenantId): void
    {
        if ($tenantId > 0) {
            self::increment(self::tenantKey($tenantId));
        }
    }

    public static function catalog(): int
    {
        return (int) Cache::get(self::CATALOG_KEY, 0);
    }

    public static function bumpCatalog(): void
    {
        self::increment(self::CATALOG_KEY);
    }

    /** The database store does not increment a missing key, so seed it first. */
    private static function increment(string $key): void
    {
        Cache::add($key, 0);
        Cache::increment($key);
    }

    public static function entitlementsKey(int $tenantId): string
    {
        return sprintf(
            'subscriptions:entitlements:tenant_%d:v_%d:c_%d',
            $tenantId,
            self::tenant($tenantId),
            self::catalog()
        );
    }

    public static function catalogSnapshotKey(): string
    {
        return sprintf('subscriptions:catalog:c_%d', self::catalog());
    }
}
