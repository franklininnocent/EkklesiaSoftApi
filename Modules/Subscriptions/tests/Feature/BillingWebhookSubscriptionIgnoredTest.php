<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingWebhookEvent;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingWebhookSubscriptionIgnoredTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_sub_ignore';

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
        $config = BillingGatewayConfig::query()->where('environment', 'test')->firstOrFail();
        $config->setWebhookSecretValue(self::WEBHOOK_SECRET);
        $config->save();
    }

    #[Test]
    public function subscription_product_webhook_is_acknowledged_without_payment_processing(): void
    {
        $payload = [
            'id' => 'evt_sub_1',
            'event' => 'subscription.charged',
            'payload' => ['subscription' => ['entity' => ['id' => 'sub_1']]],
        ];
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $this->call(
            'POST',
            '/api/billing/webhooks/razorpay',
            [],
            [],
            [],
            ['HTTP_X-Razorpay-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $event = BillingWebhookEvent::query()->where('provider_event_id', 'evt_sub_1')->firstOrFail();
        $this->assertSame('ignored_subscription_product', $event->processing_status);
    }
}
