<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class BillingAdminApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function gateway_update_never_returns_secrets(): void
    {
        $this->asSuperAdmin();
        BillingGatewayConfig::query()->firstOrCreate(['environment' => 'test'], ['enabled' => false]);

        $this->putJson('/api/admin/subscriptions/billing/gateways/test', [
            'public_key_id' => 'rzp_test_abc',
            'api_secret' => 'secret_should_not_leak',
            'webhook_secret' => 'whsec_should_not_leak',
            'enabled' => true,
            'reason' => 'Test configuration',
        ])->assertOk();

        $show = $this->getJson('/api/admin/subscriptions/billing/controls')->assertOk();
        $json = json_encode($show->json());
        $this->assertStringNotContainsString('secret_should_not_leak', (string) $json);
        $this->assertStringNotContainsString('whsec_should_not_leak', (string) $json);

        $config = BillingGatewayConfig::query()->where('environment', 'test')->first();
        $this->assertNotNull($config->getApiSecretValue());
    }
}
