<?php

namespace Modules\Sacraments\Support;

/**
 * Structured baptismal status for marriage parties (Canon 1121 register).
 * Not free-text "Religion".
 */
final class BaptismalStatus
{
    public const BAPTIZED_CATHOLIC = 'baptized_catholic';

    public const BAPTIZED_NON_CATHOLIC = 'baptized_non_catholic';

    public const UNBAPTIZED = 'unbaptized';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BAPTIZED_CATHOLIC,
            self::BAPTIZED_NON_CATHOLIC,
            self::UNBAPTIZED,
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
            self::BAPTIZED_CATHOLIC => 'Baptized Catholic',
            self::BAPTIZED_NON_CATHOLIC => 'Baptized Non-Catholic',
            self::UNBAPTIZED => 'Unbaptized',
            default => null,
        };
    }
}
