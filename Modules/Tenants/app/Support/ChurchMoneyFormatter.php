<?php

namespace Modules\Tenants\Support;

use App\Support\MoneyMath;
use Modules\Tenants\Services\ChurchCurrencyResolver;

final class ChurchMoneyFormatter
{
    public static function format(string|float|int|null $amount, ?ChurchCurrency $currency): string
    {
        if ($currency === null || $currency->currencyCodeOrNull() === null) {
            $normalized = MoneyMath::normalize($amount ?? 0);

            return number_format((float) $normalized, 2, '.', ',');
        }

        $code = $currency->currencyCodeOrNull();
        $normalized = MoneyMath::normalize($amount ?? 0);
        $numeric = (float) $normalized;

        if (extension_loaded('intl')) {
            $formatter = new \NumberFormatter($currency->locale, \NumberFormatter::CURRENCY);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $currency->decimalDigits);
            $formatted = $formatter->formatCurrency($numeric, $code);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $code.' '.number_format($numeric, $currency->decimalDigits, '.', ',');
    }

    public static function formatForTenant(int $tenantId, string|float|int|null $amount): string
    {
        $currency = app(ChurchCurrencyResolver::class)->forTenantId($tenantId);

        return self::format($amount, $currency);
    }
}
