<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Tenants\Models\SubscriptionSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingMaintenanceScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function maintenance_window_reverts_online_checkout_when_until_passes(): void
    {
        $settings = SubscriptionSettings::query()->firstOrFail();
        $policies = is_array($settings->policies) ? $settings->policies : [];
        $policies['billing'] = array_replace((array) ($policies['billing'] ?? []), [
            'online_checkout' => 'maintenance',
            'maintenance_until' => now()->subMinute()->toIso8601String(),
            'maintenance_revert_to' => 'disabled',
        ]);
        $settings->forceFill(['policies' => $policies])->save();
        app(SubscriptionPolicyService::class)->forgetPoliciesMemo();

        $this->artisan('billing:apply-scheduled-maintenance')->assertSuccessful();

        $billing = (array) app(SubscriptionPolicyService::class)->get('billing', []);
        $this->assertSame('disabled', $billing['online_checkout']);
        $this->assertNull($billing['maintenance_until']);
    }
}
