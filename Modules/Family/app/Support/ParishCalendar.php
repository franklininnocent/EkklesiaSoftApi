<?php

namespace Modules\Family\app\Support;

use App\Support\UserFacingDate;
use Carbon\Carbon;
use Modules\Tenants\Models\Tenant;

final class ParishCalendar
{
    public static function timezoneForTenant(int $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);
        $fromTenant = $tenant?->getSetting('timezone');

        if (is_string($fromTenant) && $fromTenant !== '') {
            return $fromTenant;
        }

        return (string) config('tenants.default_settings.timezone', 'Asia/Kolkata');
    }

    /**
     * Celebration list window: explicit inclusive dates in parish TZ, or current Mon–Sun week.
     *
     * @return array{start: Carbon, end: Carbon, timezone: string}
     */
    public static function resolveCelebrationWindowBounds(int $tenantId, ?string $from, ?string $to): array
    {
        $timezone = self::timezoneForTenant($tenantId);

        if ($from !== null && $from !== '' && $to !== null && $to !== '') {
            $start = Carbon::parse($from, $timezone)->startOfDay();
            $end = Carbon::parse($to, $timezone)->endOfDay();

            return [
                'start' => $start,
                'end' => $end,
                'timezone' => $timezone,
            ];
        }

        return self::currentWeekBounds($tenantId);
    }

    /**
     * Monday 00:00:00 through Sunday 23:59:59 in the parish timezone.
     *
     * @return array{start: Carbon, end: Carbon, timezone: string}
     */
    public static function currentWeekBounds(int $tenantId): array
    {
        $timezone = self::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ];
    }

    /**
     * Today through the next N calendar days (inclusive) in the parish timezone.
     *
     * @return array{start: Carbon, end: Carbon, timezone: string}
     */
    public static function nextDaysBounds(int $tenantId, int $dayCount = 7): array
    {
        $dayCount = max(1, $dayCount);
        $timezone = self::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $start = $now->copy()->startOfDay();
        $end = $start->copy()->addDays($dayCount - 1)->endOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
        ];
    }

    /**
     * @return list<array{month: int, day: int, date: Carbon}>
     */
    public static function weekDayOccurrences(Carbon $weekStart, Carbon $weekEnd): array
    {
        $days = [];
        for ($cursor = $weekStart->copy(); $cursor->lte($weekEnd); $cursor->addDay()) {
            $days[] = [
                'month' => (int) $cursor->month,
                'day' => (int) $cursor->day,
                'date' => $cursor->copy(),
            ];
        }

        return $days;
    }

    public static function weekRangeLabel(Carbon $weekStart, Carbon $weekEnd): string
    {
        $startLabel = UserFacingDate::formatDayMonth($weekStart);
        $endLabel = UserFacingDate::formatDayMonth($weekEnd);

        return "{$startLabel} – {$endLabel}";
    }
}
