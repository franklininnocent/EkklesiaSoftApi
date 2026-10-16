<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;

final class DonationModuleReportService
{
    public function __construct(
        private readonly DonationReportBuilderRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(int $tenantId, ReportFilter $filter): array
    {
        DonationReportCatalog::assertSupportedFilters($filter->reportType, $filter);

        return $this->registry->resolve($filter->reportType)->preview($tenantId, $filter);
    }

    public function streamExportCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        DonationReportCatalog::assertSupportedFilters($filter->reportType, $filter);

        return $this->registry->resolve($filter->reportType)->streamCsv($tenantId, $filter, $handle);
    }
}
