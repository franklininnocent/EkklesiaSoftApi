<?php

namespace Modules\Tenants\Services;

use Carbon\Carbon;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ChurchFinancialPeriod;
use Modules\Tenants\Support\CountryFiscalYearCatalog;

class ChurchFinancialPeriodResolver
{
    /** @var array<int, ChurchFinancialPeriod> */
    private array $currentMemo = [];

    public function currentFiscalYear(int $tenantId, ?string $referenceDate = null): ChurchFinancialPeriod
    {
        if ($referenceDate === null && isset($this->currentMemo[$tenantId])) {
            return $this->currentMemo[$tenantId];
        }

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $reference = $referenceDate
            ? Carbon::parse($referenceDate, $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();

        [$month, $day] = $this->resolveFyStartMonthDay($tenantId);
        $bounds = $this->financialYearBoundsForStart($reference, $month, $day, $timezone);
        $period = $this->periodFromBounds($bounds['start'], $bounds['end'], $timezone, $month, $day);

        if ($referenceDate === null) {
            $this->currentMemo[$tenantId] = $period;
        }

        return $period;
    }

    public function previousFiscalYear(int $tenantId, ?string $referenceDate = null): ChurchFinancialPeriod
    {
        $current = $this->currentFiscalYear($tenantId, $referenceDate);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $prevStart = Carbon::parse($current->start, $timezone)->subYear()->toDateString();
        $prevEnd = Carbon::parse($current->start, $timezone)->subDay()->toDateString();

        return $this->periodFromBounds($prevStart, $prevEnd, $timezone, $current->startMonth, $current->startDay);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function currentFinancialYearBounds(int $tenantId, ?string $referenceDate = null): array
    {
        $period = $this->currentFiscalYear($tenantId, $referenceDate);

        return ['start' => $period->start, 'end' => $period->end];
    }

    /**
     * Parse a user fiscal-year label such as "2025-26", "FY 2025–26", or "2025".
     *
     * @return array{start: string, end: string}|null
     */
    public function boundsForFiscalYearInput(int $tenantId, string $input): ?array
    {
        $normalized = str_replace(['–', '—', ' '], ['-', '-', ''], trim($input));
        $normalized = preg_replace('/^fy/i', '', $normalized) ?? $normalized;
        if (! preg_match('/^(\d{4})(?:-(\d{2}|\d{4}))?$/', $normalized, $matches)) {
            return null;
        }

        $startYear = (int) $matches[1];
        if (isset($matches[2]) && $matches[2] !== '') {
            $endToken = (int) $matches[2];
            $expectedEnd = $endToken < 100 ? $endToken : $endToken % 100;
            if ($expectedEnd !== ($startYear + 1) % 100) {
                return null;
            }
        }

        [$month, $day] = $this->resolveFyStartMonthDay($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $safeDay = min($day, Carbon::create($startYear, $month, 1, 0, 0, 0, $timezone)->daysInMonth);
        $start = Carbon::create($startYear, $month, $safeDay, 0, 0, 0, $timezone)->startOfDay();
        $end = $start->copy()->addYear()->subDay();

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    /**
     * @return array{month: int, day: int, source: string}
     */
    public function resolveFyStartConfig(int $tenantId): array
    {
        [$month, $day, $source] = $this->resolveFyStartMonthDayWithSource($tenantId);

        return ['month' => $month, 'day' => $day, 'source' => $source];
    }

    public function syncDerivedFyStartColumns(int $tenantId): void
    {
        $settings = DonationSetting::forTenant($tenantId)->first();
        if ($settings === null) {
            return;
        }

        if ((string) ($settings->financial_year_source ?? 'country') !== 'country') {
            return;
        }

        [$month, $day] = $this->resolveFyStartMonthDay($tenantId);
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $dayStr = str_pad((string) $day, 2, '0', STR_PAD_LEFT);

        if (
            (string) $settings->financial_year_start_month === $monthStr
            && (string) $settings->financial_year_start_day === $dayStr
        ) {
            return;
        }

        $settings->updateQuietly([
            'financial_year_start_month' => $monthStr,
            'financial_year_start_day' => $dayStr,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function resolveFyStartMonthDay(int $tenantId): array
    {
        [$month, $day] = $this->resolveFyStartMonthDayWithSource($tenantId);

        return [$month, $day];
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function resolveFyStartMonthDayWithSource(int $tenantId): array
    {
        $settings = DonationSetting::forTenant($tenantId)->first();
        $source = (string) ($settings?->financial_year_source ?? 'country');

        if ($settings !== null && $source === 'tenant') {
            return [
                (int) $settings->financial_year_start_month,
                (int) $settings->financial_year_start_day,
                'tenant',
            ];
        }

        $tenant = Tenant::query()->find($tenantId);
        $country = $tenant ? $this->resolveCountryForTenant($tenant) : null;
        if ($country !== null && $country->fiscal_year_start_month && $country->fiscal_year_start_day) {
            return [
                (int) $country->fiscal_year_start_month,
                (int) $country->fiscal_year_start_day,
                'country',
            ];
        }

        $catalog = CountryFiscalYearCatalog::defaultForIso2($country?->iso2);

        return [$catalog['month'], $catalog['day'], 'country'];
    }

    /**
     * @return array{start: string, end: string}
     */
    private function financialYearBoundsForStart(Carbon $reference, int $month, int $day, string $timezone): array
    {
        $fyStart = $this->financialYearStartOnOrBefore($reference, $month, $day, $timezone);
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

    private function financialYearStartOnOrBefore(Carbon $reference, int $month, int $day, string $timezone): Carbon
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

    private function periodFromBounds(
        string $start,
        string $end,
        string $timezone,
        int $startMonth,
        int $startDay
    ): ChurchFinancialPeriod {
        $startCarbon = Carbon::parse($start, $timezone)->startOfDay();
        $endCarbon = Carbon::parse($end, $timezone)->startOfDay();

        return new ChurchFinancialPeriod(
            key: (string) $startCarbon->year,
            label: $this->buildLabel($startCarbon, $endCarbon),
            start: $start,
            end: $end,
            startMonth: $startMonth,
            startDay: $startDay,
        );
    }

    private function buildLabel(Carbon $start, Carbon $end): string
    {
        $startYear = (int) $start->year;
        $endYear = (int) $end->year;
        if ($startYear === $endYear) {
            return 'FY '.$startYear;
        }

        return sprintf('FY %d–%02d', $startYear, $endYear % 100);
    }

    private function resolveCountryForTenant(Tenant $tenant): ?Country
    {
        $addresses = $tenant->addresses()
            ->where('active', 1)
            ->whereNotNull('country_id')
            ->with('country')
            ->get();

        foreach (['official', 'primary'] as $type) {
            $match = $addresses->firstWhere('address_type', $type);
            if ($match instanceof Address) {
                $country = $match->getRelation('country');
                if ($country instanceof Country) {
                    return $country;
                }
            }
        }

        foreach ($addresses as $address) {
            $country = $address->getRelation('country');
            if ($country instanceof Country) {
                return $country;
            }
        }

        return null;
    }
}
