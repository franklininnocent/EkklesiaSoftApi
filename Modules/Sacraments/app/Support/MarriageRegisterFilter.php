<?php

namespace Modules\Sacraments\Support;

/**
 * Dashboard drill-down filters for matrimony register list views.
 */
final class MarriageRegisterFilter
{
    public const CATHOLIC_BOTH = 'catholic_both';

    public const MIXED_DISPARITY = 'mixed_disparity';

    public const CONVALIDATIONS = 'convalidations';

    public const PROFILE_LINKED = 'profile_linked';

    public const SAME_PARISH = 'same_parish';

    public const INTER_PARISH = 'inter_parish';

    public const ALL = [
        self::CATHOLIC_BOTH,
        self::MIXED_DISPARITY,
        self::CONVALIDATIONS,
        self::PROFILE_LINKED,
        self::SAME_PARISH,
        self::INTER_PARISH,
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::ALL, true) ? $normalized : null;
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::ALL);
    }

    public static function label(?string $value): ?string
    {
        return match (self::normalize($value)) {
            self::CATHOLIC_BOTH => 'Sacramental marriages (both Catholic)',
            self::MIXED_DISPARITY => 'Mixed marriages & disparity of cult',
            self::CONVALIDATIONS => 'Convalidations & radical sanations',
            self::PROFILE_LINKED => 'Full profile & register matched',
            self::SAME_PARISH => 'Both from same parish (bride & groom)',
            self::INTER_PARISH => 'Different parishes (bride & groom)',
            default => null,
        };
    }
}
