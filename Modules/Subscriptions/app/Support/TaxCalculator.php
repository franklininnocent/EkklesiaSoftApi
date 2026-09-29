<?php

namespace Modules\Subscriptions\Support;

use App\Support\MoneyMath;

/**
 * Display-only tax breakdown (bcmath; no floats). No invoicing or payment processing.
 */
final class TaxCalculator
{
    /**
     * @return array{net: string, tax: string, gross: string, rate_percent: string, inclusive: bool}|null
     */
    public static function breakdown(?string $price, ?string $ratePercent, bool $inclusive): ?array
    {
        if ($price === null) {
            return null;
        }

        $price = MoneyMath::normalize($price);
        $rate = MoneyMath::normalize($ratePercent ?? '0');

        if (! MoneyMath::isPositive($rate)) {
            return ['net' => $price, 'tax' => '0.00', 'gross' => $price, 'rate_percent' => $rate, 'inclusive' => $inclusive];
        }

        if ($inclusive) {
            $net = bcdiv(bcmul($price, '100', 6), bcadd('100', $rate, 6), 6);
            $net = MoneyMath::normalize(self::round($net));
            $tax = MoneyMath::subtract($price, $net);

            return ['net' => $net, 'tax' => $tax, 'gross' => $price, 'rate_percent' => $rate, 'inclusive' => true];
        }

        $tax = MoneyMath::normalize(self::round(bcdiv(bcmul($price, $rate, 6), '100', 6)));

        return ['net' => $price, 'tax' => $tax, 'gross' => MoneyMath::add($price, $tax), 'rate_percent' => $rate, 'inclusive' => false];
    }

    /**
     * Half-up rounding to 2 decimals on a bcmath string.
     */
    private static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $offset, 2);
    }
}
