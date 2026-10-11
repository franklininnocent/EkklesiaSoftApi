<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class RbacSubscriptionEntitlementTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function starter_plan_allows_standard_rbac_reads_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/roles')->assertOk();
    }

    #[Test]
    public function starter_plan_blocks_custom_role_create_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->postJson('/api/tenant/roles', ['name' => 'Custom', 'description' => 'Test'])
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionException::FEATURE_NOT_AVAILABLE)
            ->assertJsonPath('feature', 'RBAC_ADVANCED');
    }

    #[Test]
    public function parish_without_rbac_basic_cannot_use_tenant_rbac_api(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $sub = $this->currentSubscription($ctx['tenant']);
        $basic = Feature::query()->where('code', 'RBAC_BASIC')->firstOrFail();
        $sub->version->entitlements()->where('feature_id', $basic->id)->update(['is_enabled' => false]);
        app(EntitlementResolver::class)->flushMemo();
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/roles')
            ->assertForbidden()
            ->assertJsonPath('feature', 'RBAC_BASIC');
    }
}
