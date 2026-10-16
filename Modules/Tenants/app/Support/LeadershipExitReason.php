<?php

namespace Modules\Tenants\Support;

final class LeadershipExitReason
{
    public const TRANSFERRED = 'transferred';

    public const RETIRED = 'retired';

    public const RESIGNED = 'resigned';

    public const REMOVED = 'removed';

    public const COMPLETED = 'completed';

    public const DECEASED = 'deceased';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TRANSFERRED,
            self::RETIRED,
            self::RESIGNED,
            self::REMOVED,
            self::COMPLETED,
            self::DECEASED,
            self::OTHER,
        ];
    }

    public static function statusForExitReason(string $exitReason): string
    {
        return match ($exitReason) {
            self::TRANSFERRED => LeadershipAssignmentStatus::TRANSFERRED,
            self::COMPLETED, self::RETIRED => LeadershipAssignmentStatus::COMPLETED,
            self::RESIGNED, self::DECEASED => LeadershipAssignmentStatus::VACATED,
            default => LeadershipAssignmentStatus::VACATED,
        };
    }
}
