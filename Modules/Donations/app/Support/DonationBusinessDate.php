<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use Modules\Donations\Models\DonationSetting;
use Modules\Tenants\Models\Tenant;

final class DonationBusinessDate
{
    public static function today(int $tenantId): string
    {
        $timezone = self::timezoneForTenant($tenantId);

        return Carbon::now($timezone)->toDateString();
    }

    public static function monthStart(int $tenantId): string
    {
        $timezone = self::timezoneForTenant($tenantId);

        return Carbon::now($timezone)->startOfMonth()->toDateString();
    }

    public static function monthEnd(int $tenantId): string
    {
        $timezone = self::timezoneForTenant($tenantId);

        return Carbon::now($timezone)->endOfMonth()->toDateString();
    }

    public static function subDays(int $tenantId, int $days): string
    {
        $timezone = self::timezoneForTenant($tenantId);

        return Carbon::now($timezone)->subDays($days)->toDateString();
    }

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
     * @return array{start: string, end: string}
     */
    public static function currentFinancialYearBounds(int $tenantId, ?string $referenceDate = null): array
    {
        $timezone = self::timezoneForTenant($tenantId);
        $reference = $referenceDate
            ? Carbon::parse($referenceDate, $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();

        $settings = DonationSetting::forTenant($tenantId)->first();
        $month = (int) ($settings?->financial_year_start_month ?? 1);
        $day = (int) ($settings?->financial_year_start_day ?? 1);

        $fyStart = self::financialYearStartOnOrBefore($reference, $month, $day, $timezone);
        $fyEnd = $fyStart->copy()->addYear()->subDay();

        if ($reference->gt($fyEnd)) {
            $fyStart = $fyStart->copy()->addYear();
            $fyEnd = $fyStart->copy()->addYear()->subDay();
        }

        return [
            'start' => $fyStart->toDateString(),
            'end' => $fyEnd->toDateString(),
        ];
    }

    private static function financialYearStartOnOrBefore(Carbon $reference, int $month, int $day, string $timezone): Carbon
    {
        $year = (int) $reference->year;
        $safeDay = min($day, Carbon::create($year, $month, 1)->daysInMonth);
        $candidate = Carbon::create($year, $month, $safeDay, 0, 0, 0, $timezone)->startOfDay();

        if ($reference->lt($candidate)) {
            $year--;
            $safeDay = min($day, Carbon::create($year, $month, 1)->daysInMonth);
            $candidate = Carbon::create($year, $month, $safeDay, 0, 0, 0, $timezone)->startOfDay();
        }

        return $candidate;
    }
}
