<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Single source of truth for user-facing date labels produced by the API
 * (PDFs, certificates, audit summaries, notification copy).
 *
 * Machine formats (Y-m-d, ISO-8601) must not be rewritten through this helper.
 */
final class UserFacingDate
{
    public static function formatDate(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);
        if ($date === null) {
            return '';
        }

        return $date->locale('en')->format('j M Y');
    }

    public static function formatDateTime(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);
        if ($date === null) {
            return '';
        }

        return $date->locale('en')->format('j M Y, g:i A');
    }

    public static function formatMonthYear(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);
        if ($date === null) {
            return '';
        }

        return $date->locale('en')->format('M Y');
    }

    public static function formatDayMonth(DateTimeInterface|string|null $value): string
    {
        $date = self::parse($value);
        if ($date === null) {
            return '';
        }

        return $date->locale('en')->format('j M');
    }

    private static function parse(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return Carbon::parse($value);
            }

            $trimmed = trim((string) $value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
                return Carbon::createFromFormat('Y-m-d', $trimmed)?->startOfDay();
            }

            return Carbon::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }
}
