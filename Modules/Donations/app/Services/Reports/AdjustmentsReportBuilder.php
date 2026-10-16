<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Collection;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\PaymentReversal;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class AdjustmentsReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_ADJUSTMENTS;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $rows = ReportTableSort::sortCollection(
            $this->collectRows($tenantId, $filter),
            $filter,
            ['action_type', 'event_date', 'amount', 'reference', 'status'],
        );
        $total = $rows->count();
        $page = $filter->page();
        $perPage = $filter->perPage();
        $slice = $rows->forPage($page, $perPage)->values()->all();
        $amountTotal = '0';
        foreach ($rows as $row) {
            $amountTotal = MoneyMath::add($amountTotal, $row['amount'] ?? 0);
        }

        return [
            'columns' => $this->columns(),
            'rows' => $slice,
            'totals' => [
                'adjustment_count' => $total,
                'adjustment_total' => MoneyMath::toApiNumber($amountTotal),
            ],
            'footer' => [
                'action_type' => $total.' adjustments',
                'amount' => MoneyMath::toApiNumber($amountTotal),
            ],
            'meta' => $this->meta('adjustment_date', 'net'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['action_type', 'event_date', 'amount', 'reference', 'status'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($this->collectRows($tenantId, $filter) as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectRows(int $tenantId, ReportFilter $filter): Collection
    {
        $range = $this->resolveDateRange($tenantId, $filter);
        $action = $filter->get('action_type');
        $search = $this->searchTerm($filter);
        $like = $search === null ? null : '%'.addcslashes($search, '%_\\').'%';
        $rows = collect();

        if ($action === null || $action === '' || $action === 'refund') {
            $refunds = DonationRefund::query()->forTenant($tenantId)->orderByDesc('refund_date');
            if ($like !== null) {
                $likeOp = $this->likeOperator();
                $refunds->where(function ($inner) use ($like, $likeOp): void {
                    $inner->where('status', $likeOp, $like)
                        ->orWhereHas('payment', function ($payment) use ($like, $likeOp): void {
                            $payment->where('payer_name', $likeOp, $like)
                                ->orWhere('payment_number', $likeOp, $like)
                                ->orWhereHas('family', fn ($family) => $family->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
                        });
                });
            }
            if ($range !== null) {
                $refunds->whereDate('refund_date', '>=', $range->dateFrom)
                    ->whereDate('refund_date', '<=', $range->collectionEnd);
            }
            foreach ($refunds->get() as $refund) {
                $rows->push([
                    'action_type' => 'refund',
                    'event_date' => $refund->refund_date?->format('Y-m-d'),
                    'amount' => MoneyMath::toApiNumber($refund->amount),
                    'reference' => $refund->payment_id,
                    'status' => $refund->status,
                ]);
            }
        }

        if ($action === null || $action === '' || $action === 'reversal') {
            $reversals = PaymentReversal::query()->forTenant($tenantId)->orderByDesc('reversed_at');
            if ($like !== null) {
                $likeOp = $this->likeOperator();
                $reversals->whereHas('payment', function ($payment) use ($like, $likeOp): void {
                    $payment->where('payer_name', $likeOp, $like)
                        ->orWhere('payment_number', $likeOp, $like)
                        ->orWhereHas('family', fn ($family) => $family->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
                });
            }
            if ($range !== null) {
                $reversals->whereDate('reversed_at', '>=', $range->dateFrom)
                    ->whereDate('reversed_at', '<=', $range->collectionEnd);
            }
            foreach ($reversals->get() as $reversal) {
                $rows->push([
                    'action_type' => 'reversal',
                    'event_date' => $reversal->reversed_at?->format('Y-m-d'),
                    'amount' => MoneyMath::toApiNumber($reversal->amount),
                    'reference' => $reversal->payment_id,
                    'status' => 'completed',
                ]);
            }
        }

        if ($action === null || $action === '' || $action === 'waiver') {
            $waived = ContributionDue::query()
                ->forTenant($tenantId)
                ->where('status', 'waived')
                ->orderByDesc('updated_at');
            if ($like !== null) {
                $likeOp = $this->likeOperator();
                $waived->whereHas('family', fn ($family) => $family->where('family_name', $likeOp, $like)->orWhere('family_code', $likeOp, $like));
            }
            if ($range !== null) {
                $waived->whereDate('updated_at', '>=', $range->dateFrom)
                    ->whereDate('updated_at', '<=', $range->collectionEnd);
            }
            foreach ($waived->get() as $due) {
                $rows->push([
                    'action_type' => 'waiver',
                    'event_date' => $due->status_changed_at?->format('Y-m-d') ?? $due->updated_at?->format('Y-m-d'),
                    'amount' => MoneyMath::toApiNumber($due->amount_due),
                    'reference' => $due->id,
                    'status' => 'waived',
                ]);
            }
        }

        return $rows->sortByDesc('event_date')->values();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'action_type', 'label' => 'Type'],
            ['key' => 'event_date', 'label' => 'Date'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'status', 'label' => 'Status'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['action_type'],
            $row['event_date'],
            $row['amount'],
            $row['reference'],
            $row['status'],
        ];
    }
}
