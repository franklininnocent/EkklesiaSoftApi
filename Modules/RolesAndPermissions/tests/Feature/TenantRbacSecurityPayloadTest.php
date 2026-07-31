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

class TenantRbacSecurityPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Role $adminRole;
    protected User $tenantAdmin;
    protected Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->adminRole = Role::create([
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

        $this->tenantAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->adminRole->id,
        ]);
        $this->tenantAdmin->syncRoles([$this->adminRole->id]);

        $requiredPermissionNames = [
            'roles.create',
            'roles.assign',
            'permissions.assign',
        ];
        $permissionIds = [];
        foreach ($requiredPermissionNames as $permissionName) {
            $permission = Permission::updateOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'description' => 'Security payload test permission',
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
        $this->adminRole->permissions()->syncWithoutDetaching($permissionIds);

        Passport::actingAs($this->tenantAdmin);
    }

    #[Test]
    public function it_rejects_oversized_role_name_payload(): void
    {
        $response = $this->postJson('/api/tenant/roles', [
            'name' => str_repeat('A', 300),
            'description' => 'Should fail validation',
            'level' => 4,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function it_rejects_non_array_permission_ids_payload(): void
    {
        $response = $this->putJson("/api/tenant/roles/{$this->staffRole->id}/permissions", [
            'permission_ids' => '{{constructor.constructor()}}',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['permission_ids']);
    }

    #[Test]
    public function it_rejects_non_array_role_ids_payload(): void
    {
        $response = $this->putJson("/api/tenant/users/{$this->tenantAdmin->id}/roles", [
            'role_ids' => 'DROP TABLE users;',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role_ids']);
    }
}
