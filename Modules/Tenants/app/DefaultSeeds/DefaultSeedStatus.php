<?php

namespace Modules\Tenants\DefaultSeeds;

final class DefaultSeedStatus
{
    public const AVAILABLE = 'available';

    public const PARTIALLY_INITIALIZED = 'partially_initialized';

    public const INITIALIZED = 'initialized';

    public const UNAVAILABLE = 'unavailable';

    public const RESULT_COMPLETED = 'completed';

    public const RESULT_ALREADY_INITIALIZED = 'already_initialized';

    public const RESULT_PARTIALLY_COMPLETED = 'partially_completed';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_UNAVAILABLE = 'unavailable';

    /**
     * @param  list<string>  $canonicalCodes
     * @param  list<string>  $matchedCodes
     */
    public static function deriveStatus(int $expectedCount, int $matchedCount): string
    {
        if ($expectedCount <= 0) {
            return self::INITIALIZED;
        }

        if ($matchedCount <= 0) {
            return self::AVAILABLE;
        }

        if ($matchedCount >= $expectedCount) {
            return self::INITIALIZED;
        }

        return self::PARTIALLY_INITIALIZED;
    }
}
