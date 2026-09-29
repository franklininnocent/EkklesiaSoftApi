<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\RevenueCalculator;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SubscriptionAnalyticsApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function monthly_equivalent_rules(): void
    {
        $this->assertSame('2999.000000', RevenueCalculator::monthlyEquivalent('2999.00', 'MONTHLY'));
        $this->assertSame('2499.166666', RevenueCalculator::monthlyEquivalent('29990.00', 'ANNUAL'));
        $this->assertSame('10000.000000', RevenueCalculator::monthlyEquivalent('120000', 'CUSTOM'));
        $this->assertNull(RevenueCalculator::monthlyEquivalent('100', 'NONE'));
        $this->assertNull(RevenueCalculator::monthlyEquivalent(null, 'MONTHLY'));
        $this->assertSame('2499.17', RevenueCalculator::round('2499.166666'));
        $this->assertSame('29990.00', RevenueCalculator::annualize('2499.166666'));
    }

    #[Test]
    public function revenue_counts_active_priced_churches_and_excludes_trial_expired_and_suspended(): void
    {
        $active = Tenant::factory()->create();
        $this->assignPlan($active, 'STANDARD', ['billing_interval' => 'MONTHLY', 'duration_months' => 12]);
        $annual = Tenant::factory()->create();
        $this->assignPlan($annual, 'STARTER', ['billing_interval' => 'ANNUAL', 'duration_months' => 12]);
        $expired = Tenant::factory()->create();
        $this->assignPlan($expired, 'PROFESSIONAL', ['billing_interval' => 'MONTHLY']);
        $expired->forceFill(['subscription_ends_at' => now()->subDays(90), 'trial_ends_at' => null])->save();
        $suspended = Tenant::factory()->create();
        $this->assignPlan($suspended, 'PROFESSIONAL', ['billing_interval' => 'MONTHLY', 'duration_months' => 12]);
        $suspended->forceFill(['subscription_suspended_at' => now()])->save();

        $this->asSuperAdmin();
        $data = $this->getJson('/api/admin/subscriptions/revenue')->assertOk()->json('data');

        $this->assertSame('Contracted Subscription Revenue', $data['label']);
        $inr = collect($data['totals'])->firstWhere('currency_code', 'INR');
        // 2999 + 14990 / 12 = 4248.1666 → 4248.17; ARR = 50978.00
        $this->assertSame('4248.17', $inr['mrr']);
        $this->assertSame('50978.00', $inr['arr']);
        $this->assertSame(1, $data['excluded']['expired']);
        $this->assertSame(1, $data['excluded']['suspended']);
    }

    #[Test]
    public function revenue_needs_the_revenue_permission_which_ekklesia_admin_does_not_have_by_default(): void
    {
        $this->asEkklesiaAdmin();
        $this->getJson('/api/admin/subscriptions/revenue')->assertForbidden();
        $this->getJson('/api/admin/subscriptions/overview')->assertOk();
        $this->getJson('/api/admin/subscriptions/usage')->assertOk();

        $this->asTenantAdmin();
        $this->getJson('/api/admin/subscriptions/overview')->assertForbidden();
        $this->getJson('/api/admin/subscriptions/usage')->assertForbidden();
    }

    #[Test]
    public function overview_counts_churches_per_plan_and_usage_lists_churches_near_limits(): void
    {
        $near = Tenant::factory()->create(['name' => 'St Mary']);
        $this->assignPlan($near, 'STARTER');
        $calm = Tenant::factory()->create(['name' => 'St Paul']);
        $this->assignPlan($calm, 'STARTER');

        $peopleId = DB::table('features')->where('code', 'PEOPLE_LIMIT')->value('id');
        $today = now()->toDateString();
        DB::table('tenant_usage_snapshots')->insert([
            ['tenant_id' => $near->id, 'feature_id' => $peopleId, 'snapshot_date' => $today, 'usage_value' => 245, 'limit_value' => 250, 'created_at' => now()],
            ['tenant_id' => $calm->id, 'feature_id' => $peopleId, 'snapshot_date' => $today, 'usage_value' => 20, 'limit_value' => 250, 'created_at' => now()],
        ]);

        $this->asEkklesiaAdmin();
        $overview = $this->getJson('/api/admin/subscriptions/overview')->assertOk()->json('data');
        $this->assertSame(2, collect($overview['plans'])->firstWhere('code', 'STARTER')['tenant_count']);
        $this->assertSame(1, $overview['tenants_needing_attention']);
        $this->assertSame($today, $overview['usage_measured_on']);

        $usage = $this->getJson('/api/admin/subscriptions/usage?attention=1')->assertOk();
        $usage->assertJsonCount(1, 'data')->assertJsonPath('data.0.tenant_name', 'St Mary')->assertJsonPath('data.0.worst_level', 'critical');
        $this->getJson('/api/admin/subscriptions/usage')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function usage_alerts_notify_once_per_level(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'STARTER');
        $peopleId = DB::table('features')->where('code', 'PEOPLE_LIMIT')->value('id');
        DB::table('tenant_usage_snapshots')->insert([
            'tenant_id' => $tenant->id, 'feature_id' => $peopleId, 'snapshot_date' => now()->toDateString(),
            'usage_value' => 250, 'limit_value' => 250, 'created_at' => now(),
        ]);

        Artisan::call('subscriptions:usage-alerts');
        $this->assertStringContainsString('Sent 1 usage alerts', Artisan::output());
        Artisan::call('subscriptions:usage-alerts');
        $this->assertStringContainsString('Sent 0 usage alerts', Artisan::output());
    }
}
