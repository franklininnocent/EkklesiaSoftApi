<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class CollectionsByMonthReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_COLLECTIONS_BY_MONTH;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $rows = ReportTableSort::sortArrayRows(
            $this->aggregateRows($tenantId, $filter),
            $filter,
            ['month', 'collected'],
        );

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'month_count' => count($rows),
                'collected_total' => MoneyMath::toApiNumber(collect($rows)->reduce(
                    fn (string $carry, array $row) => MoneyMath::add($carry, $row['collected'] ?? 0),
                    '0',
                )),
            ],
            'footer' => [
                'month' => count($rows).' months',
                'collected' => MoneyMath::toApiNumber(collect($rows)->reduce(
                    fn (string $carry, array $row) => MoneyMath::add($carry, $row['collected'] ?? 0),
                    '0',
                )),
            ],
            'meta' => ['date_semantic' => 'payment_period', 'amount_basis' => 'gross'],
            'pagination' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows)],
        ];
    }

    public function csvHeaders(): array
    {
        return ['month', 'collected'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($this->aggregateRows($tenantId, $filter) as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(int $tenantId, ReportFilter $filter): array
    {
        $query = DB::table('donation_payments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'succeeded')
            ->whereNull('deleted_at');

        $period = $this->constrainedPeriod($tenantId, $filter);
        if ($period !== null) {
            $query->whereDate('payment_date', '>=', $period['start'])
                ->whereDate('payment_date', '<=', $period['end']);
        }

        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereExists(function ($sub) use ($bcc, $tenantId): void {
                $sub->selectRaw('1')
                    ->from('families as f')
                    ->whereColumn('f.id', 'donation_payments.family_id')
                    ->where('f.tenant_id', $tenantId);
                if ($bcc->unassignedOnly) {
                    $sub->whereNull('f.bcc_id');
                } elseif ($bcc->bccId !== null) {
                    $sub->where('f.bcc_id', $bcc->bccId);
                }
            });
        }

        $driver = DB::connection()->getDriverName();
        $monthExpr = match ($driver) {
            'pgsql' => "TO_CHAR(payment_date, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', payment_date)",
            default => "DATE_FORMAT(payment_date, '%Y-%m')",
        };

        return collect(
            $query->selectRaw("{$monthExpr} as month, SUM(amount) as collected")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
        )->map(fn ($row) => [
            'month' => $row->month,
            'collected' => MoneyMath::toApiNumber((string) $row->collected),
        ])->all();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'month', 'label' => 'Month'],
            ['key' => 'collected', 'label' => 'Collected'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [$row['month'], $row['collected']];
    }
}
