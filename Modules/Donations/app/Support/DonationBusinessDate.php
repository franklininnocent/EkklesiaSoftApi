<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Modules\Tenants\Support\TenantTimezone;

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
        return TenantTimezone::forTenantId($tenantId);
    }

    /**
     * @return array{start: string, end: string}
     */
    public static function currentFinancialYearBounds(int $tenantId, ?string $referenceDate = null): array
    {
        return app(ChurchFinancialPeriodResolver::class)->currentFinancialYearBounds($tenantId, $referenceDate);
    }
}
