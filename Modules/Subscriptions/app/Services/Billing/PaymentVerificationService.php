<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Tenants\Models\Tenant;

final class PaymentVerificationService
{
    public function __construct(
        private readonly RazorpayGatewayClient $razorpay,
        private readonly BillingFulfillmentService $fulfillment,
        private readonly BillingAvailabilityPolicy $availability,
        private readonly BillingAuditService $audit,
        private readonly BillingInvestigationService $investigations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(Tenant $tenant, BillingOrder $order, string $paymentId, string $signature, ?int $actorId): array
    {
        if ((int) $order->tenant_id !== (int) $tenant->id) {
            throw SubscriptionException::billingDenied('forbidden', 'Order not found.');
        }

        $decision = $this->availability->decide($tenant, BillingAction::VerifyOnlinePayment);
        if (! $decision->allowed && $order->status === BillingOrder::STATUS_AWAITING_PAYMENT) {
            // Still allow verify for in-flight checkout
        }

        if ($order->provider_order_id === null) {
            throw SubscriptionException::billingVerificationFailed('Order is not linked to a payment provider.');
        }

        $config = BillingGatewayConfig::query()->where('environment', $order->environment)->first();
        if ($config === null) {
            throw SubscriptionException::billingVerificationFailed('Payment gateway is not configured.');
        }

        $secret = $config->getApiSecretValue();
        if ($secret === null || ! $this->razorpay->verifyCheckoutSignature($order->provider_order_id, $paymentId, $signature, $secret)) {
            throw SubscriptionException::billingVerificationFailed('Payment signature is invalid.');
        }

        $payment = $this->razorpay->fetchPayment($config, $paymentId);
        $status = (string) ($payment['status'] ?? '');
        $amount = (int) ($payment['amount'] ?? 0);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $providerOrderId = (string) ($payment['order_id'] ?? '');

        if ($providerOrderId !== $order->provider_order_id) {
            throw SubscriptionException::billingVerificationFailed('Payment does not match this order.');
        }

        if ($amount !== (int) $order->amount_minor || $currency !== strtoupper($order->currency_code)) {
            $order->forceFill(['status' => BillingOrder::STATUS_NEEDS_INVESTIGATION])->save();
            $this->investigations->openForOrder($order->fresh(), 'amount_mismatch', [
                'expected_minor' => $order->amount_minor,
                'received_minor' => $amount,
                'expected_currency' => $order->currency_code,
                'received_currency' => $currency,
                'payment_id' => $paymentId,
            ]);
            throw SubscriptionException::billingVerificationFailed('Payment amount or currency does not match.');
        }

        if ($status !== 'captured') {
            $order->forceFill(['status' => BillingOrder::STATUS_PAYMENT_PENDING])->save();
            BillingPaymentAttempt::query()->updateOrCreate(
                ['provider_payment_id' => $paymentId],
                [
                    'billing_order_id' => $order->id,
                    'status' => $status === 'authorized' ? BillingPaymentAttempt::STATUS_AUTHORIZED : BillingPaymentAttempt::STATUS_FAILED,
                    'amount' => $order->gross_amount,
                    'currency_code' => $order->currency_code,
                    'environment' => $order->environment,
                    'provider_payload_summary' => ['status' => $status],
                ]
            );

            return [
                'status' => 'pending',
                'order_status' => $order->status,
                'payment_status' => $status,
            ];
        }

        return DB::transaction(function () use ($order, $paymentId, $payment, $tenant, $actorId, $status) {
            $locked = BillingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BillingOrder::STATUS_FULFILLED) {
                return ['status' => 'fulfilled', 'order_status' => $locked->status];
            }

            $attempt = BillingPaymentAttempt::query()->updateOrCreate(
                ['provider_payment_id' => $paymentId],
                [
                    'billing_order_id' => $locked->id,
                    'status' => BillingPaymentAttempt::STATUS_CAPTURED,
                    'amount' => $locked->gross_amount,
                    'currency_code' => $locked->currency_code,
                    'environment' => $locked->environment,
                    'verified_at' => now(),
                    'provider_payload_summary' => ['status' => $status],
                ]
            );

            $locked->forceFill(['status' => BillingOrder::STATUS_CAPTURED])->save();

            $this->fulfillment->fulfillFromOrder($locked, $attempt, $actorId);

            $this->audit->record('payment_verified', $actorId, null, (int) $tenant->id, null, null, [
                'order_id' => $locked->id,
                'payment_id' => $paymentId,
            ]);

            return [
                'status' => 'captured',
                'order_status' => $locked->fresh()->status,
            ];
        });
    }
}
