<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Exceptions\ChurchLeadershipDomainException;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipRoleCategory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class LeadershipRoleCatalogTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private ChurchProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeadershipRolesSeeder::class);

        Permission::updateOrCreate(
            ['name' => 'church.leadership.roles.create'],
            [
                'display_name' => 'Create Custom Leadership Roles',
                'description' => 'Create tenant-specific leadership role titles',
                'module' => 'ChurchSettings',
                'category' => 'settings',
                'tenant_id' => null,
                'is_custom' => false,
                'scope' => Permission::SCOPE_TENANT,
                'active' => 1,
            ]
        );

        $this->tenant = $this->makeOperationalTenant();
        $this->profile = ChurchProfile::factory()->create(['tenant_id' => $this->tenant->id]);

        $adminBundle = $this->makeTenantPersona(
            $this->tenant,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->admin = $adminBundle['user'];
    }

    private function authenticateAdmin(): void
    {
        Passport::actingAs($this->admin);
    }

    #[Test]
    public function authorized_user_can_create_custom_role_for_current_tenant(): void
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/api/church-profile/leadership/roles', [
            'title' => 'Associate Parish Priest',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Associate Parish Priest')
            ->assertJsonPath('data.is_system_defined', false)
            ->assertJsonPath('data.scope', 'tenant');

        $roleId = $response->json('data.id');
        $role = LeadershipRole::query()->findOrFail($roleId);
        $this->assertSame($this->tenant->id, $role->tenant_id);
        $this->assertSame('associate parish priest', $role->normalized_title);
        $this->assertSame(LeadershipRoleCategory::PARISH_CLERGY, $role->category);
        $this->assertTrue($role->allows_concurrent);

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'leadership.role.created',
            'target_type' => 'leadership_role',
            'target_id' => $roleId,
        ]);
    }

    #[Test]
    public function duplicate_system_role_is_rejected_with_role_already_exists(): void
    {
        $this->authenticateAdmin();

        foreach (['Pastor', 'pastor', 'PASTOR', ' Pastor ', 'Pastor  '] as $title) {
            $this->postJson('/api/church-profile/leadership/roles', ['title' => $title])
                ->assertStatus(409)
                ->assertJsonPath('code', ChurchLeadershipDomainException::ROLE_ALREADY_EXISTS);
        }
    }

    #[Test]
    public function duplicate_tenant_role_is_rejected(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Chaplain'])->assertCreated();

        $this->postJson('/api/church-profile/leadership/roles', ['title' => 'chaplain'])
            ->assertStatus(409)
            ->assertJsonPath('code', ChurchLeadershipDomainException::ROLE_ALREADY_EXISTS);
    }

    #[Test]
    public function tenant_b_cannot_see_tenant_a_custom_role(): void
    {
        $this->authenticateAdmin();
        $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Chaplain'])->assertCreated();

        $tenantB = $this->makeOperationalTenant();
        ChurchProfile::factory()->create(['tenant_id' => $tenantB->id]);
        $userB = $this->makeTenantPersona(
            $tenantB,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
        )['user'];
        Passport::actingAs($userB);

        $titles = collect($this->getJson('/api/church-profile/leadership/roles')->json('data'))
            ->pluck('title');

        $this->assertFalse($titles->contains('Chaplain'));
    }

    #[Test]
    public function tenant_b_cannot_assign_tenant_a_custom_role_id(): void
    {
        $this->authenticateAdmin();
        $roleId = $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Chaplain'])
            ->assertCreated()
            ->json('data.id');

        $tenantB = $this->makeOperationalTenant();
        $profileB = ChurchProfile::factory()->create(['tenant_id' => $tenantB->id]);
        $personB = \Modules\Family\Models\Person::factory()->create([
            'tenant_id' => $tenantB->id,
            'created_by' => $this->admin->id,
        ]);
        $userB = $this->makeTenantPersona(
            $tenantB,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
        )['user'];
        Passport::actingAs($userB);

        $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $personB->id,
            'role_id' => $roleId,
            'start_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function user_without_permission_cannot_create_custom_role(): void
    {
        $staffBundle = $this->makeTenantPersona(
            $this->tenant,
            'Staff',
            ['church.settings.edit'],
            ['is_custom' => true, 'level' => 5]
        );
        Passport::actingAs($staffBundle['user']);

        $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Custom Role'])
            ->assertForbidden();
    }

    #[Test]
    public function validation_rejects_blank_and_overlong_titles(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/church-profile/leadership/roles', ['title' => '   '])
            ->assertStatus(422);

        $this->postJson('/api/church-profile/leadership/roles', [
            'title' => str_repeat('A', 101),
        ])->assertStatus(422);
    }

    #[Test]
    public function parish_clergy_catalog_includes_parish_priest_titles(): void
    {
        $this->authenticateAdmin();

        $titles = collect($this->getJson('/api/church-profile/leadership/roles?category=PARISH_CLERGY')->json('data'))
            ->pluck('title');

        $this->assertTrue($titles->contains('Parish Priest'));
        $this->assertTrue($titles->contains('Assistant Parish Priest'));
        $this->assertTrue($titles->contains('Joint Parish Priest'));

        $parishPriest = LeadershipRole::query()->whereNull('tenant_id')->where('title', 'Parish Priest')->firstOrFail();
        $assistant = LeadershipRole::query()->whereNull('tenant_id')->where('title', 'Assistant Parish Priest')->firstOrFail();
        $joint = LeadershipRole::query()->whereNull('tenant_id')->where('title', 'Joint Parish Priest')->firstOrFail();

        $this->assertSame(LeadershipRoleCategory::PARISH_CLERGY, $parishPriest->category);
        $this->assertFalse($parishPriest->allows_concurrent);
        $this->assertTrue($assistant->allows_concurrent);
        $this->assertTrue($joint->allows_concurrent);

        $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Parish Priest'])
            ->assertStatus(409)
            ->assertJsonPath('code', ChurchLeadershipDomainException::ROLE_ALREADY_EXISTS);
    }

    #[Test]
    public function sync_absorbs_tenant_copy_of_a_system_parish_clergy_role(): void
    {
        $system = LeadershipRole::query()
            ->whereNull('tenant_id')
            ->where('normalized_title', 'joint parish priest')
            ->firstOrFail();
        $system->delete();

        $tenantRole = LeadershipRole::factory()->forTenant($this->tenant)->create([
            'title' => 'Joint Parish Priest',
            'category' => LeadershipRoleCategory::OTHER,
            'allows_concurrent' => true,
        ]);

        $person = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->admin->id,
        ]);

        $assignment = LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $tenantRole->id,
        ]);

        (new LeadershipRolesSeeder())->run();

        $this->assertDatabaseMissing('leadership_roles', ['id' => $tenantRole->id]);

        $restored = LeadershipRole::query()
            ->whereNull('tenant_id')
            ->where('normalized_title', 'joint parish priest')
            ->first();

        $this->assertNotNull($restored);
        $this->assertSame(LeadershipRoleCategory::PARISH_CLERGY, $restored->category);
        $this->assertSame($restored->id, $assignment->fresh()->role_id);
    }

    #[Test]
    public function list_roles_includes_scope_fields(): void
    {
        $this->authenticateAdmin();

        $response = $this->getJson('/api/church-profile/leadership/roles')->assertOk();
        $pastor = collect($response->json('data'))->firstWhere('title', 'Pastor');

        $this->assertNotNull($pastor);
        $this->assertTrue($pastor['is_system_defined']);
        $this->assertSame('system', $pastor['scope']);
        $this->assertTrue($pastor['is_global']);
    }

    #[Test]
    public function authorized_user_can_create_custom_role_with_explicit_category(): void
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/api/church-profile/leadership/roles', [
            'title' => 'Parish Secretary',
            'category' => LeadershipRoleCategory::PARISH_COUNCIL,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Parish Secretary')
            ->assertJsonPath('data.category', LeadershipRoleCategory::PARISH_COUNCIL)
            ->assertJsonPath('data.category_label', 'Parish Councils');
    }

    #[Test]
    public function authorized_user_can_update_custom_role_category(): void
    {
        $this->authenticateAdmin();

        $roleId = $this->postJson('/api/church-profile/leadership/roles', [
            'title' => 'Parish Secretary',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/church-profile/leadership/roles/{$roleId}", [
            'category' => LeadershipRoleCategory::PARISH_COUNCIL,
        ])->assertOk()
            ->assertJsonPath('data.category', LeadershipRoleCategory::PARISH_COUNCIL)
            ->assertJsonPath('data.category_label', 'Parish Councils');

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'leadership.role.updated',
            'target_type' => 'leadership_role',
            'target_id' => $roleId,
        ]);
    }

    #[Test]
    public function system_role_category_cannot_be_updated(): void
    {
        $this->authenticateAdmin();

        $pastor = LeadershipRole::query()->whereNull('tenant_id')->where('title', 'Pastor')->firstOrFail();

        $this->putJson("/api/church-profile/leadership/roles/{$pastor->id}", [
            'category' => LeadershipRoleCategory::OTHER,
        ])->assertForbidden();
    }

    #[Test]
    public function tenant_b_cannot_update_tenant_a_custom_role(): void
    {
        $this->authenticateAdmin();
        $roleId = $this->postJson('/api/church-profile/leadership/roles', ['title' => 'Chaplain'])
            ->assertCreated()
            ->json('data.id');

        $tenantB = $this->makeOperationalTenant();
        ChurchProfile::factory()->create(['tenant_id' => $tenantB->id]);
        $userB = $this->makeTenantPersona(
            $tenantB,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
        )['user'];
        Passport::actingAs($userB);

        $this->putJson("/api/church-profile/leadership/roles/{$roleId}", [
            'category' => LeadershipRoleCategory::PARISH_CLERGY,
        ])->assertForbidden();
    }
}
