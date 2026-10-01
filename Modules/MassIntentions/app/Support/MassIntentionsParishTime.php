<?php

namespace Modules\MassIntentions\Support;

use Carbon\Carbon;
use Modules\Donations\Support\DonationBusinessDate;

/**
 * Parish-calendar windows shared by dashboard KPIs and list filters.
 */
final class MassIntentionsParishTime
{
    public static function timezone(int $tenantId): string
    {
        return DonationBusinessDate::timezoneForTenant($tenantId);
    }

    /**
     * Sunday–Saturday bounds containing `dateYmd` in the parish timezone.
     *
     * @return array{sunday: string, saturday: string}
     */
    public static function weekBoundsContaining(int $tenantId, string $dateYmd): array
    {
        $tz = self::timezone($tenantId);
        $d = Carbon::parse($dateYmd, $tz)->startOfDay();
        $sunday = $d->copy()->subDays($d->dayOfWeek);
        $saturday = $sunday->copy()->addDays(6);

        return [
            'sunday' => $sunday->toDateString(),
            'saturday' => $saturday->toDateString(),
        ];
    }

    /**
     * Inclusive parish calendar start, exclusive parish calendar end → UTC datetimes for timestamp columns.
     *
     * @return array{0: string, 1: string}
     */
    public static function timestampRangeUtc(int $tenantId, string $fromInclusiveYmd, string $toExclusiveYmd): array
    {
        $tz = self::timezone($tenantId);
        $start = Carbon::parse($fromInclusiveYmd, $tz)->startOfDay()->utc();
        $end = Carbon::parse($toExclusiveYmd, $tz)->startOfDay()->utc();

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }
}
