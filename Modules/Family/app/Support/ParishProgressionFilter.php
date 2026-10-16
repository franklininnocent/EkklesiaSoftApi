<?php

namespace Modules\Family\Support;

/**
 * Dashboard and list drill-down filters for sacrament progression gaps.
 */
final class ParishProgressionFilter
{
    public const BAPTIZED_WITHOUT_COMMUNION = 'baptized_without_communion';

    public const BAPTIZED_WITHOUT_CONFIRMATION = 'baptized_without_confirmation';

    public const FEMALE_UNMARRIED_OVER_18 = 'female_unmarried_over_18';

    public const MALE_UNMARRIED_OVER_23 = 'male_unmarried_over_23';

    public const ALL = [
        self::BAPTIZED_WITHOUT_COMMUNION,
        self::BAPTIZED_WITHOUT_CONFIRMATION,
        self::FEMALE_UNMARRIED_OVER_18,
        self::MALE_UNMARRIED_OVER_23,
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::ALL, true) ? $normalized : null;
    }

    public static function label(?string $value): ?string
    {
        return match (self::normalize($value)) {
            self::BAPTIZED_WITHOUT_COMMUNION => 'Baptized, no First Communion (age 10+)',
            self::BAPTIZED_WITHOUT_CONFIRMATION => 'Baptized, no Confirmation (age 10+)',
            self::FEMALE_UNMARRIED_OVER_18 => 'Female (>18) - Not Married',
            self::MALE_UNMARRIED_OVER_23 => 'Male (>23) - Not Married',
            default => null,
        };
    }
}
