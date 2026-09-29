<?php

namespace Modules\Donations\Contracts;

use Modules\Donations\Support\Reports\ReportFilter;

interface DonationReportBuilderContract
{
    public function reportType(): string;

    /**
     * @return array{
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     meta: array<string, mixed>,
     *     pagination: array{page: int, per_page: int, total: int}
     * }
     */
    public function preview(int $tenantId, ReportFilter $filter): array;

    /**
     * @param  resource  $handle
     */
    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int;

    /**
     * @return list<string>
     */
    public function csvHeaders(): array;
}
