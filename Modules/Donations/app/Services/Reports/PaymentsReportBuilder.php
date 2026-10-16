<?php

namespace Modules\Donations\Services\Reports;

use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class PaymentsReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_PAYMENTS;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $query = ReportPaymentQuery::base($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();

        $rows = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($payment) => $this->mapPaymentRow($payment, $tenantId))
            ->all();

        $base = ReportPaymentQuery::base($tenantId, $filter);
        $collected = ReportPaymentQuery::sumSucceededAmount($base);
        $refunded = (string) (clone $base)->sum('refunded_amount');

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'payment_count' => $total,
                'collected_gross' => MoneyMath::toApiNumber($collected),
                'refunded_total' => MoneyMath::toApiNumber($refunded),
                'currency_code' => $this->currencyCode($tenantId),
            ],
            'footer' => [
                'payment_number' => $total.' payments',
                'amount' => MoneyMath::toApiNumber($collected),
            ],
            'meta' => [
                'amount_basis' => 'gross',
                'date_semantic' => 'payment_period',
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function csvHeaders(): array
    {
        return [
            'payment_number',
            'payment_date',
            'family_code',
            'family_name',
            'payer_name',
            'method',
            'status',
            'amount',
            'refunded_amount',
            'currency_code',
            'receipt_number',
            'is_anonymous',
            'source_type',
        ];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $query = ReportPaymentQuery::base($tenantId, $filter);
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $currency = $this->currencyCode($tenantId);

        $query->chunk(200, function ($payments) use ($handle, $tenantId, $currency, &$count): void {
            foreach ($payments as $payment) {
                $row = $this->mapPaymentRow($payment, $tenantId);
                SafeCsvWriter::putRow($handle, [
                    $row['payment_number'],
                    $row['payment_date'],
                    $row['family_code'],
                    $row['family_name'],
                    $row['payer_name'],
                    $row['method'],
                    $row['status'],
                    $row['amount'],
                    $row['refunded_amount'],
                    $currency,
                    $row['receipt_number'],
                    $row['is_anonymous'] ? 'yes' : 'no',
                    $row['source_type'],
                ]);
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'payment_number', 'label' => 'Payment #'],
            ['key' => 'payment_date', 'label' => 'Date'],
            ['key' => 'family_name', 'label' => 'Family'],
            ['key' => 'payer_name', 'label' => 'Payer'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'receipt_number', 'label' => 'Receipt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPaymentRow(object $payment, int $tenantId): array
    {
        $family = $payment->family;
        $anonymous = (bool) $payment->is_anonymous;

        return [
            'id' => $payment->id,
            'family_id' => $payment->family_id,
            'payment_number' => $payment->payment_number,
            'payment_date' => $payment->payment_date?->format('Y-m-d'),
            'family_code' => $anonymous ? '' : ($family?->family_code ?? ''),
            'family_name' => $anonymous ? 'Anonymous' : ($family?->family_name ?? ''),
            'payer_name' => $anonymous ? 'Anonymous' : ($payment->payer_name ?? ''),
            'method' => $payment->method,
            'status' => $payment->status,
            'amount' => MoneyMath::toApiNumber($payment->amount),
            'refunded_amount' => MoneyMath::toApiNumber($payment->refunded_amount ?? 0),
            'receipt_number' => $payment->receipt?->receipt_number,
            'is_anonymous' => $anonymous,
            'source_type' => $payment->source_type,
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['payment_number'],
            $row['payment_date'],
            $row['family_code'],
            $row['family_name'],
            $row['payer_name'],
            $row['method'],
            $row['status'],
            $row['amount'],
            $row['refunded_amount'],
            '',
            $row['receipt_number'],
            $row['is_anonymous'] ? 'yes' : 'no',
            $row['source_type'],
        ];
    }
}
