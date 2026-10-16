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

class TenantRbacAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $tenantAdmin;
    protected Role $adminRole;
    protected Role $staffRole;
    protected User $member;

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
            'description' => 'Staff',
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
        $this->member = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->staffRole->id,
        ]);
        $this->member->syncRoles([$this->staffRole->id]);

        $rolesAssign = Permission::updateOrCreate(
            ['name' => 'roles.assign'],
            [
                'display_name' => 'Assign Roles',
                'description' => 'Assign roles',
                'module' => 'RolesAndPermissions',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'roles',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $permissionsAssign = Permission::updateOrCreate(
            ['name' => 'permissions.assign'],
            [
                'display_name' => 'Assign Permissions',
                'description' => 'Assign permissions',
                'module' => 'RolesAndPermissions',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'permissions',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $usersView = Permission::updateOrCreate(
            ['name' => 'users.view'],
            [
                'display_name' => 'View Users',
                'description' => 'View users',
                'module' => 'Users',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'users',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $this->adminRole->permissions()->syncWithoutDetaching([$rolesAssign->id, $permissionsAssign->id, $usersView->id]);

        Passport::actingAs($this->tenantAdmin);
    }

    #[Test]
    public function syncing_user_roles_creates_assignment_audit_log(): void
    {
        $response = $this->putJson("/api/tenant/users/{$this->member->id}/roles", [
            'role_ids' => [$this->adminRole->id, $this->staffRole->id],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('permission_audit_logs', [
            'action' => 'role_assigned_to_user',
            'tenant_id' => $this->tenant->id,
        ]);

        $entry = \Illuminate\Support\Facades\DB::table('permission_audit_logs')
            ->where('action', 'role_assigned_to_user')
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry);
        $metadata = json_decode((string) $entry->metadata, true);
        $this->assertSame($this->adminRole->id, $metadata['role_id'] ?? null);
        $this->assertSame($this->member->id, $metadata['user_id'] ?? null);
        $this->assertSame($this->tenantAdmin->id, $metadata['assigned_by'] ?? null);
    }

    #[Test]
    public function blocked_permission_assignment_does_not_write_bulk_assignment_audit_log(): void
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

        $this->assertDatabaseMissing('permission_audit_logs', [
            'action' => 'bulk_permissions_assigned_to_role',
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
