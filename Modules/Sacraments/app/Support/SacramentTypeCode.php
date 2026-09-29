<?php

namespace Modules\Sacraments\Support;

/**
 * Normalize sacrament type codes across dual seeders (BAPTISM vs baptism, MATRIMONY vs marriage).
 */
final class SacramentTypeCode
{
    public const BAPTISM = 'BAPTISM';

    public const CONFIRMATION = 'CONFIRMATION';

    public const EUCHARIST = 'EUCHARIST';

    public const RECONCILIATION = 'RECONCILIATION';

    public const ANOINTING = 'ANOINTING';

    public const HOLY_ORDERS = 'HOLY_ORDERS';

    public const MATRIMONY = 'MATRIMONY';

    /** @var list<string> Types omitted from age distribution and monthly trend dashboard charts. */
    private const STANDARD_DASHBOARD_CHART_EXCLUSIONS = [
        self::RECONCILIATION,
        self::ANOINTING,
    ];

    private const ALIASES = [
        'BAPTISM' => self::BAPTISM,
        'BAPTISMO' => self::BAPTISM,
        'BAPTIMAL' => self::BAPTISM,
        'BAPTISE' => self::BAPTISM,
        'CONFIRMATION' => self::CONFIRMATION,
        'EUCHARIST' => self::EUCHARIST,
        'FIRST_COMMUNION' => self::EUCHARIST,
        'FIRSTCOMMUNION' => self::EUCHARIST,
        'RECONCILIATION' => self::RECONCILIATION,
        'CONFESSION' => self::RECONCILIATION,
        'ANOINTING' => self::ANOINTING,
        'ANOINTING_SICK' => self::ANOINTING,
        'ANOINTINGOFTHESICK' => self::ANOINTING,
        'HOLY_ORDERS' => self::HOLY_ORDERS,
        'HOLYORDERS' => self::HOLY_ORDERS,
        'ORDINATION' => self::HOLY_ORDERS,
        'MATRIMONY' => self::MATRIMONY,
        'MARRIAGE' => self::MATRIMONY,
        'WEDDING' => self::MATRIMONY,
    ];

    public static function normalize(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $key = strtoupper(str_replace([' ', '-'], '_', trim($code)));
        $key = preg_replace('/_+/', '_', $key) ?? $key;

        return self::ALIASES[$key] ?? $key;
    }

    public static function isBaptism(?string $code): bool
    {
        return self::normalize($code) === self::BAPTISM;
    }

    public static function isMatrimony(?string $code): bool
    {
        return self::normalize($code) === self::MATRIMONY;
    }

    public static function isExcludedFromStandardDashboardCharts(?string $code): bool
    {
        $normalized = self::normalize($code);

        return $normalized !== null
            && in_array($normalized, self::STANDARD_DASHBOARD_CHART_EXCLUSIONS, true);
    }
}
