<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class AllocationsReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_ALLOCATIONS;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $query = $this->baseQuery($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $query->forPage($page, $perPage)->get()->map(fn ($row) => [
            'payment_number' => $row->payment?->payment_number,
            'payment_date' => $row->payment?->payment_date?->format('Y-m-d'),
            'allocatable_type' => $row->allocatable_type,
            'allocatable_id' => $row->allocatable_id,
            'amount' => MoneyMath::toApiNumber($row->amount),
        ])->all();

        return [
            'columns' => [
                ['key' => 'payment_number', 'label' => 'Payment #'],
                ['key' => 'payment_date', 'label' => 'Date'],
                ['key' => 'allocatable_type', 'label' => 'Destination'],
                ['key' => 'amount', 'label' => 'Amount'],
            ],
            'rows' => $rows,
            'totals' => [
                'allocation_count' => $total,
                'allocation_total' => MoneyMath::toApiNumber((clone $query)->sum('amount')),
            ],
            'footer' => [
                'payment_number' => $total.' allocations',
                'amount' => MoneyMath::toApiNumber((clone $query)->sum('amount')),
            ],
            'meta' => $this->meta('payment_period', 'gross'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['payment_number', 'payment_date', 'allocatable_type', 'allocatable_id', 'amount'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $this->baseQuery($tenantId, $filter)->chunk(200, function ($rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                SafeCsvWriter::putRow($handle, [
                    $row->payment?->payment_number,
                    $row->payment?->payment_date?->format('Y-m-d'),
                    $row->allocatable_type,
                    $row->allocatable_id,
                    MoneyMath::toApiNumber($row->amount),
                ]);
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    private function baseQuery(int $tenantId, ReportFilter $filter)
    {
        $paymentIds = ReportPaymentQuery::base($tenantId, $filter)->select('id');

        $query = PaymentAllocation::query()
            ->forTenant($tenantId)
            ->whereIn('payment_id', $paymentIds)
            ->with(['payment:id,payment_number,payment_date']);

        return ReportTableSort::applyToQuery($query, $filter, [
            'amount' => 'amount',
            'allocatable_type' => 'allocatable_type',
            'allocatable_id' => 'allocatable_id',
        ], static function ($builder): void {
            $builder->orderByDesc('created_at')->orderByDesc('id');
        });
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['payment_number'],
            $row['payment_date'],
            $row['allocatable_type'],
            $row['allocatable_id'],
            $row['amount'],
        ];
    }
}
