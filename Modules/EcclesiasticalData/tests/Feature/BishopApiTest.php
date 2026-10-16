<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Country;

/**
 * Bishop API Feature Tests
 * 
 * Tests all Bishop API endpoints for functionality, security, and data integrity
 * 
 * @group ecclesiastical
 * @group feature
 * @group bishop-api
 */
class BishopApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $baseUrl = '/api/ecclesiastical/bishops';
    protected DioceseManagement $diocese;

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
                'status' => 'active',
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
                'status' => 'active',
            ]);
        }
        
        // Create a diocese for bishops
        $this->diocese = DioceseManagement::factory()->create();
    }

    /** @test */
    public function it_requires_authentication_to_access_bishop_endpoints()
    {
        $response = $this->getJson($this->baseUrl);
        
        $this->assertUnauthorized($response);
    }

    /** @test */
    public function it_can_list_bishops_with_pagination()
    {
        $this->actingAsSuperAdmin();
        
        // Create 20 bishops
        BishopManagement::factory()->count(20)->create();
        
        $response = $this->getJson($this->baseUrl . '?per_page=10');
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'current_page',
                'data' => [
                    '*' => [
                        'id',
                        'full_name',
                        'given_name',
                        'family_name',
                        'status',
                        'archdiocese_id',
                    ]
                ],
                'per_page',
                'total',
            ],
            'message'
        ]);
        
        $this->assertEquals(10, $response->json('data.per_page'));
        $this->assertEquals(20, $response->json('data.total'));
    }

    /** @test */
    public function it_can_search_bishops_by_name()
    {
        $this->actingAsSuperAdmin();
        
        BishopManagement::factory()->create([
            'full_name' => 'John Smith',
            'given_name' => 'John',
            'family_name' => 'Smith'
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Michael Johnson',
            'given_name' => 'Michael',
            'family_name' => 'Johnson'
        ]);
        
        $response = $this->getJson($this->baseUrl . '?search=John');
        
        $this->assertApiSuccess($response);
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    /** @test */
    public function it_can_retrieve_a_single_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $bishop = BishopManagement::factory()->create();
        
        $response = $this->getJson($this->baseUrl . '/' . $bishop->id);
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'full_name',
                'given_name',
                'family_name',
                'religious_name',
                'email',
                'phone',
                'education',
                'status',
                'archdiocese' => ['id', 'name'],
            ],
            'message'
        ]);
        
        $this->assertEquals($bishop->id, $response->json('data.id'));
    }

    /** @test */
    public function it_returns_404_for_nonexistent_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl . '/99999');
        
        $this->assertApiError($response, 404);
    }

    /** @test */
    public function it_can_create_a_new_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $bishopData = [
            'full_name' => 'Most Rev. Test Bishop',
            'given_name' => 'Test',
            'family_name' => 'Bishop',
            'additional_titles' => 'Archbishop',
            'archdiocese_id' => $this->diocese->id,
            'email' => 'bishop@testdiocese.org',
            'phone' => '+1234567890',
            'biography' => 'Test biography',
        ];
        
        $response = $this->postJson($this->baseUrl, $bishopData);
        
        $this->assertApiSuccess($response, 201);
        $this->assertDatabaseHas('bishops', [
            'full_name' => 'Most Rev. Test Bishop',
            'email' => 'bishop@testdiocese.org',
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->postJson($this->baseUrl, []);
        
        $this->assertValidationError($response, 'full_name');
        $this->assertValidationError($response, 'archdiocese_id');
    }

    /** @test */
    public function it_validates_email_format()
    {
        $this->actingAsSuperAdmin();
        
        $bishopData = [
            'full_name' => 'Test Bishop',
            'given_name' => 'Test',
            'family_name' => 'Bishop',
            'archdiocese_id' => $this->diocese->id,
            'email' => 'not-a-valid-email',
        ];
        
        $response = $this->postJson($this->baseUrl, $bishopData);
        
        $this->assertValidationError($response, 'email');
    }

    /** @test */
    public function it_can_update_a_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $bishop = BishopManagement::factory()->create();
        
        $updatedData = [
            'full_name' => 'Updated Bishop Name',
            'email' => 'updated@diocese.org',
            'biography' => 'Updated biography',
        ];
        
        $response = $this->putJson($this->baseUrl . '/' . $bishop->id, $updatedData);
        
        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'full_name' => 'Updated Bishop Name',
            'email' => 'updated@diocese.org',
        ]);
    }

    /** @test */
    public function it_can_delete_a_bishop()
    {
        $this->actingAsSuperAdmin();
        
        $bishop = BishopManagement::factory()->create();
        
        $response = $this->deleteJson($this->baseUrl . '/' . $bishop->id);
        
        $this->assertApiSuccess($response);
        $this->assertDatabaseMissing('bishops', [
            'id' => $bishop->id,
        ]);
    }

    /** @test */
    public function it_can_retrieve_bishop_statistics()
    {
        $this->actingAsSuperAdmin();
        
        // Create active and inactive bishops
        BishopManagement::factory()->count(10)->create(['status' => 'active']);
        BishopManagement::factory()->count(3)->create(['status' => 'inactive']);
        
        $response = $this->getJson($this->baseUrl . '/statistics');
        
        $this->assertApiSuccess($response);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total',
                'active',
                'inactive',
                'by_title',
            ],
            'message'
        ]);
        
        $this->assertEquals(13, $response->json('data.total'));
        $this->assertEquals(10, $response->json('data.active'));
    }

    /** @test */
    public function it_can_filter_bishops_by_diocese()
    {
        $this->actingAsSuperAdmin();
        
        $diocese1 = DioceseManagement::factory()->create();
        $diocese2 = DioceseManagement::factory()->create();
        
        BishopManagement::factory()->count(5)->create(['archdiocese_id' => $diocese1->id]);
        BishopManagement::factory()->count(3)->create(['archdiocese_id' => $diocese2->id]);
        
        $response = $this->getJson($this->baseUrl . '/diocese/' . $diocese1->id);
        
        $this->assertApiSuccess($response);
        $this->assertCount(5, $response->json('data'));
    }

    /** @test */
    public function it_can_filter_bishops_by_title()
    {
        $this->actingAsSuperAdmin();
        
        BishopManagement::factory()->count(4)->create(['additional_titles' => 'Archbishop']);
        BishopManagement::factory()->count(6)->create(['additional_titles' => 'Bishop']);
        
        $response = $this->getJson($this->baseUrl . '/title/Archbishop');
        
        $this->assertApiSuccess($response);
        // Since we're filtering by a specific title, should have those bishops
        $this->assertGreaterThanOrEqual(4, $response->json('data.total'));
    }

    /** @test */
    public function regular_user_cannot_create_bishop()
    {
        $this->actingAsUser();
        
        $bishopData = [
            'full_name' => 'Test Bishop',
            'archdiocese_id' => $this->diocese->id,
        ];
        
        $response = $this->postJson($this->baseUrl, $bishopData);
        
        $this->assertForbidden($response);
    }

    /** @test */
    public function it_validates_archdiocese_exists()
    {
        $this->actingAsSuperAdmin();
        
        $bishopData = [
            'full_name' => 'Test Bishop',
            'given_name' => 'Test',
            'family_name' => 'Bishop',
            'archdiocese_id' => 99999, // Non-existent
        ];
        
        $response = $this->postJson($this->baseUrl, $bishopData);
        
        $this->assertValidationError($response, 'archdiocese_id');
    }

    /** @test */
    public function it_filters_active_bishops_when_requested()
    {
        $this->actingAsSuperAdmin();
        
        BishopManagement::factory()->count(7)->create(['status' => 'active']);
        BishopManagement::factory()->count(3)->create(['status' => 'inactive']);
        
        $response = $this->getJson($this->baseUrl . '?is_active=1');
        
        $this->assertApiSuccess($response);
        $this->assertEquals(7, $response->json('data.total'));
    }

    /** @test */
    public function it_can_sort_bishops_by_full_name()
    {
        $this->actingAsSuperAdmin();
        
        BishopManagement::factory()->create(['full_name' => 'Zachary Smith']);
        BishopManagement::factory()->create(['full_name' => 'Andrew Jones']);
        BishopManagement::factory()->create(['full_name' => 'Benjamin Williams']);
        
        $response = $this->getJson($this->baseUrl . '?sort_by=full_name&sort_dir=asc');
        
        $this->assertApiSuccess($response);
        $bishops = $response->json('data.data');
        
        $this->assertEquals('Andrew Jones', $bishops[0]['full_name']);
        $this->assertEquals('Benjamin Williams', $bishops[1]['full_name']);
        $this->assertEquals('Zachary Smith', $bishops[2]['full_name']);
    }

    /** @test */
    public function it_returns_proper_error_for_invalid_id_format()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl . '/invalid-id');
        
        $this->assertTrue(in_array($response->status(), [404, 422]));
    }

    /** @test */
    public function it_respects_pagination_limits()
    {
        $this->actingAsSuperAdmin();
        
        BishopManagement::factory()->count(30)->create();
        
        // Test default pagination
        $response = $this->getJson($this->baseUrl);
        $this->assertApiSuccess($response);
        $this->assertLessThanOrEqual(15, count($response->json('data.data')));
        
        // Test custom per_page
        $response = $this->getJson($this->baseUrl . '?per_page=5');
        $this->assertCount(5, $response->json('data.data'));
    }

    /** @test */
    public function it_returns_correct_content_type_headers()
    {
        $this->actingAsSuperAdmin();
        
        $response = $this->getJson($this->baseUrl);
        
        $response->assertHeader('Content-Type', 'application/json');
    }
}
