<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\SubscriptionCatalogAudit;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Support\EntitlementCacheVersion;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SubscriptionTaxApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function super_admin_can_view_and_update_tax(): void
    {
        $this->asSuperAdmin();

        $show = $this->getJson('/api/admin/subscriptions/tax')->assertOk();
        $this->assertSame('GST', $show->json('data.tax.label'));
        $this->assertSame('18.00', $show->json('data.tax.rate_percent'));
        $this->assertArrayHasKey('impact', $show->json('data'));

        $preview = $this->postJson('/api/admin/subscriptions/tax/preview', [
            'amount' => '2999.00',
            'tax' => ['rate_percent' => '18.00', 'prices_include_tax' => false],
        ])->assertOk();
        $this->assertSame('2999.00', $preview->json('data.net'));
        $this->assertSame('539.82', $preview->json('data.tax'));
        $this->assertSame('3538.82', $preview->json('data.gross'));

        $this->putJson('/api/admin/subscriptions/tax', [
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '20.00',
                'prices_include_tax' => false,
                'jurisdiction' => ['country_code' => 'IN', 'tax_system' => 'GST'],
            ],
            'reason' => 'Test rate change',
        ])->assertOk()->assertJsonPath('data.tax.rate_percent', '20.00');

        $this->assertTrue(
            SubscriptionCatalogAudit::query()->where('operation', 'tax_policy_updated')->exists()
        );
    }

    #[Test]
    public function tenant_admin_cannot_access_tax_endpoints(): void
    {
        $this->asTenantAdmin();

        $this->getJson('/api/admin/subscriptions/tax')->assertForbidden();
        $this->putJson('/api/admin/subscriptions/tax', ['tax' => ['label' => 'X', 'rate_percent' => '1', 'prices_include_tax' => false]])->assertForbidden();
        $this->postJson('/api/admin/subscriptions/tax/preview', ['amount' => '100.00'])->assertForbidden();
    }

    #[Test]
    public function ekklesia_admin_can_view_but_not_update_tax(): void
    {
        $this->asEkklesiaAdmin();

        $this->getJson('/api/admin/subscriptions/tax')->assertOk();
        $this->putJson('/api/admin/subscriptions/tax', [
            'tax' => ['label' => 'GST', 'rate_percent' => '19.00', 'prices_include_tax' => false],
        ])->assertForbidden();
    }

    #[Test]
    public function tax_update_forgets_public_plans_cache_key(): void
    {
        $this->asSuperAdmin();
        $key = 'subscriptions:public_plans:c_'.EntitlementCacheVersion::catalog();
        Cache::put($key, ['stale'], 300);
        $this->assertTrue(Cache::has($key));

        $this->putJson('/api/admin/subscriptions/tax', [
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '18.00',
                'prices_include_tax' => false,
            ],
        ])->assertOk();

        $this->assertFalse(Cache::has($key));
    }

    #[Test]
    public function inherited_plan_uses_new_platform_rate_after_update(): void
    {
        $admin = $this->asSuperAdmin()['user'];
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion;
        $this->assertNull($version->tax_rate_percent);

        app(SubscriptionPolicyService::class)->updateTax([
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '20.00',
                'prices_include_tax' => false,
            ],
        ], $admin, 'sync');

        $public = collect($this->getJson('/api/public/subscription-plans')->json('data'));
        $starter = $public->firstWhere('code', 'STARTER');
        $this->assertSame('20.00', $starter['tax']['rate_percent']);

        app(SubscriptionPolicyService::class)->updateTax([
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '18.00',
                'prices_include_tax' => false,
            ],
        ], $admin, 'restore');
    }

    #[Test]
    public function overridden_plan_keeps_custom_rate_when_platform_changes(): void
    {
        $admin = $this->asSuperAdmin()['user'];
        $plan = $this->plan('STANDARD');
        $version = $plan->activeVersion;
        DB::table('plan_versions')->where('id', $version->id)->update([
            'tax_rate_percent' => '12.00',
            'tax_label' => 'GST',
        ]);

        app(SubscriptionPolicyService::class)->updateTax([
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '20.00',
                'prices_include_tax' => false,
            ],
        ], $admin, 'platform bump');

        $public = collect($this->getJson('/api/public/subscription-plans')->json('data'));
        $standard = $public->firstWhere('code', 'STANDARD');
        $this->assertSame('12.00', $standard['tax']['rate_percent']);
        $this->assertSame('plan_version', $standard['tax']['source']);

        DB::table('plan_versions')->where('id', $version->id)->update([
            'tax_rate_percent' => null,
            'tax_label' => null,
        ]);
        app(SubscriptionPolicyService::class)->updateTax([
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '18.00',
                'prices_include_tax' => false,
            ],
        ], $admin, 'restore');
    }
}
