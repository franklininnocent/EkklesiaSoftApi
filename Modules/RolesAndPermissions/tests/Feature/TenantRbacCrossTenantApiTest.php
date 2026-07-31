<?php

namespace Modules\RolesAndPermissions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantRbacCrossTenantApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $tenantAAdmin;
    protected Role $tenantARole;
    protected Role $tenantBRole;
    protected User $tenantBUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $tenantAAdminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant A Admin',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantARole = Role::create([
            'name' => 'Pastor A',
            'description' => 'Tenant A role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantBRole = Role::create([
            'name' => 'Pastor B',
            'description' => 'Tenant B role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenantB->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantAAdmin = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $tenantAAdminRole->id,
        ]);
        $this->tenantAAdmin->syncRoles([$tenantAAdminRole->id]);

        $this->tenantBUser = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role_id' => $this->tenantBRole->id,
        ]);
        $this->tenantBUser->syncRoles([$this->tenantBRole->id]);

        $requiredPermissionNames = [
            'roles.view',
            'roles.update',
            'roles.delete',
            'roles.assign',
            'permissions.view',
            'permissions.assign',
            'users.view',
        ];
        $permissionIds = [];
        foreach ($requiredPermissionNames as $permissionName) {
            $permission = Permission::updateOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'description' => 'Cross-tenant test permission',
                    'module' => 'RolesAndPermissions',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'test',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $tenantAAdminRole->permissions()->syncWithoutDetaching($permissionIds);

        Passport::actingAs($this->tenantAAdmin);
    }

    #[Test]
    public function tenant_role_listing_does_not_leak_other_tenant_roles(): void
    {
        $response = $this->getJson('/api/tenant/roles?per_page=all');

        $response->assertStatus(200);
        $response->assertJsonMissing(['name' => $this->tenantBRole->name]);
        $response->assertJsonFragment(['name' => $this->tenantARole->name]);
    }

    #[Test]
    public function it_blocks_foreign_role_reads_and_mutations(): void
    {
        $show = $this->getJson("/api/tenant/roles/{$this->tenantBRole->id}");
        $show->assertStatus(403);
        $show->assertJsonMissing(['name' => $this->tenantBRole->name]);

        $update = $this->putJson("/api/tenant/roles/{$this->tenantBRole->id}", [
            'description' => 'Attempted cross-tenant overwrite',
        ]);
        $update->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Role does not belong to your tenant.']);

        $delete = $this->deleteJson("/api/tenant/roles/{$this->tenantBRole->id}");
        $delete->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Role does not belong to your tenant.']);
    }

    #[Test]
    public function it_blocks_cross_tenant_role_permission_access(): void
    {
        $tenantPermission = Permission::create([
            'name' => 'members.view',
            'display_name' => 'View Members',
            'description' => 'Tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $getResponse = $this->getJson("/api/tenant/roles/{$this->tenantBRole->id}/permissions");
        $getResponse->assertStatus(403);
        $getResponse->assertJsonMissing(['name' => $tenantPermission->name]);

        $putResponse = $this->putJson("/api/tenant/roles/{$this->tenantBRole->id}/permissions", [
            'permission_ids' => [$tenantPermission->id],
        ]);
        $putResponse->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Role does not belong to your tenant.']);
    }

    #[Test]
    public function it_blocks_cross_tenant_user_role_access_and_assignment(): void
    {
        $getResponse = $this->getJson("/api/tenant/users/{$this->tenantBUser->id}/roles");
        $getResponse->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ]);

        $putResponse = $this->putJson("/api/tenant/users/{$this->tenantBUser->id}/roles", [
            'role_ids' => [$this->tenantARole->id],
        ]);
        $putResponse->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ]);
    }
}
