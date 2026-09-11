<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Models\EcclesiasticalAuditLog;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Tests\Support\CreatesEkklesiaTestUser;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enterprise-grade API tests for the Create New Bishop modal payload and lifecycle.
 *
 * @group ecclesiastical
 * @group bishop-create-modal
 */
class BishopCreateModalApiTest extends TestCase
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

        $this->diocese = DioceseManagement::factory()->create();
        $this->bishopTitleId = $this->seedEcclesiasticalTitle('Bishop');
    }

    #[Test]
    public function it_creates_bishop_with_all_modal_fields_and_persists_to_database(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $payload = $this->validModalPayload([
            'religious_name' => 'Brother Joseph',
            'education' => "STB, Rome\nMA Theology",
        ]);

        $response = $this->postJson($this->baseUrl, $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', $payload['full_name'])
            ->assertJsonPath('data.religious_name', 'Brother Joseph')
            ->assertJsonPath('data.archdiocese_id', $this->diocese->id)
            ->assertJsonPath('data.ecclesiastical_title_id', $this->bishopTitleId)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.email', 'bishop.qa@example.com')
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonPath('data.education', "STB, Rome\nMA Theology")
            ->assertJsonPath('data.date_of_birth', '1955-03-15')
            ->assertJsonPath('data.ordained_priest_date', '1980-06-01')
            ->assertJsonPath('data.ordained_bishop_date', '2005-09-09')
            ->assertJsonPath('data.appointed_date', '2006-01-01');

        $bishopId = $response->json('data.id');

        $this->assertDatabaseHas('bishops', [
            'id' => $bishopId,
            'full_name' => $payload['full_name'],
            'religious_name' => 'Brother Joseph',
            'archdiocese_id' => $this->diocese->id,
            'ecclesiastical_title_id' => $this->bishopTitleId,
            'email' => 'bishop.qa@example.com',
            'phone' => '9876543210',
            'status' => 'active',
            'is_current' => true,
        ]);

        $this->assertNotNull(BishopManagement::find($bishopId)?->normalized_name);
    }

    #[Test]
    public function it_records_audit_log_on_successful_create(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload());
        $bishopId = $response->json('data.id');

        $this->assertDatabaseHas('ecclesiastical_audit_log', [
            'entity_type' => 'bishops',
            'entity_id' => $bishopId,
            'action' => 'create',
        ]);
    }

    #[Test]
    public function it_does_not_record_audit_log_when_validation_fails(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $beforeCount = EcclesiasticalAuditLog::count();

        $this->postJson($this->baseUrl, ['email' => 'not-an-email'])
            ->assertStatus(422);

        $this->assertSame($beforeCount, EcclesiasticalAuditLog::count());
    }

    #[Test]
    public function it_rejects_unauthenticated_create_requests(): void
    {
        $this->postJson($this->baseUrl, $this->validModalPayload())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_platform_viewer_without_create_permission(): void
    {
        Passport::actingAs($this->createEkklesiaUser(['bishops.view'], 'bishop_viewer'));

        $this->postJson($this->baseUrl, $this->validModalPayload())
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_rejects_tenant_user_from_create_endpoint(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $tenantUser = \Modules\Authentication\Models\User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Passport::actingAs($tenantUser);

        $this->postJson($this->baseUrl, $this->validModalPayload())
            ->assertForbidden();
    }

    #[Test]
    public function it_requires_full_name_when_creating_bishop(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, [
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['full_name']);
    }

    #[Test]
    public function it_allows_create_without_archdiocese_id_on_backend(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, [
            'full_name' => 'Bishop Without Diocese',
            'status' => 'active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Bishop Without Diocese')
            ->assertJsonPath('data.archdiocese_id', null);
    }

    #[Test]
    public function it_rejects_nonexistent_archdiocese_id(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'archdiocese_id' => 999999,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['archdiocese_id']);
    }

    #[Test]
    public function it_rejects_nonexistent_ecclesiastical_title_id(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'ecclesiastical_title_id' => 999999,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['ecclesiastical_title_id']);
    }

    #[Test]
    public function it_rejects_future_date_of_birth(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'date_of_birth' => now()->addDay()->toDateString(),
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    #[Test]
    public function it_accepts_today_as_invalid_date_of_birth(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'date_of_birth' => now()->toDateString(),
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    #[Test]
    public function it_rejects_invalid_email_format(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'email' => 'not-a-valid-email',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function it_allows_duplicate_email_across_bishops(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'email' => 'duplicate@example.com',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Another Bishop Same Email',
            'email' => 'duplicate@example.com',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.email', 'duplicate@example.com');
    }

    #[Test]
    public function it_rejects_invalid_photo_url(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'photo_url' => 'not-a-url',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    #[Test]
    public function it_rejects_javascript_photo_url_protocol(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'photo_url' => 'javascript:alert(1)',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    #[Test]
    public function it_rejects_client_photo_url_on_create(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'photo_url' => 'https://cdn.example.com/images/bishop.webp?size=large',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['photo_url']);
    }

    #[Test]
    public function it_rejects_invalid_status_value(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'status' => 'suspended',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    #[Test]
    #[DataProvider('validStatusProvider')]
    public function it_accepts_all_configured_status_values(string $status): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => "Bishop Status {$status}",
            'status' => $status,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', $status);
    }

    public static function validStatusProvider(): array
    {
        return [
            'active' => ['active'],
            'retired' => ['retired'],
            'deceased' => ['deceased'],
            'inactive' => ['inactive'],
        ];
    }

    #[Test]
    public function it_accepts_optional_religious_name_empty(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'religious_name' => null,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.religious_name', null);
    }

    #[Test]
    public function it_persists_unicode_and_special_characters_in_text_fields(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $fullName = "Most Rev. François O'Brien-Smith";
        $education = "பொ.ந.பு. (Tamil)\nMA Pastoral Theology — St. Peter's";

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => $fullName,
            'education' => $education,
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('bishops', [
            'id' => $response->json('data.id'),
            'full_name' => $fullName,
            'education' => $education,
        ]);
    }

    #[Test]
    public function it_stores_xss_payloads_as_literal_text_without_execution(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $payload = "<script>alert('xss')</script>";

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'religious_name' => $payload,
            'education' => $payload,
        ]));

        $response->assertCreated();

        $bishop = BishopManagement::find($response->json('data.id'));
        $this->assertSame($payload, $bishop->religious_name);
        $this->assertSame($payload, $bishop->education);
    }

    #[Test]
    public function it_rejects_high_confidence_duplicate_bishop(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $existing = app(BishopService::class)->createPerson([
            'full_name' => 'Most Rev. John Duplicate',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $response = $this->postJson($this->baseUrl, [
            'full_name' => 'Most Rev. John Duplicate',
            'date_of_birth' => '1960-05-10',
            'ordained_priest_date' => '1985-06-01',
            'ordained_bishop_date' => '2010-09-01',
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(1, BishopManagement::where('normalized_name', $existing->normalized_name)->count());
    }

    #[Test]
    public function it_allows_same_name_without_matching_identity_dates(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        BishopManagement::factory()->create([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1960-05-10',
            'archdiocese_id' => $this->diocese->id,
        ]);

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. John Smith',
            'date_of_birth' => '1970-01-01',
        ]));

        $response->assertCreated();
    }

    #[Test]
    public function it_coerces_is_current_boolean_from_json(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop Not Current',
            'is_current' => false,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.is_current', false);

        $this->assertDatabaseHas('bishops', [
            'id' => $response->json('data.id'),
            'is_current' => false,
        ]);
    }

    #[Test]
    public function it_rejects_is_current_string_instead_of_boolean(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'is_current' => 'true',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['is_current']);
    }

    #[Test]
    public function it_ignores_injected_id_in_create_payload(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'id' => 999999,
            'full_name' => 'Bishop Safe Id',
        ]));

        $response->assertCreated();

        $this->assertNotSame(999999, (int) $response->json('data.id'));
        $this->assertDatabaseHas('bishops', [
            'id' => $response->json('data.id'),
            'full_name' => 'Bishop Safe Id',
        ]);
    }

    #[Test]
    public function it_does_not_create_partial_record_on_validation_failure(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $before = BishopManagement::count();

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'photo_url' => 'javascript:alert(1)',
        ]))->assertStatus(422);

        $this->assertSame($before, BishopManagement::count());
    }

    #[Test]
    public function it_allows_multiple_bishops_with_is_current_true_for_same_diocese_on_person_record(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop Serving One',
            'is_current' => true,
        ]))->assertCreated();

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop Serving Two',
            'is_current' => true,
        ]));

        $response->assertCreated();

        $this->assertSame(
            2,
            BishopManagement::query()
                ->where('archdiocese_id', $this->diocese->id)
                ->where('is_current', true)
                ->count()
        );
    }

    #[Test]
    public function it_does_not_validate_date_chronology_at_form_request_layer(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $response = $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop Invalid Chronology',
            'date_of_birth' => '1980-01-01',
            'ordained_priest_date' => '1970-01-01',
            'ordained_bishop_date' => '1960-01-01',
            'appointed_date' => '1950-01-01',
        ]));

        $response->assertCreated();
    }

    #[Test]
    public function it_retrieves_created_bishop_with_matching_fields_via_show_endpoint(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $createResponse = $this->postJson($this->baseUrl, $this->validModalPayload());
        $bishopId = $createResponse->json('data.id');

        $showResponse = $this->getJson("{$this->baseUrl}/{$bishopId}");

        $showResponse->assertOk()
            ->assertJsonPath('data.id', $bishopId)
            ->assertJsonPath('data.email', 'bishop.qa@example.com')
            ->assertJsonPath('data.archdiocese.id', $this->diocese->id);
    }

    #[Test]
    public function it_filters_bishops_by_archdiocese_id_on_list(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $otherDiocese = DioceseManagement::factory()->create();

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop In Target Diocese',
        ]))->assertCreated();

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Bishop In Other Diocese',
            'archdiocese_id' => $otherDiocese->id,
        ]))->assertCreated();

        $response = $this->getJson($this->baseUrl.'?diocese_id='.$this->diocese->id);

        $response->assertOk()
            ->assertJsonPath('data.total', 1);

        $rows = $response->json('data.data');
        $this->assertSame('Bishop In Target Diocese', $rows[0]['full_name'] ?? null);
    }

    #[Test]
    public function it_finds_bishops_by_diocese_name_in_quick_search(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->diocese->update(['name' => 'Diocese of Kuzhithurai']);

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. Dr. Albert Anasthas',
        ]))->assertCreated();

        $otherDiocese = DioceseManagement::factory()->create(['name' => 'Diocese of Quilon']);

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. Jerome Dhas Varuvel',
            'archdiocese_id' => $this->diocese->id,
        ]))->assertCreated();

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. Other Diocese Bishop',
            'archdiocese_id' => $otherDiocese->id,
        ]))->assertCreated();

        $response = $this->getJson($this->baseUrl.'?search=Kuzhithurai');

        $response->assertOk()
            ->assertJsonPath('data.total', 2);

        $names = collect($response->json('data.data'))->pluck('full_name')->all();

        $this->assertContains('Most Rev. Dr. Albert Anasthas', $names);
        $this->assertContains('Most Rev. Jerome Dhas Varuvel', $names);
        $this->assertNotContains('Most Rev. Other Diocese Bishop', $names);
    }

    #[Test]
    public function it_lists_modal_created_bishops_on_diocese_bishops_endpoint(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. Dr. Albert Anasthas',
        ]))->assertCreated();

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'Most Rev. Jerome Dhas Varuvel',
        ]))->assertCreated();

        $response = $this->getJson($this->baseUrl.'/diocese/'.$this->diocese->id);

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('full_name')->all();

        $this->assertContains('Most Rev. Dr. Albert Anasthas', $names);
        $this->assertContains('Most Rev. Jerome Dhas Varuvel', $names);
    }

    #[Test]
    public function it_returns_flat_bishop_rows_in_list_response(): void
    {
        Passport::actingAs($this->createEkklesiaAdmin());

        $this->postJson($this->baseUrl, $this->validModalPayload([
            'full_name' => 'List Response Bishop',
        ]))->assertCreated();

        $response = $this->getJson($this->baseUrl);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1);

        $rows = $response->json('data.data');
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertSame(
            'List Response Bishop',
            collect($rows)->firstWhere('full_name', 'List Response Bishop')['full_name'] ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validModalPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Most Rev. QA Test Bishop',
            'given_name' => 'QA',
            'family_name' => 'Bishop',
            'archdiocese_id' => $this->diocese->id,
            'ecclesiastical_title_id' => $this->bishopTitleId,
            'status' => 'active',
            'is_current' => true,
            'date_of_birth' => '1955-03-15',
            'ordained_priest_date' => '1980-06-01',
            'ordained_bishop_date' => '2005-09-09',
            'appointed_date' => '2006-01-01',
            'email' => 'bishop.qa@example.com',
            'phone' => '9876543210',
        ], $overrides);
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
