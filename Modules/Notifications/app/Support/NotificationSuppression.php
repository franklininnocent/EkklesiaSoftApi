<?php

namespace Modules\Notifications\Support;

/**
 * Request/job-scoped suppression for bulk imports (P2/P3 only).
 */
final class NotificationSuppression
{
    private static int $quietDepth = 0;

    public static function quiet(): void
    {
        self::$quietDepth++;
    }

    public static function unquiet(): void
    {
        self::$quietDepth = max(0, self::$quietDepth - 1);
    }

    public static function isQuiet(): bool
    {
        return self::$quietDepth > 0;
    }
}
