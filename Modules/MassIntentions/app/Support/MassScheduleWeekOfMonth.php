<?php

namespace Modules\MassIntentions\Support;

use Carbon\Carbon;

/**
 * nth weekday of the calendar month (parish calendar date), not ISO week number.
 */
final class MassScheduleWeekOfMonth
{
    /**
     * @param  list<mixed>|null  $raw
     * @return list<string>|null
     */
    public static function normalize(?array $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $allowed = ['1', '2', '3', '4', 'last'];
        $picked = [];
        foreach ($raw as $value) {
            $token = is_int($value) ? (string) $value : strtolower(trim((string) $value));
            if (in_array($token, $allowed, true)) {
                $picked[$token] = true;
            }
        }

        if ($picked === []) {
            return null;
        }

        $order = ['1', '2', '3', '4', 'last'];

        return array_values(array_filter($order, fn (string $key) => isset($picked[$key])));
    }

    /**
     * @param  list<string>|null  $weeksOfMonth
     */
    public static function matchesDate(?array $weeksOfMonth, Carbon $date): bool
    {
        $normalized = self::normalize($weeksOfMonth);
        if ($normalized === null) {
            return true;
        }

        $occurrence = self::occurrenceIndexOnWeekday($date);
        $isLast = self::isLastWeekdayOccurrenceInMonth($date);

        foreach ($normalized as $token) {
            if ($token === 'last' && $isLast) {
                return true;
            }
            if ($token === (string) $occurrence) {
                return true;
            }
        }

        return false;
    }

    public static function occurrenceIndexOnWeekday(Carbon $date): int
    {
        $weekday = (int) $date->format('w');
        $count = 0;
        $cursor = $date->copy()->startOfMonth()->startOfDay();
        $end = $date->copy()->endOfMonth()->startOfDay();

        while ($cursor->lte($end)) {
            if ((int) $cursor->format('w') === $weekday) {
                $count++;
                if ($cursor->isSameDay($date)) {
                    return $count;
                }
            }
            $cursor->addDay();
        }

        return 0;
    }

    public static function isLastWeekdayOccurrenceInMonth(Carbon $date): bool
    {
        $weekday = (int) $date->format('w');
        $lastDate = null;
        $cursor = $date->copy()->startOfMonth()->startOfDay();
        $end = $date->copy()->endOfMonth()->startOfDay();

        while ($cursor->lte($end)) {
            if ((int) $cursor->format('w') === $weekday) {
                $lastDate = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $lastDate !== null && $lastDate->isSameDay($date);
    }
}
