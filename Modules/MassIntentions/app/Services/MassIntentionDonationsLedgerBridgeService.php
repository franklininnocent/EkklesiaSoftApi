<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tenants\Services\ChurchCurrencyResolver;

/**
 * Export shape for a future shared Donations ledger import.
 * Mass intentions keep their own receipts until Donations owns mass offerings.
 */
class MassIntentionDonationsLedgerBridgeService
{
    public function __construct(
        private readonly ChurchCurrencyResolver $currencyResolver,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $tenantId): array
    {
        $currency = $this->currencyResolver->currencyCodeForTenantId($tenantId) ?? '';

        return DB::table('mass_intention_offering_receipts as r')
            ->join('mass_intention_offerings as o', 'o.id', '=', 'r.offering_id')
            ->join('mass_intention_requests as req', 'req.id', '=', 'o.request_id')
            ->where('r.tenant_id', $tenantId)
            ->whereNull('r.voided_at')
            ->orderBy('r.received_on')
            ->orderBy('r.receipt_number')
            ->select([
                'r.id as mass_receipt_id',
                'r.receipt_number',
                'r.amount',
                'r.payment_method',
                'r.received_on',
                'req.id as mass_intention_request_id',
                'req.beneficiary_name',
                'o.currency_code',
            ])
            ->get()
            ->map(fn ($row) => [
                'source' => 'mass_intentions',
                'mass_receipt_id' => $row->mass_receipt_id,
                'receipt_number' => $row->receipt_number,
                'amount' => $row->amount,
                'currency_code' => $row->currency_code ?: $currency,
                'payment_method' => $row->payment_method,
                'received_on' => $row->received_on,
                'mass_intention_request_id' => $row->mass_intention_request_id,
                'beneficiary_name' => $row->beneficiary_name,
                'donations_ledger_status' => 'pending_port',
            ])
            ->all();
    }
}
