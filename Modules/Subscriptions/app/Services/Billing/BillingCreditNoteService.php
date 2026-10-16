<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\MoneyMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingCreditNote;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingRefund;

final class BillingCreditNoteService
{
    public function __construct(private readonly BillingAuditService $audit) {}

    public function issueForInvoice(
        BillingInvoice $invoice,
        string $amount,
        string $reason,
        User $actor,
        ?BillingRefund $refund = null,
    ): BillingCreditNote {
        $normalized = MoneyMath::normalize($amount);
        if (MoneyMath::compare($normalized, '0') <= 0) {
            throw SubscriptionException::changeNotAllowed('Credit note amount must be positive.');
        }

        if (MoneyMath::compare($normalized, MoneyMath::normalize((string) $invoice->gross_amount)) > 0) {
            throw SubscriptionException::changeNotAllowed('Credit note cannot exceed invoice total.');
        }

        return DB::transaction(function () use ($invoice, $normalized, $reason, $actor, $refund) {
            $note = BillingCreditNote::query()->create([
                'billing_invoice_id' => $invoice->id,
                'billing_refund_id' => $refund?->id,
                'credit_note_number' => 'CN-'.strtoupper(Str::random(10)),
                'amount' => $normalized,
                'currency_code' => $invoice->currency_code,
                'issued_at' => now(),
            ]);

            $this->audit->record(
                'credit_note_issued',
                $actor->id,
                null,
                (int) $invoice->tenant_id,
                $reason,
                null,
                ['credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'amount' => $normalized],
            );

            return $note;
        });
    }
}
