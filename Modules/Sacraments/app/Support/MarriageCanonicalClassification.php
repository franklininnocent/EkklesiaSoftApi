<?php

namespace Modules\Sacraments\Support;

/**
 * Canonical classification of a marriage (Can. 1124 mixed marriage, Can. 1086 disparity of cult).
 * Register-only — never printed on the default public certificate.
 */
final class MarriageCanonicalClassification
{
    public const BOTH_CATHOLIC = 'both_catholic';

    public const MIXED_MARRIAGE = 'mixed_marriage';

    public const DISPARITY_OF_CULT = 'disparity_of_cult';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BOTH_CATHOLIC,
            self::MIXED_MARRIAGE,
            self::DISPARITY_OF_CULT,
            self::OTHER,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }

    public static function requiresDispensation(string $classification): bool
    {
        return in_array($classification, [self::MIXED_MARRIAGE, self::DISPARITY_OF_CULT], true);
    }

    public static function label(?string $value): ?string
    {
        return match ($value) {
            self::BOTH_CATHOLIC => 'Both Catholic',
            self::MIXED_MARRIAGE => 'Mixed Marriage',
            self::DISPARITY_OF_CULT => 'Disparity of Cult',
            self::OTHER => 'Other',
            default => null,
        };
    }
}
