<?php

namespace Modules\Donations\Support;

class MoneyMath
{
    public static function normalize(string|float|int|null $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return bcadd('0', '0', $scale);
        }

        return bcadd((string) $value, '0', $scale);
    }

    public static function add(string|float|int $left, string|float|int $right, int $scale = 2): string
    {
        return bcadd(self::normalize($left, $scale), self::normalize($right, $scale), $scale);
    }

    public static function subtract(string|float|int $left, string|float|int $right, int $scale = 2): string
    {
        return bcsub(self::normalize($left, $scale), self::normalize($right, $scale), $scale);
    }

    public static function floorAtZero(string|float|int $value, int $scale = 2): string
    {
        $normalized = self::normalize($value, $scale);

        return bccomp($normalized, '0', $scale) < 0 ? self::normalize('0', $scale) : $normalized;
    }

    public static function outstanding(string|float|int $amountDue, string|float|int $amountPaid, int $scale = 2): string
    {
        return self::floorAtZero(self::subtract($amountDue, $amountPaid, $scale), $scale);
    }

    public static function compare(string|float|int $left, string|float|int $right, int $scale = 2): int
    {
        return bccomp(self::normalize($left, $scale), self::normalize($right, $scale), $scale);
    }

    public static function equals(string|float|int $left, string|float|int $right, int $scale = 2): bool
    {
        return self::compare($left, $right, $scale) === 0;
    }

    public static function isPositive(string|float|int $value, int $scale = 2): bool
    {
        return self::compare($value, '0', $scale) > 0;
    }

    public static function min(string|float|int $left, string|float|int $right, int $scale = 2): string
    {
        return self::compare($left, $right, $scale) <= 0
            ? self::normalize($left, $scale)
            : self::normalize($right, $scale);
    }

    public static function max(string|float|int $left, string|float|int $right, int $scale = 2): string
    {
        return self::compare($left, $right, $scale) >= 0
            ? self::normalize($left, $scale)
            : self::normalize($right, $scale);
    }

    /**
     * Serialize for JSON that historically returned numeric amounts.
     */
    public static function toApiNumber(string|float|int $value, int $scale = 2): float
    {
        return (float) self::normalize($value, $scale);
    }

    public static function round(string|float|int $value, int $scale = 2): float
    {
        return self::toApiNumber($value, $scale);
    }
}
