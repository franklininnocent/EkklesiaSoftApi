<?php

namespace Modules\Family\Support;

use Illuminate\Support\Facades\Cache;

final class FamilyDashboardCache
{
    public const TTL_SECONDS = 60;

    public static function bump(int|string $tenantId): void
    {
        $key = self::versionKey((int) $tenantId);
        if (Cache::has($key)) {
            Cache::increment($key);

            return;
        }

        // Database store does not increment a missing key; Redis does. Seed a
        // version of 2 so the first mutation invalidates the default v1 payload.
        Cache::forever($key, 2);
    }

    public static function version(int $tenantId): int
    {
        return (int) Cache::get(self::versionKey($tenantId), 1);
    }

    public static function payloadKey(
        int $tenantId,
        int $version,
        string $filterHash,
        bool $includeContributions,
        bool $includePastoral,
    ): string {
        $flags = ($includeContributions ? 'c1' : 'c0').($includePastoral ? 'p1' : 'p0');

        return "family_dashboard:{$tenantId}:{$version}:{$flags}:{$filterHash}";
    }

    private static function versionKey(int $tenantId): string
    {
        return "family_dashboard:v:{$tenantId}";
    }
}
