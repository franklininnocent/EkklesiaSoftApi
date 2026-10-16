<?php

namespace Modules\Sacraments\Certificates;

final class DenominationMapper
{
    public const ROMAN_CATHOLIC = 'ROMAN_CATHOLIC';

    public const EASTERN_RITE = 'EASTERN_RITE';

    public const CSI = 'CSI';

    public const ANGLICAN = 'ANGLICAN';

    public const LUTHERAN = 'LUTHERAN';

    public const NON_DENOM = 'NON_DENOM';

    public const GENERIC = 'GENERIC';

    private const EASTERN = [
        'SYRO_MALABAR',
        'SYRO_MALANKARA',
        'ORTHODOX',
        'ORIENTAL_ORTHODOX',
        'COPTIC',
        'ARMENIAN',
        'MAR_THOMA',
    ];

    public static function map(?string $code): string
    {
        $key = strtoupper(trim((string) $code));
        if ($key === '' || $key === 'OTHER') {
            return self::GENERIC;
        }
        if (in_array($key, ['CATHOLIC', 'ROMAN_CATHOLIC'], true)) {
            return self::ROMAN_CATHOLIC;
        }
        if (in_array($key, self::EASTERN, true)) {
            return self::EASTERN_RITE;
        }
        if (in_array($key, ['CSI', 'CHURCH_OF_SOUTH_INDIA'], true)) {
            return self::CSI;
        }
        if (in_array($key, ['ANGLICAN', 'EPISCOPAL'], true)) {
            return self::ANGLICAN;
        }
        if ($key === 'LUTHERAN') {
            return self::LUTHERAN;
        }
        if (in_array($key, ['NON_DENOM', 'NON_DENOMINATIONAL'], true)) {
            return self::NON_DENOM;
        }

        return self::GENERIC;
    }

    public static function themeId(string $denominationType): string
    {
        return match ($denominationType) {
            self::ROMAN_CATHOLIC, self::EASTERN_RITE => 'catholic',
            self::CSI, self::ANGLICAN => 'csi',
            default => 'generic',
        };
    }

    public static function confirmationEngineType(string $denominationType): string
    {
        return $denominationType === self::EASTERN_RITE ? 'CHRISMATION' : 'CONFIRMATION';
    }

    public static function engineSacramentType(string $dbCode, string $denominationType): string
    {
        $code = strtoupper(trim($dbCode));

        return match ($code) {
            'BAPTISM' => 'BAPTISM',
            'CONFIRMATION' => self::confirmationEngineType($denominationType),
            'EUCHARIST', 'FIRST_COMMUNION', 'FIRST_HOLY_COMMUNION' => 'FIRST_HOLY_COMMUNION',
            'MATRIMONY', 'MARRIAGE', 'WEDDING', 'HOLY_MATRIMONY' => 'HOLY_MATRIMONY',
            'ANOINTING', 'ANOINTING_SICK', 'ANOINTINGOFTHESICK' => 'ANOINTING_OF_THE_SICK',
            'HOLY_ORDERS', 'HOLYORDERS', 'ORDINATION' => 'HOLY_ORDERS',
            'RECONCILIATION', 'CONFESSION', 'PENANCE' => 'RECONCILIATION',
            default => 'GENERIC_REGISTRY',
        };
    }
}
