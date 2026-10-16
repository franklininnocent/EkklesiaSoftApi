<?php

namespace Modules\Donations\Support\Reports;

use InvalidArgumentException;

final class DonationReportExportFormat
{
    public const CSV = 'csv';

    public const XLSX = 'xlsx';

    public const PDF = 'pdf';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CSV, self::XLSX, self::PDF];
    }

    public static function normalize(?string $value): string
    {
        $format = strtolower(trim((string) $value));
        if ($format === '') {
            return self::CSV;
        }

        if (! in_array($format, self::all(), true)) {
            throw new InvalidArgumentException('Export format must be csv, xlsx, or pdf.');
        }

        return $format;
    }

    public static function extension(string $format): string
    {
        return match (self::normalize($format)) {
            self::XLSX => 'xlsx',
            self::PDF => 'pdf',
            default => 'csv',
        };
    }

    public static function mimeType(string $format): string
    {
        return match (self::normalize($format)) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PDF => 'application/pdf',
            default => 'text/csv; charset=UTF-8',
        };
    }
}
