<?php

namespace Modules\Subscriptions\Support;

/**
 * Rollout switch for the plan-driven entitlement engine (config: subscriptions.entitlement_engine).
 */
final class EntitlementEngineMode
{
    public const LEGACY = 'legacy';

    public const SHADOW = 'shadow';

    public const ENFORCE = 'enforce';

    public static function current(): string
    {
        $mode = strtolower(trim((string) config('subscriptions.entitlement_engine', self::SHADOW)));

        return in_array($mode, [self::LEGACY, self::SHADOW, self::ENFORCE], true) ? $mode : self::SHADOW;
    }

    public static function isEnforcing(): bool
    {
        return self::current() === self::ENFORCE;
    }

    public static function isLegacy(): bool
    {
        return self::current() === self::LEGACY;
    }
}
