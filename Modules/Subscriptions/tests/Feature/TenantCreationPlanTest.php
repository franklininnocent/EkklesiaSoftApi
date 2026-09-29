<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function new_tenant_cannot_be_created_on_a_legacy_plan(): void
    {
        $this->asSuperAdmin();

        $this->postJson('/api/tenant', $this->payload('legacy') + ['plan' => 'basic'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
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
