<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\MoneyMath;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingRefund;

final class BillingRefundService
{
    public function __construct(
        private readonly RazorpayGatewayClient $razorpay,
        private readonly BillingAuditService $audit,
        private readonly BillingCreditNoteService $creditNotes,
    ) {}

    public function request(
        BillingPaymentAttempt $attempt,
        string $amount,
        string $reason,
        User $requester,
    ): BillingRefund {
        if ($attempt->status !== BillingPaymentAttempt::STATUS_CAPTURED) {
            throw SubscriptionException::invalidState('Only captured payments can be refunded.');
        }

        $existing = BillingRefund::query()
            ->where('billing_payment_attempt_id', $attempt->id)
            ->whereIn('status', [BillingRefund::STATUS_REQUESTED, BillingRefund::STATUS_APPROVED, BillingRefund::STATUS_PROCESSING, BillingRefund::STATUS_COMPLETED])
            ->exists();
        if ($existing) {
            throw SubscriptionException::invalidState('A refund is already in progress for this payment.');
        }

        $normalized = MoneyMath::normalize($amount);
        if (MoneyMath::compare($normalized, (string) $attempt->amount) > 0) {
            throw SubscriptionException::changeNotAllowed('Refund amount exceeds payment amount.');
        }

        $order = $attempt->order;
        if ($order === null) {
            throw SubscriptionException::invalidState('Order not found for payment.');
        }

        return BillingRefund::query()->create([
            'tenant_id' => $order->tenant_id,
            'billing_payment_attempt_id' => $attempt->id,
            'billing_receipt_id' => null,
            'amount' => $normalized,
            'currency_code' => $attempt->currency_code,
            'status' => BillingRefund::STATUS_REQUESTED,
            'reason' => $reason,
            'requested_by' => $requester->id,
        ]);
    }

    public function approveAndProcess(BillingRefund $refund, User $approver, string $reason): BillingRefund
    {
        if ($refund->status !== BillingRefund::STATUS_REQUESTED) {
            throw SubscriptionException::invalidState('Refund cannot be approved.');
        }

        $attempt = $refund->paymentAttempt;
        if ($attempt === null || $attempt->provider_payment_id === null) {
            throw SubscriptionException::invalidState('Payment reference missing for refund.');
        }

        $config = BillingGatewayConfig::query()->where('environment', $attempt->environment)->first();
        if ($config === null) {
            throw SubscriptionException::billingDenied('gateway_unavailable', 'Gateway configuration not found.');
        }

        return DB::transaction(function () use ($refund, $approver, $reason, $attempt, $config) {
            $refund->forceFill([
                'status' => BillingRefund::STATUS_PROCESSING,
                'approved_by' => $approver->id,
            ])->save();

            $amountMinor = BillingQuoteService::toMinorUnits((string) $refund->amount, (string) $refund->currency_code);
            $provider = $this->razorpay->createRefund($config, (string) $attempt->provider_payment_id, $amountMinor);

            $refund->forceFill([
                'status' => BillingRefund::STATUS_COMPLETED,
                'provider_refund_id' => (string) ($provider['id'] ?? null),
            ])->save();

            $this->audit->record('refund_completed', $approver->id, null, (int) $refund->tenant_id, $reason, null, [
                'refund_id' => $refund->id,
                'provider_refund_id' => $refund->provider_refund_id,
            ]);

            $attempt = $refund->paymentAttempt;
            if ($attempt !== null) {
                $invoice = BillingInvoice::query()->where('billing_order_id', $attempt->billing_order_id)->first();
                if ($invoice !== null) {
                    $this->creditNotes->issueForInvoice($invoice, (string) $refund->amount, $reason, $approver, $refund->fresh());
                }
            }

            return $refund->fresh();
        });
    }
}
