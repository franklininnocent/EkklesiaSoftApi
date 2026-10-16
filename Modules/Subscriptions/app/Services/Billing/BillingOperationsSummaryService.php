<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingRefund;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Models\TenantBillingAgreement;

final class BillingOperationsSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(): array
    {
        $invoiced = $this->sumByCurrency('billing_invoices', 'gross_amount');

        $collectedOnline = DB::table('billing_receipts')
            ->select('currency_code', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->where('source', 'online')
            ->where('status', '!=', 'reversed')
            ->groupBy('currency_code')
            ->get()
            ->keyBy('currency_code');

        $collectedOffline = DB::table('billing_receipts')
            ->select('currency_code', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->where('source', 'offline')
            ->where('status', '!=', 'reversed')
            ->groupBy('currency_code')
            ->get()
            ->keyBy('currency_code');

        $pendingClaims = BillingOfflineClaim::query()
            ->whereIn('status', [
                BillingOfflineClaim::STATUS_PENDING,
                BillingOfflineClaim::STATUS_INFO_REQUIRED,
            ])
            ->count();

        $partialInvoices = BillingInvoice::query()
            ->where('status', BillingInvoice::STATUS_PARTIALLY_PAID)
            ->count();

        $awaitingPayment = BillingOrder::query()
            ->whereIn('status', [
                BillingOrder::STATUS_AWAITING_PAYMENT,
                BillingOrder::STATUS_PAYMENT_PENDING,
            ])
            ->count();

        $refundPending = BillingRefund::query()->where('status', 'requested')->count();
        $webhookFailed = BillingWebhookEvent::query()->where('processing_status', 'failed')->count();
        $activeAgreements = TenantBillingAgreement::query()->where('active', true)->count();

        return [
            'invoiced_by_currency' => $invoiced,
            'collected_online_by_currency' => $collectedOnline,
            'collected_offline_by_currency' => $collectedOffline,
            'pending_offline_claims' => $pendingClaims,
            'partially_paid_invoices' => $partialInvoices,
            'orders_awaiting_payment' => $awaitingPayment,
            'refunds_requested' => $refundPending,
            'webhook_events_failed' => $webhookFailed,
            'tenants_with_active_agreements' => $activeAgreements,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: mixed}>  $filters
     * @return array<string, array{total: string, count: int}>
     */
    private function sumByCurrency(string $table, string $amountColumn, array $filters = []): array
    {
        $q = DB::table($table)->select('currency_code', DB::raw("SUM({$amountColumn}) as total"), DB::raw('COUNT(*) as count'));
        foreach ($filters as $filter) {
            $q->where($filter[0], $filter[1], $filter[2]);
        }

        $out = [];
        foreach ($q->groupBy('currency_code')->get() as $row) {
            $out[(string) $row->currency_code] = [
                'total' => (string) $row->total,
                'count' => (int) $row->count,
            ];
        }

        return $out;
    }
}
