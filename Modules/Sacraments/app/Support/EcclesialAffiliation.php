<?php

namespace Modules\Sacraments\Support;

/**
 * Structured religious / ecclesial affiliation (not parish membership, ADR-12).
 */
final class EcclesialAffiliation
{
    public const ROMAN_CATHOLIC = 'roman_catholic';

    public const SYRO_MALABAR = 'syro_malabar';

    public const SYRO_MALANKARA = 'syro_malankara';

    public const ORTHODOX = 'orthodox';

    public const CSI = 'csi';

    public const ANGLICAN = 'anglican';

    public const LUTHERAN = 'lutheran';

    public const HINDU = 'hindu';

    public const MUSLIM = 'muslim';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ROMAN_CATHOLIC,
            self::SYRO_MALABAR,
            self::SYRO_MALANKARA,
            self::ORTHODOX,
            self::CSI,
            self::ANGLICAN,
            self::LUTHERAN,
            self::HINDU,
            self::MUSLIM,
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

    public static function label(?string $code, ?string $customLabel = null): ?string
    {
        if ($code === self::OTHER) {
            $custom = trim((string) $customLabel);

            return $custom !== '' ? $custom : 'Other';
        }

        return match ($code) {
            self::ROMAN_CATHOLIC => 'Roman Catholic Church',
            self::SYRO_MALABAR => 'Syro-Malabar Church',
            self::SYRO_MALANKARA => 'Syro-Malankara Church',
            self::ORTHODOX => 'Orthodox Church',
            self::CSI => 'Church of South India',
            self::ANGLICAN => 'Anglican Communion',
            self::LUTHERAN => 'Lutheran',
            self::HINDU => 'Hindu',
            self::MUSLIM => 'Muslim',
            default => $customLabel ?: null,
        };
    }
}
