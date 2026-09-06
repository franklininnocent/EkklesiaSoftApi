<?php

namespace Modules\Tenants\Support;

final class LeadershipRoleCategory
{
    public const CANONICAL_DIOCESAN = 'CANONICAL_DIOCESAN';

    public const PARISH_CLERGY = 'PARISH_CLERGY';

    public const PARISH_COUNCIL = 'PARISH_COUNCIL';

    public const MINISTRY_PIOUS = 'MINISTRY_PIOUS';

    public const OTHER = 'OTHER';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CANONICAL_DIOCESAN,
            self::PARISH_CLERGY,
            self::PARISH_COUNCIL,
            self::MINISTRY_PIOUS,
            self::OTHER,
        ];
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::CANONICAL_DIOCESAN => 'Diocesan / Canonical',
            self::PARISH_CLERGY => 'Parish Clergy',
            self::PARISH_COUNCIL => 'Parish Councils',
            self::MINISTRY_PIOUS => 'Ministries',
            default => 'Other',
        };
    }
}
