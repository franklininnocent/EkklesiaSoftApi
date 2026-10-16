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

class TenantRbacEscalationApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $tenantAdminUser;
    protected Role $tenantAdminRole;
    protected Role $staffRole;

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

        $this->staffRole = Role::create([
            'name' => 'Staff',
            'description' => 'Staff role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantAdminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);
        $this->tenantAdminUser->syncRoles([$this->tenantAdminRole->id]);

        $requiredPermissionNames = [
            'roles.view',
            'roles.update',
            'roles.assign',
            'permissions.assign',
            'users.view',
        ];
        $permissionIds = [];
        foreach ($requiredPermissionNames as $permissionName) {
            $permission = Permission::updateOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'description' => 'Escalation test permission',
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
    public function it_blocks_permission_escalation_when_actor_lacks_target_permission(): void
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

        $response = $this->putJson("/api/tenant/roles/{$this->staffRole->id}/permissions", [
            'permission_ids' => [$extraPermission->id],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Permission escalation blocked', $response->json('message'));
    }

    #[Test]
    public function it_blocks_platform_scope_permission_assignment(): void
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

        $response = $this->putJson("/api/tenant/roles/{$this->staffRole->id}/permissions", [
            'permission_ids' => [$platformPermission->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('platform-only', $response->json('message'));
    }

    #[Test]
    public function it_blocks_removing_mandatory_permissions_from_tenant_admin_role(): void
    {
        $retainedPermissionId = Permission::where('name', 'roles.assign')->value('id');
        $this->assertNotNull($retainedPermissionId);

        $response = $this->putJson("/api/tenant/roles/{$this->tenantAdminRole->id}/permissions", [
            'permission_ids' => [(int) $retainedPermissionId],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must retain critical management permissions', $response->json('message'));
    }

    #[Test]
    public function it_blocks_permission_escalation_on_legacy_bulk_assignment_endpoint(): void
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

        $response = $this->postJson('/api/permissions/bulk-assign-to-role', [
            'role_id' => $this->staffRole->id,
            'permission_ids' => [$extraPermission->id],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Permission escalation blocked', $response->json('message'));
    }
}
