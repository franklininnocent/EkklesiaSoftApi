<?php

namespace Modules\Subscriptions\Services\Billing;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Models\BillingWebhookEvent;

final class BillingWebhookProcessor
{
    public function __construct(
        private readonly BillingFulfillmentService $fulfillment,
    ) {}

    public function process(BillingWebhookEvent $event): void
    {
        if (! $event->signature_valid) {
            return;
        }

        if ($event->processing_status === BillingWebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $summary = (array) ($event->payload_summary ?? []);
        $paymentEntity = $summary['payment'] ?? null;
        if (! is_array($paymentEntity)) {
            $event->forceFill(['processing_status' => BillingWebhookEvent::STATUS_FAILED])->save();

            return;
        }

        $paymentId = (string) ($paymentEntity['id'] ?? '');
        $orderId = (string) ($paymentEntity['order_id'] ?? '');
        $status = (string) ($paymentEntity['status'] ?? '');

        DB::transaction(function () use ($event, $paymentId, $orderId, $status) {
            $order = BillingOrder::query()->where('provider_order_id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                $event->forceFill(['processing_status' => BillingWebhookEvent::STATUS_FAILED])->save();

                return;
            }

            if ($status !== 'captured') {
                $event->forceFill(['processing_status' => BillingWebhookEvent::STATUS_PROCESSED])->save();

                return;
            }

            $attempt = BillingPaymentAttempt::query()->updateOrCreate(
                ['provider_payment_id' => $paymentId],
                [
                    'billing_order_id' => $order->id,
                    'status' => BillingPaymentAttempt::STATUS_CAPTURED,
                    'amount' => $order->gross_amount,
                    'currency_code' => $order->currency_code,
                    'environment' => $order->environment,
                    'verified_at' => now(),
                    'provider_payload_summary' => ['status' => $status, 'via' => 'webhook'],
                ]
            );

            if ($order->status !== BillingOrder::STATUS_FULFILLED) {
                $order->forceFill(['status' => BillingOrder::STATUS_CAPTURED])->save();
                $this->fulfillment->fulfillFromOrder($order, $attempt, null);
            }

            $event->forceFill(['processing_status' => BillingWebhookEvent::STATUS_PROCESSED])->save();
        });
    }

    public function resolveWebhookSecret(string $environment, string $rawBody, string $signature): ?BillingGatewayConfig
    {
        $config = BillingGatewayConfig::query()->where('environment', $environment)->first();
        if ($config === null) {
            return null;
        }

        $client = app(RazorpayGatewayClient::class);
        foreach ([$config->getWebhookSecretValue(), $config->getWebhookSecretPreviousValue()] as $secret) {
            if ($secret && $client->verifyWebhookSignature($rawBody, $signature, $secret)) {
                return $config;
            }
        }

        return null;
    }
}
