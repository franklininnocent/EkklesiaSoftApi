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

class TenantRbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $tenantAdminUser;
    protected Role $tenantAdminRole;
    protected Role $pastorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->tenantAdminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->pastorRole = Role::create([
            'name' => 'Pastor',
            'description' => 'Pastor Role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_DEFAULT_TEMPLATE,
        ]);

        $this->tenantAdminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);
        $this->tenantAdminUser->syncRoles([$this->tenantAdminRole->id]);

        // Grant route-level tenant permissions so tests hit business guardrails,
        // not middleware short-circuit responses.
        $requiredPermissionNames = [
            'roles.view',
            'roles.create',
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
                    'description' => 'Test permission',
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
        $this->tenantAdminRole->permissions()->syncWithoutDetaching($permissionIds);

        Passport::actingAs($this->tenantAdminUser);
    }

    #[Test]
    public function it_blocks_deleting_tenant_administrator_role(): void
    {
        $response = $this->deleteJson("/api/tenant/roles/{$this->tenantAdminRole->id}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Church Administrator role cannot be deleted.',
            ]);
    }

    #[Test]
    public function it_blocks_renaming_tenant_administrator_role(): void
    {
        $response = $this->putJson("/api/tenant/roles/{$this->tenantAdminRole->id}", [
            'name' => 'Renamed Admin',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Church Administrator role name cannot be changed.',
            ]);
    }

    #[Test]
    public function it_allows_updating_default_template_role(): void
    {
        $response = $this->putJson("/api/tenant/roles/{$this->pastorRole->id}", [
            'name' => 'Lead Pastor',
            'description' => 'Updated description',
            'level' => 3,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role.name', 'Lead Pastor');
    }

    #[Test]
    public function it_allows_deactivating_default_template_role(): void
    {
        $response = $this->postJson("/api/tenant/roles/{$this->pastorRole->id}/deactivate");

        $response->assertStatus(200)
            ->assertJsonPath('data.role.active', 0);
    }

    #[Test]
    public function it_blocks_removing_last_church_administrator_from_tenant(): void
    {
        $response = $this->putJson("/api/tenant/users/{$this->tenantAdminUser->id}/roles", [
            'role_ids' => [$this->pastorRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot remove the last Church Administrator from this tenant.',
            ]);
    }

    #[Test]
    public function it_blocks_deleting_default_template_role_with_assigned_users(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->pastorRole->id,
        ]);
        $user->syncRoles([$this->pastorRole->id]);

        $response = $this->deleteJson("/api/tenant/roles/{$this->pastorRole->id}");
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete role with assigned users. Reassign users first.',
            ]);
    }

    #[Test]
    public function it_blocks_assigning_roles_from_other_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignRole = Role::create([
            'name' => 'Foreign Pastor',
            'description' => 'Other tenant role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $otherTenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $member = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->pastorRole->id,
        ]);
        $member->syncRoles([$this->pastorRole->id]);

        $response = $this->putJson("/api/tenant/users/{$member->id}/roles", [
            'role_ids' => [$foreignRole->id],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'One or more selected roles are invalid for your tenant.',
            ]);
    }

    #[Test]
    public function it_blocks_platform_scope_permission_assignment_in_tenant_context(): void
    {
        $platformPermission = Permission::create([
            'name' => 'tenants.create',
            'display_name' => 'Create Tenants',
            'description' => 'Platform only',
            'module' => 'Tenants',
            'scope' => Permission::SCOPE_PLATFORM,
            'category' => 'tenants',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $response = $this->putJson("/api/tenant/roles/{$this->pastorRole->id}/permissions", [
            'permission_ids' => [$platformPermission->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('platform-only', $response->json('message'));
    }

    #[Test]
    public function it_blocks_permission_escalation_for_tenant_admin_assignment(): void
    {
        $existingPermission = Permission::create([
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

        $extraPermission = Permission::create([
            'name' => 'members.edit',
            'display_name' => 'Edit Members',
            'description' => 'Tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->tenantAdminRole->permissions()->syncWithoutDetaching([$existingPermission->id]);

        $response = $this->putJson("/api/tenant/roles/{$this->pastorRole->id}/permissions", [
            'permission_ids' => [$extraPermission->id],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Permission escalation blocked', $response->json('message'));
    }
}
