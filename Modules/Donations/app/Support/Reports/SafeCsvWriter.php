<?php

namespace Modules\Donations\Support\Reports;

final class SafeCsvWriter
{
    /**
     * @param  resource  $handle
     * @param  list<scalar|null>  $fields
     */
    public static function putRow($handle, array $fields): void
    {
        $safe = array_map([self::class, 'sanitizeCell'], $fields);
        fputcsv($handle, $safe);
    }

    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;
        if ($string === '') {
            return '';
        }

        $first = $string[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }
}
