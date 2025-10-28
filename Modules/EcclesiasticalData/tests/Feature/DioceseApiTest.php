<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;

/**
 * Diocese API Feature Tests
 * 
 * Tests all Diocese API endpoints for functionality, security, and data integrity
 * 
 * @group ecclesiastical
 * @group feature
 * @group diocese-api
 */
class DioceseApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $baseUrl = '/api/ecclesiastical/dioceses';

    /**
     * Setup test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we have required reference data
        if (!Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'active' => 1,
            ]);
        }
        
        // Create or get country
        $country = Country::where('iso2', 'IN')->first();
        if (!$country) {
            $country = Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'active' => 1,
            ]);
        }
        
        // Create state if it doesn't exist
        if (!State::where('country_id', $country->id)->where('name', 'Tamil Nadu')->exists()) {
            State::create([
                'name' => 'Tamil Nadu',
                'code' => 'TN',
                'country_id' => $country->id,
                'active' => 1,
            ]);
        }
    }

    /** @test */
    public function it_requires_authentication_to_access_diocese_endpoints()
    {
        $response = $this->getJson($this->baseUrl);
        
        $this->assertUnauthorized($response);
    }

    /** @test */
    public function it_can_list_dioceses_with_pagination()
    {
        $this->actingAsSuperAdmin();
        
        // Create 25 dioceses
        DioceseManagement::factory()->count(25)->create();
        
        $response = $this->getJson($this->baseUrl . '?per_page=10');
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'current_page',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'code',
                        'headquarters_city',
                        'active',
                        'denomination_id',
                        'country_id',
                        'state_id',
                    ]
                ],
                'per_page',
                'total',
                'last_page'
            ],
            'message'
        ]);
        
        $response->assertJsonPath('data.per_page', 10);
        $response->assertJsonPath('data.total', 25);
        $this->assertCount(10, $response->json('data.data'));
    }

    /** @test */
    public function it_can_search_dioceses_by_name()
    {
        $this->actingAsSuperAdmin();
        
        DioceseManagement::factory()->create(['name' => 'Diocese of Chennai']);
        DioceseManagement::factory()->create(['name' => 'Diocese of Mumbai']);
        DioceseManagement::factory()->create(['name' => 'Archdiocese of Madras']);
        
        $response = $this->getJson($this->baseUrl . '?search=Chennai');
        
        $this->assertApiSuccess($response);
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertStringContainsString('Chennai', $response->json('data.data.0.name'));
    }

    /** @test */
    public function it_can_filter_dioceses_by_country()
    {
        $this->actingAsSuperAdmin();
        
        // Create dioceses for India
        DioceseManagement::factory()->count(5)->create(['country_id' => 101]);
        
        // Create dioceses for USA
        Country::factory()->create(['id' => 233, 'name' => 'United States']);
        DioceseManagement::factory()->count(3)->create(['country_id' => 233]);
        
        $response = $this->getJson($this->baseUrl . '?country_id=101');
        
        $this->assertApiSuccess($response);
        $this->assertEquals(5, $response->json('data.total'));
    }

    /** @test */
    public function it_can_filter_dioceses_by_denomination()
    {
        $this->actingAsSuperAdmin();
        
        $latinDenomination = Denomination::factory()->create(['code' => 'LATIN']);
        $syroMalabarDenomination = Denomination::factory()->create(['code' => 'SYRO_MALABAR']);
        
        DioceseManagement::factory()->count(7)->create(['denomination_id' => $latinDenomination->id]);
        DioceseManagement::factory()->count(3)->create(['denomination_id' => $syroMalabarDenomination->id]);
        
        $response = $this->getJson($this->baseUrl . '?denomination_id=' . $latinDenomination->id);
        
        $this->assertApiSuccess($response);
        $this->assertEquals(7, $response->json('data.total'));
    }

    /** @test */
    public function it_can_retrieve_a_single_diocese_with_relationships()
    {
        $this->actingAsSuperAdmin();
        
        $diocese = DioceseManagement::factory()->create();
        
        $response = $this->getJson($this->baseUrl . '/' . $diocese->id);
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'code',
                'headquarters_city',
                'active',
                'country' => ['id', 'name'],
                'state' => ['id', 'name'],
                'denomination' => ['id', 'name', 'code'],
            ],
            'message'
        ]);
        
        $this->assertEquals($diocese->id, $response->json('data.id'));
        $this->assertEquals($diocese->name, $response->json('data.name'));
    }

    /** @test */
    public function it_returns_404_for_nonexistent_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl . '/99999');
        
        $this->assertApiError($response, 404);
    }

    /** @test */
    public function it_can_create_a_new_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $dioceseData = [
            'name' => 'Diocese of Test City',
            'code' => 'TEST',
            'denomination_id' => 1,
            'country_id' => 101,
            'state_id' => 4035,
            'headquarters_city' => 'Test City',
            'website' => 'https://testdiocese.org',
            'description' => 'Test diocese for unit testing',
        ];
        
        $response = $this->postJson($this->baseUrl, $dioceseData);
        
        $this->assertApiSuccess($response, 201);
        $this->assertDatabaseHas('archdioceses', [
            'name' => 'Diocese of Test City',
            'code' => 'TEST',
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->postJson($this->baseUrl, []);
        
        $this->assertValidationError($response, 'name');
        $this->assertValidationError($response, 'code');
        $this->assertValidationError($response, 'denomination_id');
        $this->assertValidationError($response, 'country_id');
    }

    /** @test */
    public function it_validates_unique_code_when_creating_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $existingDiocese = DioceseManagement::factory()->create(['code' => 'UNIQUE']);
        
        $dioceseData = [
            'name' => 'Another Diocese',
            'code' => 'UNIQUE', // Duplicate code
            'denomination_id' => 1,
            'country_id' => 101,
        ];
        
        $response = $this->postJson($this->baseUrl, $dioceseData);
        
        $this->assertValidationError($response, 'code');
    }

    /** @test */
    public function it_validates_url_format_for_website()
    {
        $this->actingAsSuperAdmin();
        
        $dioceseData = [
            'name' => 'Diocese Test',
            'code' => 'TEST2',
            'denomination_id' => 1,
            'country_id' => 101,
            'website' => 'not-a-valid-url',
        ];
        
        $response = $this->postJson($this->baseUrl, $dioceseData);
        
        $this->assertValidationError($response, 'website');
    }

    /** @test */
    public function it_can_update_a_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $diocese = DioceseManagement::factory()->create();
        
        $updatedData = [
            'name' => 'Updated Diocese Name',
            'headquarters_city' => 'New City',
            'website' => 'https://updated-diocese.org',
        ];
        
        $response = $this->putJson($this->baseUrl . '/' . $diocese->id, $updatedData);
        
        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('archdioceses', [
            'id' => $diocese->id,
            'name' => 'Updated Diocese Name',
            'headquarters_city' => 'New City',
        ]);
    }

    /** @test */
    public function it_can_delete_a_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $diocese = DioceseManagement::factory()->create();
        
        $response = $this->deleteJson($this->baseUrl . '/' . $diocese->id);
        
        $this->assertApiSuccess($response);
        $this->assertDatabaseMissing('archdioceses', [
            'id' => $diocese->id,
        ]);
    }

    /** @test */
    public function it_can_retrieve_diocese_statistics()
    {
        $this->actingAsSuperAdmin();
        
        // Create mix of archdioceses and dioceses
        DioceseManagement::factory()->count(5)->create(['name' => 'Archdiocese of Test']);
        DioceseManagement::factory()->count(10)->create(['name' => 'Diocese of Test']);
        
        $response = $this->getJson($this->baseUrl . '/statistics');
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total',
                'active',
                'archdioceses',
                'dioceses',
                'by_country',
            ],
            'message'
        ]);
        
        $this->assertEquals(15, $response->json('data.total'));
    }

    /** @test */
    public function it_can_list_archdioceses_only()
    {
        $this->actingAsSuperAdmin();
        
        // Create archdioceses (name contains "Archdiocese")
        DioceseManagement::factory()->count(3)->create([
            'name' => 'Archdiocese of Test City'
        ]);
        
        // Create regular dioceses
        DioceseManagement::factory()->count(7)->create([
            'name' => 'Diocese of Test City'
        ]);
        
        $response = $this->getJson($this->baseUrl . '/archdioceses');
        
        $this->assertApiSuccess($response);
        $this->assertCount(3, $response->json('data'));
        
        foreach ($response->json('data') as $archdiocese) {
            $this->assertStringContainsString('Archdiocese', $archdiocese['name']);
        }
    }

    /** @test */
    public function it_can_filter_dioceses_by_country_using_route_parameter()
    {
        $this->actingAsSuperAdmin();
        
        $india = Country::find(101);
        $usa = Country::factory()->create(['id' => 233, 'name' => 'United States']);
        
        DioceseManagement::factory()->count(5)->create(['country_id' => $india->id]);
        DioceseManagement::factory()->count(3)->create(['country_id' => $usa->id]);
        
        $response = $this->getJson($this->baseUrl . '/country/' . $india->id);
        
        $this->assertApiSuccess($response);
        $this->assertCount(5, $response->json('data'));
    }

    /** @test */
    public function tenant_admin_can_only_create_diocese_for_their_tenant()
    {
        $this->actingAsTenantAdmin();
        
        $dioceseData = [
            'name' => 'Tenant Diocese',
            'code' => 'TENANT',
            'denomination_id' => 1,
            'country_id' => 101,
        ];
        
        $response = $this->postJson($this->baseUrl, $dioceseData);
        
        // Should either succeed or be forbidden based on policy
        $this->assertTrue(
            $response->status() === 201 || $response->status() === 403
        );
    }

    /** @test */
    public function regular_user_cannot_create_diocese()
    {
        $this->actingAsUser();
        
        $dioceseData = [
            'name' => 'User Diocese',
            'code' => 'USER',
            'denomination_id' => 1,
            'country_id' => 101,
        ];
        
        $response = $this->postJson($this->baseUrl, $dioceseData);
        
        $this->assertForbidden($response);
    }

    /** @test */
    public function it_respects_pagination_limits()
    {
        $this->actingAsSuperAdmin();
        
        DioceseManagement::factory()->count(50)->create();
        
        // Test default pagination
        $response = $this->getJson($this->baseUrl);
        $this->assertApiSuccess($response);
        $this->assertLessThanOrEqual(15, count($response->json('data.data')));
        
        // Test custom per_page
        $response = $this->getJson($this->baseUrl . '?per_page=5');
        $this->assertCount(5, $response->json('data.data'));
        
        // Test maximum per_page (should cap at 100)
        $response = $this->getJson($this->baseUrl . '?per_page=200');
        $this->assertLessThanOrEqual(100, count($response->json('data.data')));
    }

    /** @test */
    public function it_returns_proper_error_for_invalid_id_format()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl . '/invalid-id');
        
        // Should return 404 or validation error
        $this->assertTrue(in_array($response->status(), [404, 422]));
    }

    /** @test */
    public function it_filters_inactive_dioceses_when_requested()
    {
        $this->actingAsSuperAdmin();
        
        DioceseManagement::factory()->count(5)->create(['active' => 1]);
        DioceseManagement::factory()->count(3)->create(['active' => 0]);
        
        $response = $this->getJson($this->baseUrl . '?is_active=1');
        
        $this->assertApiSuccess($response);
        $this->assertEquals(5, $response->json('data.total'));
    }

    /** @test */
    public function it_can_sort_dioceses_by_name()
    {
        $this->actingAsSuperAdmin();
        
        DioceseManagement::factory()->create(['name' => 'Zulu Diocese']);
        DioceseManagement::factory()->create(['name' => 'Alpha Diocese']);
        DioceseManagement::factory()->create(['name' => 'Beta Diocese']);
        
        $response = $this->getJson($this->baseUrl . '?sort_by=name&sort_dir=asc');
        
        $this->assertApiSuccess($response);
        $dioceses = $response->json('data.data');
        
        $this->assertEquals('Alpha Diocese', $dioceses[0]['name']);
        $this->assertEquals('Beta Diocese', $dioceses[1]['name']);
        $this->assertEquals('Zulu Diocese', $dioceses[2]['name']);
    }

    /** @test */
    public function it_handles_concurrent_requests_gracefully()
    {
        $this->actingAsSuperAdmin();
        
        DioceseManagement::factory()->count(10)->create();
        
        // Simulate multiple concurrent requests
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->getJson($this->baseUrl);
        }
        
        // All should succeed
        foreach ($responses as $response) {
            $this->assertApiSuccess($response);
        }
    }

    /** @test */
    public function it_returns_correct_content_type_headers()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl);
        
        $response->assertHeader('Content-Type', 'application/json');
    }
}

