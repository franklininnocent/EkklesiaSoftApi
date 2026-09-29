<?php

namespace Modules\PastoralCare\Support;

final class PastoralCarePriority
{
    public const URGENT = 'urgent';

    public const ROUTINE = 'routine';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::URGENT, self::ROUTINE];
    }
}
