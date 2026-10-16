<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Database\Seeders\AdminMinistriesPlatformPermissionSeeder;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMinistriesOrganizationsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function organizations_list_and_health_filters_work(): void
    {
        $this->authenticateAsSuperAdmin();

        $tenantA = Tenant::factory()->create([
            'name' => 'Parish A',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $tenantB = Tenant::factory()->create([
            'name' => 'Parish B',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);

        $emptyOrg = $this->createOrganization($tenantA->id, 'empty-org', 'Empty Org');
        $staffed = $this->createOrganization($tenantB->id, 'staffed-org', 'Staffed Org');
        $guest = GuestMember::create([
            'tenant_id' => $tenantB->id,
            'first_name' => 'Sam',
            'last_name' => 'Volunteer',
            'guest_type' => GuestMember::GUEST_TYPE_VOLUNTEER,
        ]);
        OrganizationMembership::create([
            'tenant_id' => $tenantB->id,
            'organization_id' => $staffed->id,
            'member_source' => OrganizationMembership::SOURCE_GUEST,
            'guest_member_id' => $guest->id,
            'family_member_id' => null,
            'member_type' => 'regular',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => now()->toDateString(),
            'is_current' => true,
        ]);

        $this->assertNotNull($emptyOrg->id);

        $this->getJson('/api/admin/ministries/organizations')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/admin/ministries/organizations?health=without_members')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Empty Org')
            ->assertJsonPath('data.0.health.without_members', true);

        $this->getJson('/api/admin/ministries/organizations?search=Staffed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant.name', 'Parish B');

        $this->getJson('/api/admin/ministries/health')
            ->assertOk()
            ->assertJsonPath('data.organizations.total', 2)
            ->assertJsonPath('data.indicators.orgs_without_active_members', 1)
            ->assertJsonPath('data.indicators.active_orgs_without_leadership', 2);
    }

    #[Test]
    public function organizations_endpoint_is_forbidden_for_tenant_users(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);
        Passport::actingAs($user);

        $this->getJson('/api/admin/ministries/organizations')->assertForbidden();
        $this->getJson('/api/admin/ministries/health')->assertForbidden();
    }

    private function authenticateAsSuperAdmin(): User
    {
        $this->seed(AdminMinistriesPlatformPermissionSeeder::class);

        $role = Role::query()->firstOrCreate(
            ['name' => Role::SUPER_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Super Admin',
                'level' => 0,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOrganization(int $tenantId, string $code, string $name): Organization
    {
        $category = OrganizationCategory::create([
            'tenant_id' => $tenantId,
            'code' => $code.'-cat',
            'name' => $name.' Category',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $type = OrganizationType::create([
            'tenant_id' => $tenantId,
            'code' => $code.'-type',
            'name' => $name.' Type',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        return Organization::create([
            'tenant_id' => $tenantId,
            'category_id' => $category->id,
            'type_id' => $type->id,
            'code' => $code,
            'name' => $name,
            'status' => Organization::STATUS_ACTIVE,
        ]);
    }
}
