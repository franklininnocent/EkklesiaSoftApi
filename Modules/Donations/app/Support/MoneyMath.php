<?php

namespace Modules\Donations\Support;

class MoneyMath
{
    public static function add(string|float $left, string|float $right, int $scale = 2): string
    {
        return bcadd((string) $left, (string) $right, $scale);
    }

    public static function subtract(string|float $left, string|float $right, int $scale = 2): string
    {
        return bcsub((string) $left, (string) $right, $scale);
    }

    public static function outstanding(string|float $amountDue, string|float $amountPaid, int $scale = 2): string
    {
        $outstanding = self::subtract($amountDue, $amountPaid, $scale);

        return bccomp($outstanding, '0', $scale) < 0 ? bcadd('0', '0', $scale) : $outstanding;
    }

    public static function toFloat(string $value): float
    {
        return (float) $value;
    }

    public static function round(string|float $value, int $scale = 2): float
    {
        return round((float) $value, $scale);
    }
}
