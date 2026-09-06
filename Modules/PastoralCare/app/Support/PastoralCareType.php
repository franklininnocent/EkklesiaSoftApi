<?php

namespace Modules\PastoralCare\Support;

final class PastoralCareType
{
    public const HOSPITAL_VISIT = 'hospital_visit';

    public const HOME_VISIT = 'home_visit';

    public const BEREAVEMENT = 'bereavement';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::HOSPITAL_VISIT, self::HOME_VISIT, self::BEREAVEMENT, self::OTHER];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::HOSPITAL_VISIT => 'Hospital visit',
            self::HOME_VISIT => 'Home visit',
            self::BEREAVEMENT => 'Bereavement',
            default => 'Other',
        };
    }
}
