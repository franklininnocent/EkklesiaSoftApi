<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class ReceiptsReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_RECEIPTS;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $query = $this->baseQuery($tenantId, $filter);
        $total = (clone $query)->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $rows = $query->forPage($page, $perPage)->get()->map(fn ($r) => $this->mapRow($r))->all();
        $activeAmount = $this->sumActiveReceiptAmount($tenantId, $filter);
        $voidCount = (clone $this->baseQuery($tenantId, $filter))->where('is_void', true)->count();

        return [
            'columns' => $this->columns(),
            'rows' => $rows,
            'totals' => [
                'receipt_count' => $total,
                'void_count' => $voidCount,
                'active_amount' => $activeAmount,
            ],
            'footer' => [
                'receipt_number' => $total.' receipts',
                'amount' => $activeAmount,
                'is_void' => $voidCount.' void',
            ],
            'meta' => $this->meta('receipt_issued', 'gross'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['receipt_number', 'issued_on', 'is_void', 'payer_name', 'family_name', 'amount'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        $this->baseQuery($tenantId, $filter)->chunk(200, function ($rows) use ($handle, &$count): void {
            foreach ($rows as $row) {
                SafeCsvWriter::putRow($handle, $this->mapRowToCsv($this->mapRow($row)));
                $count++;
            }
        });

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    private function sumActiveReceiptAmount(int $tenantId, ReportFilter $filter): float
    {
        $running = '0';
        $this->baseQuery($tenantId, $filter)
            ->where('is_void', false)
            ->chunk(200, function ($receipts) use (&$running): void {
                foreach ($receipts as $receipt) {
                    $mapped = $this->mapRow($receipt);
                    $running = MoneyMath::add($running, $mapped['amount'] ?? 0);
                }
            });

        return MoneyMath::toApiNumber($running);
    }

    /**
     * @return Builder<DonationReceipt>
     */
    private function baseQuery(int $tenantId, ReportFilter $filter): Builder
    {
        $query = DonationReceipt::query()
            ->forTenant($tenantId)
            ->with(['payment.family:id,family_name']);

        $status = $filter->get('status');
        if (in_array($status, ['void', 'voided'], true)) {
            $query->where('is_void', true);
        } elseif (in_array($status, ['active', 'issued'], true)) {
            $query->where('is_void', false);
        } elseif (is_string($status) && $status !== '') {
            $query->whereRaw('1 = 0');
        }

        $range = $this->resolveDateRange($tenantId, $filter);
        if ($range !== null) {
            $query->whereDate('issued_on', '>=', $range->dateFrom)
                ->whereDate('issued_on', '<=', $range->collectionEnd);
        }

        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->whereHas('payment.family', fn (Builder $family) => $bcc->applyToFamilyQuery($family, $tenantId));
        }

        $search = $this->searchTerm($filter);
        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $likeOp = $this->likeOperator();
                $inner->where('receipt_number', $likeOp, $like)
                    ->orWhereHas('payment', function (Builder $payment) use ($like, $likeOp): void {
                        $payment->where('payer_name', $likeOp, $like)
                            ->orWhere('payment_number', $likeOp, $like)
                            ->orWhereHas('family', fn (Builder $family) => $family->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
                    });
            });
        }

        return ReportTableSort::applyToQuery($query, $filter, [
            'receipt_number' => 'receipt_number',
            'issued_on' => 'issued_on',
            'is_void' => 'is_void',
        ], static function (Builder $builder): void {
            $builder->orderByDesc('issued_on')->orderByDesc('id');
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'receipt_number', 'label' => 'Receipt #'],
            ['key' => 'issued_on', 'label' => 'Issued'],
            ['key' => 'payer_name', 'label' => 'Payer'],
            ['key' => 'family_name', 'label' => 'Family'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'is_void', 'label' => 'Void'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(DonationReceipt $receipt): array
    {
        $payment = $receipt->payment;
        $snapshot = is_array($receipt->snapshot) ? $receipt->snapshot : [];
        $amount = $snapshot['amount'] ?? $payment?->amount;

        return [
            'receipt_number' => $receipt->receipt_number,
            'issued_on' => $receipt->issued_on?->format('Y-m-d'),
            'is_void' => (bool) $receipt->is_void,
            'payer_name' => $payment?->payer_name,
            'family_name' => $payment?->family?->family_name,
            'amount' => $amount,
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['receipt_number'],
            $row['issued_on'],
            $row['is_void'] ? 'yes' : 'no',
            $row['payer_name'],
            $row['family_name'],
            $row['amount'],
        ];
    }
}
