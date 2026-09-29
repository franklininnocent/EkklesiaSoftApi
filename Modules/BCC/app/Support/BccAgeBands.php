<?php

namespace Modules\BCC\Support;

/**
 * Single source of truth for BCC demographic age bands.
 * No parish-wide age-band definition existed; these defaults are documented
 * in the BCC module plan and must be reused by overview + people filters.
 */
final class BccAgeBands
{
    public const BABIES = 'babies';

    public const CHILDREN = 'children';

    public const TEENAGERS = 'teenagers';

    public const YOUNG_ADULTS = 'young_adults';

    public const ADULTS = 'adults';

    public const SENIORS = 'seniors';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<array{key: string, label: string, min: int|null, max: int|null}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => self::BABIES, 'label' => 'Babies', 'min' => 0, 'max' => 2],
            ['key' => self::CHILDREN, 'label' => 'Children', 'min' => 3, 'max' => 12],
            ['key' => self::TEENAGERS, 'label' => 'Teenagers', 'min' => 13, 'max' => 17],
            ['key' => self::YOUNG_ADULTS, 'label' => 'Young adults', 'min' => 18, 'max' => 25],
            ['key' => self::ADULTS, 'label' => 'Adults', 'min' => 26, 'max' => 59],
            ['key' => self::SENIORS, 'label' => 'Seniors', 'min' => 60, 'max' => null],
            ['key' => self::UNKNOWN, 'label' => 'Unknown', 'min' => null, 'max' => null],
        ];
    }

    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    public static function fromAge(?int $age): string
    {
        if ($age === null || $age < 0) {
            return self::UNKNOWN;
        }

        foreach (self::definitions() as $band) {
            if ($band['key'] === self::UNKNOWN) {
                continue;
            }
            $min = $band['min'] ?? 0;
            $max = $band['max'];
            if ($age >= $min && ($max === null || $age <= $max)) {
                return $band['key'];
            }
        }

        return self::UNKNOWN;
    }

    public static function sqlCase(string $ageExpression): string
    {
        return "
            CASE
                WHEN {$ageExpression} IS NULL THEN 'unknown'
                WHEN {$ageExpression} BETWEEN 0 AND 2 THEN 'babies'
                WHEN {$ageExpression} BETWEEN 3 AND 12 THEN 'children'
                WHEN {$ageExpression} BETWEEN 13 AND 17 THEN 'teenagers'
                WHEN {$ageExpression} BETWEEN 18 AND 25 THEN 'young_adults'
                WHEN {$ageExpression} BETWEEN 26 AND 59 THEN 'adults'
                WHEN {$ageExpression} >= 60 THEN 'seniors'
                ELSE 'unknown'
            END
        ";
    }

    public static function ageYearsSql(string $dobColumn = 'family_members.date_of_birth'): string
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return "CAST((julianday('now') - julianday({$dobColumn})) / 365.25 AS INTEGER)";
        }

        return "DATE_PART('year', AGE({$dobColumn}))";
    }

    public static function likeOperator(): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';
    }
}
