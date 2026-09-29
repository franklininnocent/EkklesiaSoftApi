<?php

namespace Modules\Tenants\Support;

/**
 * BCP47 locales for money display — keep in sync with EkklesiaSoftUi cf-intl.util.ts CURRENCY_LOCALES.
 */
final class ChurchCurrencyLocale
{
    private const LOCALES = [
        'INR' => 'en-IN',
        'USD' => 'en-US',
        'EUR' => 'en-IE',
        'GBP' => 'en-GB',
    ];

    private const DEFAULT_LOCALE = 'en-US';

    private const DEFAULT_DECIMAL_DIGITS = 2;

    public static function localeFor(string $currencyCode): string
    {
        $upper = strtoupper($currencyCode);

        return self::LOCALES[$upper] ?? self::DEFAULT_LOCALE;
    }

    public static function decimalDigitsFor(string $currencyCode): int
    {
        return self::DEFAULT_DECIMAL_DIGITS;
    }

    public static function isValidIso4217(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return (bool) preg_match('/^[A-Z]{3}$/', strtoupper($code));
    }
}
