<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Services\Billing\BillingWebhookProcessor;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingWebhookFeatureTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_webhook_secret';

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
        $config = BillingGatewayConfig::query()->where('environment', 'test')->firstOrFail();
        $config->setWebhookSecretValue(self::WEBHOOK_SECRET);
        $config->save();
    }

    #[Test]
    public function invalid_signature_is_rejected(): void
    {
        $body = json_encode(['id' => 'evt_bad', 'event' => 'payment.captured', 'payload' => []]);
        $this->call(
            'POST',
            '/api/billing/webhooks/razorpay',
            [],
            [],
            [],
            ['HTTP_X-Razorpay-Signature' => 'invalid', 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertStatus(400);
    }

    #[Test]
    public function duplicate_provider_event_id_is_acknowledged_without_duplicate_rows(): void
    {
        $payload = $this->samplePayload('evt_dup_1', 'pay_1', 'order_1', 'captured');
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $this->postWebhook($body, $sig)->assertOk();
        $this->postWebhook($body, $sig)->assertOk();

        $this->assertSame(1, BillingWebhookEvent::query()->where('provider_event_id', 'evt_dup_1')->count());
    }

    #[Test]
    public function captured_webhook_fulfills_order_once(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth(), 'plan' => 'starter']);
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $providerOrderId = 'order_webhook_1';

        $order = BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'wh_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'provider_order_id' => $providerOrderId,
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'environment' => 'test',
        ]);

        $payload = $this->samplePayload('evt_cap_1', 'pay_cap_1', $providerOrderId, 'captured');
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
        $this->postWebhook($body, $sig)->assertOk();

        $event = BillingWebhookEvent::query()->where('provider_event_id', 'evt_cap_1')->firstOrFail();
        app(BillingWebhookProcessor::class)->process($event);
        app(BillingWebhookProcessor::class)->process($event->fresh());

        $order->refresh();
        $this->assertSame(BillingOrder::STATUS_FULFILLED, $order->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePayload(string $eventId, string $paymentId, string $orderId, string $status): array
    {
        return [
            'id' => $eventId,
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'status' => $status,
                    ],
                ],
            ],
        ];
    }

    private function postWebhook(string $body, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/billing/webhooks/razorpay',
            [],
            [],
            [],
            ['HTTP_X-Razorpay-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }
}
