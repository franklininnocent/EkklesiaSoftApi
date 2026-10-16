<?php

namespace Modules\Tenants\Support;

/**
 * Civil / statutory fiscal-year start defaults by ISO2 (month/day, parish reporting).
 * Tenants may override via donation_settings when financial_year_source = tenant.
 */
final class CountryFiscalYearCatalog
{
    /** @var array<string, array{month: int, day: int}> */
    private const STARTS = [
        'IN' => ['month' => 4, 'day' => 1],
        'PK' => ['month' => 4, 'day' => 1],
        'BD' => ['month' => 4, 'day' => 1],
        'NP' => ['month' => 4, 'day' => 1],
        'AU' => ['month' => 7, 'day' => 1],
        'NZ' => ['month' => 4, 'day' => 1],
        'JP' => ['month' => 4, 'day' => 1],
        'GB' => ['month' => 4, 'day' => 6],
        'UK' => ['month' => 4, 'day' => 6],
        'ZA' => ['month' => 3, 'day' => 1],
    ];

    public static function defaultForIso2(?string $iso2): array
    {
        $code = strtoupper(trim((string) $iso2));
        if ($code !== '' && isset(self::STARTS[$code])) {
            return self::STARTS[$code];
        }

        return ['month' => 1, 'day' => 1];
    }
}
