<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Services\DonationReportService;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class ParishComparisonExportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_PARISH_COMPARISON;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $report = app(DonationReportService::class)->buildParishComparisonReport($tenantId);
        $rows = [];
        if ($report['available'] ?? false) {
            foreach ($report['parishes'] ?? [] as $parish) {
                $rows[] = [
                    'name' => $parish['name'],
                    'health_score' => $parish['health_score'],
                    'participation_rate' => $parish['participation_rate'],
                    'current_month_collected' => $parish['current_month_collected'],
                    'total_collected' => $parish['total_collected'],
                    'pending_dues' => $parish['pending_dues'],
                ];
            }
        }

        $comparable = (bool) ($report['money_comparable'] ?? false);
        $money = $comparable
            ? $this->sumMoneyColumns($rows, ['current_month_collected', 'total_collected', 'pending_dues'])
            : [];

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'parish_count' => count($rows),
                'money_comparable' => $comparable,
                'current_month_collected' => $money['current_month_collected'] ?? null,
                'total_collected' => $money['total_collected'] ?? null,
                'pending_dues' => $money['pending_dues'] ?? null,
            ],
            'footer' => array_merge(
                ['name' => count($rows).' parishes'],
                $comparable ? $money : [],
            ),
            'meta' => $this->meta('payment_period', 'gross', [
                'comparison_available' => $report['available'] ?? false,
                'reason' => ($report['available'] ?? false)
                    ? null
                    : ($report['message'] ?? 'Parish comparison is not available for this account.'),
                'money_comparable' => $report['money_comparable'] ?? false,
                'period' => $report['period'] ?? null,
                'population' => $report['scope']['parish_count'] ?? count($rows),
                'narrative' => $report['narrative'] ?? null,
            ]),
            'pagination' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows)],
        ];
    }

    public function csvHeaders(): array
    {
        return ['parish', 'health_score', 'participation_rate', 'this_month_collected', 'total_collected', 'outstanding'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $preview = $this->preview($tenantId, $filter);
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($preview['rows'] as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        return $count;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Parish'],
            ['key' => 'health_score', 'label' => 'Stewardship'],
            ['key' => 'participation_rate', 'label' => 'Participation'],
            ['key' => 'current_month_collected', 'label' => 'This month'],
            ['key' => 'total_collected', 'label' => 'Collected'],
            ['key' => 'pending_dues', 'label' => 'Outstanding'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['name'],
            $row['health_score'],
            $row['participation_rate'],
            $row['current_month_collected'],
            $row['total_collected'],
            $row['pending_dues'],
        ];
    }
}
