<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-admin-api
 */
class BishopAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected User $ekklesiaUser;

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
        $this->ekklesiaUser = $this->createEkklesiaAdmin();
        Passport::actingAs($this->ekklesiaUser);
    }

    #[Test]
    public function it_returns_current_diocese_leadership(): void
    {
        $bishop = $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ordinary.bishop_id', $bishop->id)
            ->assertJsonPath('data.leadership_state', 'occupied');
    }

    #[Test]
    public function it_returns_leadership_history_for_diocese(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership/history");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function it_returns_ordinary_on_date(): void
    {
        $bishop = $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership/ordinary-on-date?date=2020-06-01");

        $response->assertOk()
            ->assertJsonPath('data.appointment.bishop_id', $bishop->id);
    }

    #[Test]
    public function it_replaces_current_ordinary_via_succession_endpoint(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->postJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/succession/replace-ordinary", [
            'person' => ['full_name' => 'Bishop Beta'],
            'appointment' => [
                'effective_date' => '2026-01-01',
                'canonical_role' => CanonicalRole::DiocesanBishop->value,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created.canonical_role', CanonicalRole::DiocesanBishop->value);

        $leadership = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership");
        $leadership->assertJsonPath('data.ordinary.bishop_name', 'Bishop Beta');
    }

    #[Test]
    public function it_lists_bishop_appointments(): void
    {
        $bishop = $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->getJson("/api/ecclesiastical/bishops/{$bishop->id}/appointments");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.effective_date', '2018-01-01');
    }

    #[Test]
    public function it_creates_auxiliary_appointment_for_bishop(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $auxiliary = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Auxiliary',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $response = $this->postJson("/api/ecclesiastical/bishops/{$auxiliary->id}/appointments", [
            'diocese_id' => $this->diocese->id,
            'canonical_role' => CanonicalRole::Auxiliary->value,
            'effective_date' => '2020-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.canonical_role', CanonicalRole::Auxiliary->value)
            ->assertJsonPath('data.is_current', true);
    }

    #[Test]
    public function it_ends_appointment_via_api(): void
    {
        $bishop = $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $appointment = BishopAppointment::query()->where('bishop_id', $bishop->id)->firstOrFail();

        $response = $this->postJson("/api/ecclesiastical/appointments/{$appointment->id}/end", [
            'ended_date' => '2026-01-01',
            'end_reason' => 'retirement',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.end_reason', 'retirement');
    }

    #[Test]
    public function it_creates_bishop_person_without_required_diocese(): void
    {
        $response = $this->postJson('/api/ecclesiastical/bishops', [
            'full_name' => 'Bishop Person Only',
            'status' => 'active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Bishop Person Only');
    }

    #[Test]
    public function it_uploads_bishop_photo(): void
    {
        Storage::fake('public');

        $bishop = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Photo',
            'status' => 'active',
        ], null, false);

        $response = $this->postJson("/api/ecclesiastical/bishops/{$bishop->id}/upload-photo", [
            'image' => UploadedFile::fake()->image('bishop.jpg', 100, 100),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['photo_path', 'photo_public_url']]);

        $this->assertNotNull($bishop->fresh()->photo_path);
    }

    #[Test]
    public function it_blocks_tenant_users_from_leadership_api(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $tenantUser = User::factory()->create(['tenant_id' => $tenant->id]);
        Passport::actingAs($tenantUser);

        $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_caps_bishop_list_per_page_at_one_hundred(): void
    {
        $response = $this->getJson('/api/ecclesiastical/bishops?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100);
    }

    #[Test]
    public function it_caps_leadership_history_per_page_at_one_hundred(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $response = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership/history?per_page=500");

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100);
    }

    private function createEkklesiaAdmin(): User
    {
        $permissions = [
            'bishops.view', 'bishops.create', 'bishops.update', 'bishops.archive',
            'bishops.manage_appointments', 'bishops.manage_images', 'bishops.view_audit',
            'dioceses.view', 'dioceses.create', 'dioceses.update', 'dioceses.delete',
        ];

        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Admin API test permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => Permission::SCOPE_PLATFORM,
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
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }

    private function createOrdinary(string $name, string $effectiveDate): BishopManagement
    {
        $bishop = app(BishopService::class)->createPerson([
            'full_name' => $name,
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);

        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => $effectiveDate],
        );

        return $bishop->fresh();
    }
}
