<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantSubscriptionAccessPolicyApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    #[Test]
    public function super_admin_can_get_and_update_tenant_access_policy(): void
    {
        $this->asSuperAdmin();
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'custom_grace_period_days' => null,
            'custom_view_only_allowance_days' => null,
        ]);

        $this->getJson('/api/tenant/'.$tenant->id.'/subscription/access-policy')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.grace_period_inherited', true);

        $this->putJson('/api/tenant/'.$tenant->id.'/subscription/access-policy', [
            'custom_grace_period_days' => 14,
            'custom_view_only_allowance_days' => 60,
        ])->assertOk()
            ->assertJsonPath('data.custom_grace_period_days', 14)
            ->assertJsonPath('data.grace_period_inherited', false);

        $this->putJson('/api/tenant/'.$tenant->id.'/subscription/access-policy', [
            'custom_grace_period_days' => null,
            'custom_view_only_allowance_days' => null,
        ])->assertOk()
            ->assertJsonPath('data.grace_period_inherited', true);
    }

    #[Test]
    public function parish_admin_cannot_update_access_policy(): void
    {
        $ctx = $this->asTenantAdmin();
        $tenant = $ctx['tenant'];

        $this->putJson('/api/tenant/'.$tenant->id.'/subscription/access-policy', [
            'custom_grace_period_days' => 99,
        ])->assertForbidden();
    }
}
