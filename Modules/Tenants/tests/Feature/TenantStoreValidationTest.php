<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantStoreValidationTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private function validPayload(int $tenantCountryId, int $tenantStateId, int $primaryStateId, string $suffix = '1'): array
    {
        return [
            'tenant_name' => 'Test Parish '.$suffix,
            'tenant_official_address' => [
                'line1' => '123 Main Street',
                'line2' => '',
                'country_id' => $tenantCountryId,
                'state_id' => $tenantStateId,
                'district' => 'Kochi',
                'pin_zip_code' => '682001',
            ],
            'primary_user_name' => 'Jane Admin',
            'primary_user_email' => 'jane.admin'.$suffix.'@example.test',
            'primary_user_password' => 'Secure1!',
            'primary_user_password_confirmation' => 'Secure1!',
            'primary_contact_number' => '9876543210',
            'primary_user_address' => [
                'line1' => '123 Main Street',
                'line2' => '',
                'country_id' => $tenantCountryId,
                'state_id' => $primaryStateId,
                'district' => 'Kochi',
                'pin_zip_code' => '682001',
            ],
        ];
    }

    #[Test]
    public function store_rejects_state_that_does_not_belong_to_selected_country(): void
    {
        $india = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        $usa = Country::factory()->create(['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA']);

        $kerala = State::query()->create([
            'country_id' => $india->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);
        $texas = State::query()->create([
            'country_id' => $usa->id,
            'name' => 'Texas',
            'state_code' => 'TX',
            'active' => true,
        ]);

        $this->asSuperAdmin();

        $response = $this->postJson('/api/tenant', $this->validPayload(
            $india->id,
            $texas->id,
            $kerala->id,
            'mismatch'
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_official_address.state_id']);
    }

    #[Test]
    public function store_rejects_missing_primary_user_password(): void
    {
        $country = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        $state = State::query()->create([
            'country_id' => $country->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);

        $this->asSuperAdmin();

        $payload = $this->validPayload($country->id, $state->id, $state->id, 'nopw');
        unset($payload['primary_user_password'], $payload['primary_user_password_confirmation']);

        $response = $this->postJson('/api/tenant', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['primary_user_password']);
    }

    #[Test]
    public function platform_admin_can_create_tenant_with_matching_country_and_state(): void
    {
        $country = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        $state = State::query()->create([
            'country_id' => $country->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);

        $this->asSuperAdmin();

        $response = $this->postJson('/api/tenant', $this->validPayload(
            $country->id,
            $state->id,
            $state->id,
            'happy'
        ));

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tenants', ['name' => 'Test Parish happy']);
    }

    #[Test]
    public function platform_admin_can_create_tenant_with_domain_and_diocese(): void
    {
        $country = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        $state = State::query()->create([
            'country_id' => $country->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);

        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Archdiocese of Create Test',
            'code' => 'CREATE_TEST_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asSuperAdmin();

        $payload = $this->validPayload($country->id, $state->id, $state->id, 'domain-diocese');
        $payload['domain'] = 'sacred-heart.example.test';
        $payload['archdiocese_id'] = $archdioceseId;

        $response = $this->postJson('/api/tenant', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $tenantId = (int) $response->json('data.id');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'name' => 'Test Parish domain-diocese',
            'domain' => 'sacred-heart.example.test',
        ]);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $tenantId,
            'archdiocese_id' => $archdioceseId,
        ]);
    }

    #[Test]
    public function platform_admin_can_update_tenant_diocese_via_archdiocese_id(): void
    {
        $this->asSuperAdmin();
        $tenant = Tenant::factory()->create(['name' => 'Diocese Update Parish']);

        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Archdiocese of Update Test',
            'code' => 'UPDATE_TEST_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/tenant/'.$tenant->id, [
            'archdiocese_id' => $archdioceseId,
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $tenant->id,
            'archdiocese_id' => $archdioceseId,
        ]);
    }

    #[Test]
    public function platform_admin_can_update_tenant_website_via_church_profile(): void
    {
        $this->asSuperAdmin();
        $tenant = Tenant::factory()->create(['name' => 'Website Update Parish']);

        $this->putJson('/api/tenant/'.$tenant->id, [
            'website' => 'https://sacred-heart.example.test',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $tenant->id,
            'website' => 'https://sacred-heart.example.test',
        ]);
    }

    #[Test]
    public function platform_admin_can_update_tenant_name_via_tenant_name_field(): void
    {
        $this->asSuperAdmin();
        $tenant = Tenant::factory()->create(['name' => 'Old Parish Name']);

        $response = $this->putJson('/api/tenant/'.$tenant->id, [
            'tenant_name' => 'Updated Parish Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Updated Parish Name',
        ]);
    }
}
