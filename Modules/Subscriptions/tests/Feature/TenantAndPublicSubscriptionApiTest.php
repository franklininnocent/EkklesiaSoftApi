<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantAndPublicSubscriptionApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function public_plans_list_only_public_active_non_legacy_plans(): void
    {
        $response = $this->getJson('/api/public/subscription-plans')->assertOk();
        $codes = array_column($response->json('data'), 'code');

        $this->assertSame(['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'], $codes);
        $this->assertNotContains('LEGACY_FREE', $codes);

        $standard = collect($response->json('data'))->firstWhere('code', 'STANDARD');
        $this->assertTrue($standard['is_featured']);
        $this->assertStringNotContainsString('tenant', strtolower(json_encode(array_keys($standard))));
    }

    #[Test]
    public function tenant_entitlements_reflect_own_tenant_and_ignore_query_tenant_id(): void
    {
        $other = Tenant::factory()->create();
        $this->assignPlan($other, 'PROFESSIONAL');
        $ctx = $this->asStaff();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $response = $this->getJson('/api/tenant/entitlements?tenant_id='.$other->id)->assertOk();

        $this->assertSame('STARTER', $response->json('data.plan.code'));
        $this->assertTrue($response->json('data.features.FAMILIES'));
        $this->assertFalse($response->json('data.features.ADVANCED_ANALYTICS'));
        $this->assertSame('Advanced Analytics', $response->json('data.feature_names.ADVANCED_ANALYTICS'));
        $this->assertSame(250, $response->json('data.limits.PEOPLE_LIMIT'));
        $this->assertTrue($response->json('data.limits_enforced'));
    }

    #[Test]
    public function outside_enforce_mode_the_entitlement_map_reports_what_the_api_actually_allows(): void
    {
        $ctx = $this->asStaff();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('shadow');

        $response = $this->getJson('/api/tenant/entitlements')->assertOk();

        $this->assertSame('STARTER', $response->json('data.plan.code'));
        $this->assertTrue($response->json('data.features.ADVANCED_ANALYTICS'));
        $this->assertFalse($response->json('data.limits_enforced'));
    }

    #[Test]
    public function commercial_overview_requires_subscription_permission(): void
    {
        $this->asStaff();
        $this->getJson('/api/tenant/subscription/overview')->assertForbidden();
        $this->getJson('/api/tenant/subscription/usage')->assertForbidden();

        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->getJson('/api/tenant/subscription/overview')->assertOk()->assertJsonPath('data.plan.code', 'STANDARD');
        $this->getJson('/api/tenant/subscription/usage')->assertOk();
    }

    #[Test]
    public function unauthenticated_tenant_endpoints_are_rejected(): void
    {
        $this->getJson('/api/tenant/entitlements')->assertUnauthorized();
        $this->getJson('/api/tenant/subscription/overview')->assertUnauthorized();
    }
}
