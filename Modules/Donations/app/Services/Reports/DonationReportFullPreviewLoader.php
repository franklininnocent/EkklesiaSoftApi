<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;

/**
 * Loads the full filtered dataset used by CSV, Excel, and PDF exports.
 */
final class DonationReportFullPreviewLoader
{
    public function __construct(
        private readonly DonationModuleReportService $moduleReportService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(int $tenantId, ReportFilter $filter): array
    {
        DonationReportCatalog::assertSupportedFilters($filter->reportType, $filter);

        $exportFilter = ReportFilter::fromValidated(
            $filter->reportType,
            array_merge($filter->raw, ['page' => 1, 'per_page' => 50000]),
        );

        return $this->moduleReportService->preview($tenantId, $exportFilter);
    }
}
