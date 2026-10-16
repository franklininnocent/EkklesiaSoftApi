<?php

namespace Modules\Subscriptions\Services\Catalog;

/**
 * Legacy mirror columns (subscription_plans / tenants max_users, max_storage_mb, features)
 * are NOT NULL integers; this sentinel represents "unlimited" there.
 */
final class LegacyColumns
{
    public const UNLIMITED = 999999;

    public static function fromLimit(?int $limit): int
    {
        return $limit === null ? self::UNLIMITED : max(0, $limit);
    }
}
