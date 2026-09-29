<?php

namespace Modules\Tenants\Support;

final class LeadershipAssignmentStatus
{
    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const VACATED = 'vacated';

    public const TRANSFERRED = 'transferred';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::COMPLETED,
            self::VACATED,
            self::TRANSFERRED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETED,
            self::VACATED,
            self::TRANSFERRED,
        ];
    }
}
