<?php

namespace Modules\Donations\Support\Reports;

use Modules\Tenants\Support\ChurchMoneyFormatter;

final class DonationReportDisplayValue
{
    public static function format(int $tenantId, string $columnKey, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_numeric($value) && self::isMoneyKey($columnKey)) {
            return ChurchMoneyFormatter::formatForTenant($tenantId, (float) $value);
        }

        return (string) $value;
    }

    public static function sanitizeSpreadsheetCell(string $value): string
    {
        return SafeCsvWriter::sanitizeCell($value);
    }

    public static function isMoneyKey(string $key): bool
    {
        return (bool) preg_match('/amount|outstanding|collected|paid|gross|due|gap/i', $key)
            && ! preg_match('/date|_on$|issued/i', $key);
    }
}
