<?php

namespace Modules\PastoralCare\Support;

final class PastoralCareStatus
{
    public const OPEN = 'open';

    public const ASSIGNED = 'assigned';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::OPEN, self::ASSIGNED, self::DONE, self::CANCELLED];
    }

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return [self::OPEN, self::ASSIGNED];
    }
}
