<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantCreationPlanTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function new_tenant_gets_default_plan_with_trial_and_ignores_client_entitlements(): void
    {
        $this->asSuperAdmin();

        $response = $this->postJson('/api/tenant', $this->payload('default') + [
            'features' => ['api_access', 'custom_branding'],
            'max_users' => 9999,
        ])->assertCreated();

        $tenant = Tenant::query()->findOrFail((int) $response->json('data.id'));
        $subscription = $this->currentSubscription($tenant);

        $this->assertNotNull($subscription);
        $this->assertSame('STARTER', $subscription->plan->code);
        $this->assertSame('starter', $tenant->plan);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertNotContains('api_access', (array) $tenant->features);
    }

    #[Test]
    public function new_tenant_can_be_created_on_a_selected_assignable_plan(): void
    {
        $this->asSuperAdmin();

        $response = $this->postJson('/api/tenant', $this->payload('standard') + ['plan' => 'standard'])->assertCreated();

        $tenant = Tenant::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame('STANDARD', $this->currentSubscription($tenant)->plan->code);
    }

    #[Test]
    public function assignable_plan_keys_exclude_legacy_and_plans_without_active_versions(): void
    {
        $keys = app(SubscriptionPlanChangeService::class)->assignablePlanKeys();

        $this->assertContains('starter', $keys);
        $this->assertContains('standard', $keys);
        $this->assertNotContains('basic', $keys);

        PlanVersion::query()
            ->where('plan_id', $this->plan('STANDARD')->id)
            ->update(['status' => PlanVersion::STATUS_RETIRED]);

        $keysAfter = app(SubscriptionPlanChangeService::class)->assignablePlanKeys();
        $this->assertNotContains('standard', $keysAfter);
    }

    #[Test]
    public function new_tenant_cannot_be_created_on_a_legacy_plan(): void
    {
        $this->asSuperAdmin();

        $this->postJson('/api/tenant', $this->payload('legacy') + ['plan' => 'basic'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    #[Test]
    public function new_tenant_cannot_be_created_on_an_unknown_or_unassignable_plan(): void
    {
        $this->asSuperAdmin();

        $this->postJson('/api/tenant', $this->payload('unknown') + ['plan' => 'not-a-plan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);

        $this->plan('STANDARD')->forceFill(['is_assignable' => false])->save();

        $this->postJson('/api/tenant', $this->payload('unassignable') + ['plan' => 'standard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);

        $this->assertSame(0, Tenant::query()->where('name', 'like', 'Plan Parish%')->count());
    }

    #[Test]
    public function new_tenant_cannot_be_created_on_a_plan_without_an_active_version(): void
    {
        $this->asSuperAdmin();

        PlanVersion::query()
            ->where('plan_id', $this->plan('STANDARD')->id)
            ->update(['status' => PlanVersion::STATUS_RETIRED]);

        $this->postJson('/api/tenant', $this->payload('no-version') + ['plan' => 'standard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    #[Test]
    public function ekklesia_admin_can_create_a_tenant_on_an_assignable_plan(): void
    {
        $this->asEkklesiaAdmin();

        $response = $this->postJson('/api/tenant', $this->payload('ekklesia') + ['plan' => 'standard'])->assertCreated();

        $tenant = Tenant::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame('STANDARD', $this->currentSubscription($tenant)->plan->code);
    }

    #[Test]
    public function tenant_users_cannot_create_tenants_or_assign_plans(): void
    {
        $this->asTenantAdmin();

        $this->postJson('/api/tenant', $this->payload('forbidden') + ['plan' => 'standard'])
            ->assertForbidden();

        $this->assertSame(0, Tenant::query()->where('name', 'Plan Parish forbidden')->count());
    }

    #[Test]
    public function client_supplied_plan_id_and_price_do_not_override_catalog_snapshot(): void
    {
        $this->asSuperAdmin();
        $starterId = $this->plan('STARTER')->id;

        $response = $this->postJson('/api/tenant', $this->payload('forged') + [
            'plan' => 'standard',
            'plan_id' => $starterId,
            'contracted_price' => '0.01',
            'status' => 'ACTIVE',
            'max_users' => 9999,
        ])->assertCreated();

        $tenant = Tenant::query()->findOrFail((int) $response->json('data.id'));
        $subscription = $this->currentSubscription($tenant);

        $this->assertSame('STANDARD', $subscription->plan->code);
        $this->assertNotSame('0.01', (string) $subscription->contracted_price);
        $this->assertNotSame($starterId, (int) $subscription->plan_id);
        $this->assertNotSame(9999, (int) $tenant->max_users);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $suffix): array
    {
        $country = Country::query()->first() ?? Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        $state = State::query()->where('country_id', $country->id)->first() ?? State::query()->create([
            'country_id' => $country->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);
        $address = [
            'line1' => '123 Main Street',
            'country_id' => $country->id,
            'state_id' => $state->id,
            'district' => 'Kochi',
            'pin_zip_code' => '682001',
        ];

        return [
            'tenant_name' => 'Plan Parish '.$suffix,
            'tenant_official_address' => $address,
            'primary_user_name' => 'Jane Admin',
            'primary_user_email' => 'plan.admin.'.$suffix.'@example.test',
            'primary_user_password' => 'Secure1!',
            'primary_user_password_confirmation' => 'Secure1!',
            'primary_contact_number' => '9876543210',
            'primary_user_address' => $address,
        ];
    }
}
