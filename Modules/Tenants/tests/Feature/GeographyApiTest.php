<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class GeographyApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_list_active_countries(): void
    {
        $country = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        Country::factory()->inactive()->create(['name' => 'Hidden Land', 'iso2' => 'HL', 'iso3' => 'HID']);

        $this->asSuperAdmin();

        $response = $this->getJson('/api/geography/countries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'India'])
            ->assertJsonMissing(['name' => 'Hidden Land']);
    }

    #[Test]
    public function unauthenticated_user_cannot_list_countries(): void
    {
        Country::factory()->create();

        $this->getJson('/api/geography/countries')
            ->assertUnauthorized();
    }

    #[Test]
    public function it_recovers_when_countries_cache_was_stale_and_empty(): void
    {
        $country = Country::factory()->create(['name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND']);
        Cache::put('countries:active', collect(), 86400);

        $this->asSuperAdmin();

        $this->getJson('/api/geography/countries')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'India']);
    }

    #[Test]
    public function it_returns_states_for_a_valid_country(): void
    {
        $country = Country::factory()->create(['iso2' => 'IN', 'iso3' => 'IND']);
        State::query()->create([
            'country_id' => $country->id,
            'name' => 'Kerala',
            'state_code' => 'KL',
            'active' => true,
        ]);

        $this->asSuperAdmin();

        $response = $this->getJson('/api/geography/countries/'.$country->id.'/states');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'Kerala']);
    }

    #[Test]
    public function it_returns_404_for_unknown_country_states(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/geography/countries/99999/states')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function country_search_requires_at_least_two_characters(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/geography/countries/search?q=I')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }
}
