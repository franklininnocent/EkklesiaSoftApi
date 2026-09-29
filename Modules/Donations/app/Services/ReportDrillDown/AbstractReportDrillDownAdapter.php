<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;

abstract class AbstractReportDrillDownAdapter
{
    /**
     * @return array<int, string>
     */
    public function supportedDimensions(): array
    {
        return ['family'];
    }

    /**
     * @return array<int, string>
     */
    public function supportedFilterKeys(): array
    {
        return ['bcc_id'];
    }

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function supportedSorts(): array
    {
        return ['outstanding_amount'];
    }

    /**
     * @return array<int, string>
     */
    public function supportedSliceIdsForTenant(int $tenantId, ?DashboardDateRange $range = null): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function dashboardRange(int $tenantId, array $validated): ?DashboardDateRange
    {
        return DashboardDateRange::tryFromInput($tenantId, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function dashboardBccFilter(int $tenantId, array $validated): DashboardBccFilter
    {
        return DashboardBccFilter::resolve($tenantId, $validated['bcc_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function dashboardProjectFilter(int $tenantId, array $validated): DashboardProjectFilter
    {
        return DashboardProjectFilter::resolve($tenantId, $validated['project_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function enrichContext(int $tenantId, array $context): array
    {
        $businessDate = DonationBusinessDate::today($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);

        return array_merge([
            'business_date' => $businessDate,
            'timezone' => $timezone,
            'clock' => 'parish_business',
            'as_of' => $businessDate,
            'supported_sorts' => $this->supportedSorts(),
        ], $context);
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPagination(int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => 0,
            'last_page' => 1,
            'data' => [],
        ];
    }
}
