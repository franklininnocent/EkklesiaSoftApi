<?php

namespace Modules\Sacraments\Support;

final class SacramentPlaceClassification
{
    public const PARISH = 'parish';

    public const HOME = 'home';

    public const HOSPITAL = 'hospital';

    public const INSTITUTION = 'institution';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PARISH, self::HOME, self::HOSPITAL, self::INSTITUTION, self::OTHER];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }
}
