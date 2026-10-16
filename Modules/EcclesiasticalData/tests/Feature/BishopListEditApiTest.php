<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Models\EcclesiasticalAuditLog;
use Modules\EcclesiasticalData\Tests\Support\CreatesEkklesiaTestUser;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enterprise-grade API tests for Bishop List and Edit lifecycle.
 *
 * @group ecclesiastical
 * @group bishop-list-edit
 */
class BishopListEditApiTest extends TestCase
{
    use CreatesEkklesiaTestUser;
    use RefreshDatabase;

    protected string $baseUrl = '/api/ecclesiastical/bishops';

    protected DioceseManagement $diocese;

    protected int $bishopTitleId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'status' => 'active',
            ]);
        }

        if (! Country::where('iso2', 'IN')->exists()) {
            Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'status' => 'active',
            ]);
        }

        $this->diocese = DioceseManagement::factory()->create(['name' => 'Diocese of Kuzhithurai']);
        $this->bishopTitleId = $this->seedEcclesiasticalTitle('Bishop');
    }

    // -------------------------------------------------------------------------
    // LIST — retrieval, pagination, search, filter, sort
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_flat_bishop_rows_with_pagination_metadata(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->count(3)->create(['archdiocese_id' => $this->diocese->id]);

        $response = $this->getJson($this->baseUrl.'?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.current_page', 1);

        $rows = $response->json('data.data');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('full_name', $rows[0]);
        $this->assertArrayHasKey('archdiocese_id', $rows[0]);
    }

    #[Test]
    public function it_searches_bishops_by_name_case_insensitively(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Albert Anasthas',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Jerome Dhas Varuvel',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?search=albert');

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame(
            'Most Rev. Albert Anasthas',
            $response->json('data.data.0.full_name')
        );
    }

    #[Test]
    public function it_searches_bishops_by_diocese_name(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $otherDiocese = DioceseManagement::factory()->create(['name' => 'Diocese of Quilon']);

        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Albert Anasthas',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Jerome Dhas Varuvel',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Other Diocese Bishop',
            'archdiocese_id' => $otherDiocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?search=Kuzhithurai');

        $response->assertOk()
            ->assertJsonPath('data.total', 2);

        $names = collect($response->json('data.data'))->pluck('full_name')->all();

        $this->assertContains('Most Rev. Albert Anasthas', $names);
        $this->assertContains('Most Rev. Jerome Dhas Varuvel', $names);
    }

    #[Test]
    public function it_filters_bishops_by_diocese_id(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $otherDiocese = DioceseManagement::factory()->create();

        BishopManagement::factory()->create([
            'full_name' => 'Bishop In Target Diocese',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Bishop In Other Diocese',
            'archdiocese_id' => $otherDiocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?diocese_id='.$this->diocese->id);

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame(
            'Bishop In Target Diocese',
            $response->json('data.data.0.full_name')
        );
    }

    #[Test]
    public function it_filters_bishops_by_title_id(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $archbishopTitleId = $this->seedEcclesiasticalTitle('Archbishop');

        BishopManagement::factory()->create([
            'full_name' => 'Archbishop Alpha',
            'ecclesiastical_title_id' => $archbishopTitleId,
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Bishop Beta',
            'ecclesiastical_title_id' => $this->bishopTitleId,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?title_id='.$archbishopTitleId);

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame('Archbishop Alpha', $response->json('data.data.0.full_name'));
    }

    #[Test]
    public function it_filters_bishops_by_status_value(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Active Bishop',
            'status' => 'active',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Retired Bishop',
            'status' => 'retired',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?status=retired');

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame('Retired Bishop', $response->json('data.data.0.full_name'));
    }

    #[Test]
    public function it_returns_list_fields_needed_for_edit_modal(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. List Edit Consistency Bishop',
            'date_of_birth' => '1970-05-15',
            'ordained_priest_date' => '1995-06-10',
            'education' => 'STB, Rome',
            'ecclesiastical_title_id' => $this->bishopTitleId,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $listResponse = $this->getJson($this->baseUrl.'?search=List+Edit+Consistency+Bishop');
        $showResponse = $this->getJson($this->baseUrl.'/'.$bishop->id);

        $listRow = $listResponse->json('data.data.0');
        $showRow = $showResponse->json('data');

        $this->assertSame($showRow['date_of_birth'], $listRow['date_of_birth']);
        $this->assertSame($showRow['ordained_priest_date'], $listRow['ordained_priest_date']);
        $this->assertSame($showRow['education'], $listRow['education']);
        $this->assertSame($showRow['ecclesiastical_title_id'], $listRow['ecclesiastical_title_id']);
        $this->assertSame($showRow['ecclesiastical_title']['title'], $listRow['ecclesiastical_title']['title']);
    }

    #[Test]
    public function it_filters_active_bishops_when_is_active_true(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Active Bishop',
            'status' => 'active',
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Retired Bishop',
            'status' => 'retired',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?is_active=1');

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame('Active Bishop', $response->json('data.data.0.full_name'));
    }

    #[Test]
    public function it_filters_bishops_by_is_current_flag(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Current Bishop',
            'status' => 'active',
            'is_current' => true,
            'archdiocese_id' => $this->diocese->id,
        ]);
        BishopManagement::factory()->create([
            'full_name' => 'Historical Bishop',
            'status' => 'retired',
            'is_current' => false,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $currentResponse = $this->getJson($this->baseUrl.'?is_current=1');
        $currentResponse->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->assertSame('Current Bishop', $currentResponse->json('data.data.0.full_name'));

        $historicalResponse = $this->getJson($this->baseUrl.'?is_current=0');
        $historicalResponse->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->assertSame('Historical Bishop', $historicalResponse->json('data.data.0.full_name'));
    }

    #[Test]
    public function it_sorts_bishops_by_full_name_desc(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create(['full_name' => 'Andrew Bishop', 'archdiocese_id' => $this->diocese->id]);
        BishopManagement::factory()->create(['full_name' => 'Zachary Bishop', 'archdiocese_id' => $this->diocese->id]);

        $response = $this->getJson($this->baseUrl.'?sort_by=full_name&sort_dir=desc');

        $response->assertOk();

        $names = collect($response->json('data.data'))->pluck('full_name')->all();

        $this->assertSame('Zachary Bishop', $names[0]);
        $this->assertSame('Andrew Bishop', $names[1]);
    }

    #[Test]
    public function it_handles_sql_injection_in_search_safely(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Safe Bishop',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'?search='.urlencode("' OR '1'='1"));

        $response->assertOk();
        $this->assertSame(0, $response->json('data.total'));
        $this->assertDatabaseCount('bishops', 1);
    }

    #[Test]
    public function it_caps_per_page_at_one_hundred(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->count(5)->create(['archdiocese_id' => $this->diocese->id]);

        $response = $this->getJson($this->baseUrl.'?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100);
    }

    // -------------------------------------------------------------------------
    // LIST — authorization
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unauthenticated_list_requests(): void
    {
        $this->getJson($this->baseUrl)->assertUnauthorized();
    }

    #[Test]
    public function it_allows_view_only_user_to_list_bishops(): void
    {
        Passport::actingAs($this->createEkklesiaUser(['bishops.view'], Role::EKKLESIA_USER));

        BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->getJson($this->baseUrl)->assertOk();
    }

    #[Test]
    public function it_blocks_tenant_user_from_listing_platform_bishops(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $user = $this->tenantUser($tenant, ['bishops.view']);

        Passport::actingAs($user);

        $this->getJson($this->baseUrl)->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // EDIT — show / load for edit
    // -------------------------------------------------------------------------

    #[Test]
    public function it_loads_bishop_detail_for_edit_with_relationships(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Edit Target Bishop',
            'email' => 'edit.target@example.com',
            'archdiocese_id' => $this->diocese->id,
            'ecclesiastical_title_id' => $this->bishopTitleId,
            'status' => 'active',
            'is_current' => true,
        ]);

        $response = $this->getJson($this->baseUrl.'/'.$bishop->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $bishop->id)
            ->assertJsonPath('data.full_name', 'Most Rev. Edit Target Bishop')
            ->assertJsonPath('data.email', 'edit.target@example.com')
            ->assertJsonPath('data.archdiocese.name', $this->diocese->name);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_bishop_on_show(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->getJson($this->baseUrl.'/99999')->assertNotFound();
    }

    #[Test]
    public function it_loads_correct_bishop_when_two_exist(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishopA = BishopManagement::factory()->create([
            'full_name' => 'Bishop Alpha',
            'email' => 'alpha@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);
        $bishopB = BishopManagement::factory()->create([
            'full_name' => 'Bishop Beta',
            'email' => 'beta@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->getJson($this->baseUrl.'/'.$bishopB->id);

        $response->assertOk()
            ->assertJsonPath('data.full_name', 'Bishop Beta')
            ->assertJsonPath('data.email', 'beta@example.com');

        $this->assertNotSame($bishopA->id, $response->json('data.id'));
    }

    // -------------------------------------------------------------------------
    // EDIT — update, validation, persistence
    // -------------------------------------------------------------------------

    #[Test]
    public function it_updates_single_field_without_changing_others(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Partial Update Bishop',
            'email' => 'original@example.com',
            'phone' => '9876543210',
            'status' => 'active',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'updated@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.full_name', 'Most Rev. Partial Update Bishop');

        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'email' => 'updated@example.com',
            'full_name' => 'Most Rev. Partial Update Bishop',
            'phone' => '9876543210',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_updates_multiple_fields_in_one_request(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Multi Update Bishop',
            'email' => 'old@example.com',
            'status' => 'active',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'full_name' => 'Most Rev. Updated Name',
            'email' => 'new@example.com',
            'status' => 'retired',
            'education' => 'STB, Rome',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.full_name', 'Most Rev. Updated Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.education', 'STB, Rome');

        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'full_name' => 'Most Rev. Updated Name',
            'email' => 'new@example.com',
            'status' => 'retired',
        ]);
    }

    #[Test]
    public function it_strips_archdiocese_id_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $otherDiocese = DioceseManagement::factory()->create();
        $bishop = BishopManagement::factory()->create([
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'archdiocese_id' => $otherDiocese->id,
            'email' => 'diocese.stripped@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'archdiocese_id' => $this->diocese->id,
            'email' => 'diocese.stripped@example.com',
        ]);
    }

    #[Test]
    public function it_renormalizes_name_when_full_name_changes(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Old Name',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'full_name' => 'Most Rev. New Name',
        ])->assertOk();

        $updated = BishopManagement::find($bishop->id);
        $this->assertNotNull($updated?->normalized_name);
        $this->assertStringContainsString('new name', strtolower($updated->normalized_name));
    }

    #[Test]
    public function it_reflects_update_in_list_query(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. List Sync Bishop',
            'email' => 'listsync@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'listsync.updated@example.com',
        ])->assertOk();

        $listResponse = $this->getJson($this->baseUrl.'?search=List+Sync+Bishop');

        $listResponse->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.email', 'listsync.updated@example.com');
    }

    #[Test]
    public function it_allows_no_change_update_without_error(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. No Change Bishop',
            'email' => 'nochange@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'nochange@example.com',
        ])->assertOk();
    }

    #[Test]
    public function it_validates_email_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    #[Test]
    public function it_validates_status_enum_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'status' => 'invalid-status',
        ])->assertStatus(422);
    }

    #[Test]
    public function it_validates_date_of_birth_before_today_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'date_of_birth' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function it_stores_xss_payload_safely_in_education_field(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);
        $payload = '<script>alert(1)</script>';

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'education' => $payload,
        ])->assertOk()
            ->assertJsonPath('data.education', $payload);

        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'education' => $payload,
        ]);
    }

    #[Test]
    public function it_does_not_persist_invalid_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Validation Guard Bishop',
            'email' => 'guard@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'invalid-email',
        ])->assertStatus(422);

        $this->assertDatabaseHas('bishops', [
            'id' => $bishop->id,
            'email' => 'guard@example.com',
        ]);
    }

    // -------------------------------------------------------------------------
    // EDIT — audit
    // -------------------------------------------------------------------------

    #[Test]
    public function it_records_audit_log_on_successful_update(): void
    {
        Passport::actingAs($admin = $this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Audit Bishop',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'audit.updated@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('ecclesiastical_audit_log', [
            'entity_type' => 'bishops',
            'entity_id' => $bishop->id,
            'action' => 'update',
            'user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function it_does_not_record_audit_log_when_update_validation_fails(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);
        $beforeCount = EcclesiasticalAuditLog::where('entity_id', $bishop->id)->count();

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'bad-email',
        ])->assertStatus(422);

        $this->assertSame($beforeCount, EcclesiasticalAuditLog::where('entity_id', $bishop->id)->count());
    }

    // -------------------------------------------------------------------------
    // EDIT — authorization & security
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unauthenticated_update_requests(): void
    {
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'hack@example.com',
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_view_only_user_update_requests(): void
    {
        Passport::actingAs($this->createEkklesiaUser(['bishops.view'], Role::EKKLESIA_USER));

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'readonly.attempt@example.com',
        ])->assertForbidden();

        $this->assertDatabaseMissing('bishops', [
            'id' => $bishop->id,
            'email' => 'readonly.attempt@example.com',
        ]);
    }

    #[Test]
    public function it_blocks_tenant_user_from_updating_platform_bishops(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $user = $this->tenantUser($tenant, ['bishops.view', 'bishops.submit_update_request']);

        Passport::actingAs($user);

        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'email' => 'tenant.attempt@example.com',
        ])->assertForbidden();
    }

    #[Test]
    public function it_returns_forbidden_for_nonexistent_bishop_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->putJson($this->baseUrl.'/99999', [
            'email' => 'ghost@example.com',
        ]);

        $this->assertContains($response->status(), [403, 404]);
    }

    #[Test]
    public function it_ignores_mass_assignment_of_protected_fields_on_update(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $bishop = BishopManagement::factory()->create([
            'full_name' => 'Most Rev. Mass Assignment Bishop',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $originalCreatedAt = $bishop->created_at;

        $this->putJson($this->baseUrl.'/'.$bishop->id, [
            'id' => 99999,
            'normalized_name' => 'hacked',
            'email' => 'mass.assign@example.com',
        ])->assertOk();

        $fresh = BishopManagement::find($bishop->id);

        $this->assertSame($bishop->id, $fresh?->id);
        $this->assertSame('mass.assign@example.com', $fresh?->email);
        $this->assertNotSame('hacked', $fresh?->normalized_name);
        $this->assertTrue($fresh?->created_at->equalTo($originalCreatedAt));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function tenantUser(Tenant $tenant, array $permissions): User
    {
        $role = Role::create([
            'name' => 'Church Staff List Edit QA',
            'description' => 'Tenant test role',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Bishop list edit QA permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'bishops',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }

    private function seedEcclesiasticalTitle(string $title): int
    {
        $existing = DB::table('ecclesiastical_titles')->where('title', $title)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('ecclesiastical_titles')->insertGetId([
            'title' => $title,
            'abbreviation' => 'Bp.',
            'description' => 'Test bishop title',
            'hierarchy_level' => 4,
            'display_order' => 4,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
