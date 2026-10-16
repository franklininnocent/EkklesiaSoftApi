<?php

namespace Modules\Sacraments\Support;

/**
 * How the assisting cleric was competent to assist at the marriage (Can. 1108–1111).
 */
final class CanonicalDelegationStatus
{
    public const PROPER_PASTOR = 'proper_pastor';

    public const DELEGATED = 'delegated';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PROPER_PASTOR,
            self::DELEGATED,
            self::OTHER,
        ];
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::all());
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && $value !== '' && in_array($value, self::all(), true);
    }

    public static function label(?string $value): ?string
    {
        return match ($value) {
            self::PROPER_PASTOR => 'Proper pastor',
            self::DELEGATED => 'Delegated',
            self::OTHER => 'Other',
            default => null,
        };
    }
}
