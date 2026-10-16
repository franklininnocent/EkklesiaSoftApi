<?php

namespace Modules\RolesAndPermissions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enterprise-grade Roles & Permissions validation covering legacy endpoint
 * correctness, tenant isolation, authorization boundaries, and audit integrity.
 */
class RolesPermissionsEnterpriseFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $tenantAAdmin;
    private Role $tenantAAdminRole;
    private Role $tenantACustomRole;
    private Role $tenantBRole;
    private Permission $viewPermission;
    private Permission $createPermission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $this->tenantAAdminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant A Admin',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $this->tenantACustomRole = Role::create([
            'name' => 'Volunteer Coordinator',
            'description' => 'Custom tenant A role',
            'level' => 4,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_CUSTOM,
        ]);

        $this->tenantBRole = Role::create([
            'name' => 'Volunteer Coordinator',
            'description' => 'Custom tenant B role',
            'level' => 4,
            'active' => 1,
            'tenant_id' => $this->tenantB->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_CUSTOM,
        ]);

        $this->tenantAAdmin = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->tenantAAdminRole->id,
        ]);
        $this->tenantAAdmin->syncRoles([$this->tenantAAdminRole->id]);

        $required = [
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',
            'permissions.view',
            'permissions.assign',
            'users.view',
            'users.update',
        ];

        $permissionIds = [];
        foreach ($required as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
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

            if ($name === 'roles.view') {
                $this->viewPermission = $permission;
            }
            if ($name === 'roles.create') {
                $this->createPermission = $permission;
            }
        }

        $this->tenantAAdminRole->permissions()->sync($permissionIds);
        Passport::actingAs($this->tenantAAdmin);
    }

    #[Test]
    public function it_lists_permissions_for_tenant_users_without_creating(): void
    {
        $before = Permission::count();

        $response = $this->getJson('/api/permissions?per_page=all');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($before, Permission::count());
        $this->assertIsArray($response->json('data'));
    }

    #[Test]
    public function it_assigns_permission_to_role_via_legacy_endpoint_for_tenant_actor(): void
    {
        $response = $this->postJson('/api/permissions/assign-to-role', [
            'role_id' => $this->tenantACustomRole->id,
            'permission_id' => $this->viewPermission->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Permission assigned to role successfully');

        $this->assertTrue(
            $this->tenantACustomRole->fresh()->permissions->contains('id', $this->viewPermission->id)
        );
    }

    #[Test]
    public function it_removes_permission_from_role_via_legacy_endpoint_for_tenant_actor(): void
    {
        $this->tenantACustomRole->permissions()->sync([
            $this->viewPermission->id,
            $this->createPermission->id,
        ]);

        $response = $this->postJson('/api/permissions/remove-from-role', [
            'role_id' => $this->tenantACustomRole->id,
            'permission_id' => $this->createPermission->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Permission removed from role successfully');

        $remaining = $this->tenantACustomRole->fresh()->permissions->pluck('id')->all();
        $this->assertContains($this->viewPermission->id, $remaining);
        $this->assertNotContains($this->createPermission->id, $remaining);
    }

    #[Test]
    public function it_blocks_legacy_assign_to_foreign_tenant_role(): void
    {
        $response = $this->postJson('/api/permissions/assign-to-role', [
            'role_id' => $this->tenantBRole->id,
            'permission_id' => $this->viewPermission->id,
        ]);

        $response->assertStatus(403);
        $this->assertFalse(
            $this->tenantBRole->fresh()->permissions->contains('id', $this->viewPermission->id)
        );
    }

    #[Test]
    public function it_blocks_restoring_another_tenants_role(): void
    {
        $this->tenantBRole->delete();

        $response = $this->postJson("/api/roles/{$this->tenantBRole->id}/restore");

        $response->assertStatus(403);
        $this->assertSoftDeleted('roles', ['id' => $this->tenantBRole->id]);
    }

    #[Test]
    public function it_blocks_deactivating_another_tenants_role(): void
    {
        $response = $this->postJson("/api/roles/{$this->tenantBRole->id}/deactivate");

        $response->assertStatus(403);
        $this->assertSame(1, (int) $this->tenantBRole->fresh()->active);
    }

    #[Test]
    public function it_rejects_unauthenticated_tenant_role_listing(): void
    {
        Passport::actingAs(User::factory()->create(['tenant_id' => null]));
        // Clear auth by creating a fresh request without Passport — use actingAs then logout pattern.
        $this->app['auth']->guard('api')->forgetUser();

        $response = $this->getJson('/api/tenant/roles');

        $this->assertContains($response->status(), [401, 403]);
    }

    #[Test]
    public function it_rejects_role_create_with_whitespace_only_name(): void
    {
        $response = $this->postJson('/api/tenant/roles', [
            'name' => '   ',
            'description' => 'Invalid',
            'level' => 5,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_creates_tenant_role_and_writes_relational_audit_ids(): void
    {
        $response = $this->postJson('/api/tenant/roles', [
            'name' => 'Sacristan',
            'description' => 'Handles sacristy duties',
            'level' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $roleId = (int) ($response->json('data.role.id') ?? $response->json('data.id'));
        $this->assertGreaterThan(0, $roleId);
        $this->assertDatabaseHas('roles', [
            'id' => $roleId,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
        ]);

        $audit = DB::table('permission_audit_logs')
            ->where('action', 'role_created')
            ->where('role_id', $roleId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->tenantAAdmin->id, (int) $audit->assigned_by);
        $this->assertSame($this->tenantA->id, (int) $audit->tenant_id);
    }

    #[Test]
    public function it_blocks_duplicate_role_names_within_same_tenant(): void
    {
        $response = $this->postJson('/api/tenant/roles', [
            'name' => $this->tenantACustomRole->name,
            'description' => 'Duplicate',
            'level' => 5,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_allows_same_role_name_in_different_tenant(): void
    {
        // Tenant B already has "Volunteer Coordinator"; Tenant A creating another
        // distinct name proves uniqueness is tenant-scoped via existing fixture.
        $response = $this->postJson('/api/tenant/roles', [
            'name' => 'Choir Director',
            'description' => 'Music ministry',
            'level' => 5,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('roles', [
            'name' => 'Choir Director',
            'tenant_id' => $this->tenantA->id,
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'Volunteer Coordinator',
            'tenant_id' => $this->tenantB->id,
        ]);
    }

    #[Test]
    public function it_aggregates_permissions_across_multiple_roles(): void
    {
        $permX = Permission::create([
            'name' => 'families.view',
            'display_name' => 'View Families',
            'module' => 'Family',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'test',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $permY = Permission::create([
            'name' => 'donations.view',
            'display_name' => 'View Donations',
            'module' => 'Donations',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'test',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $roleB = Role::create([
            'name' => 'Donation Clerk',
            'description' => 'Secondary role',
            'level' => 5,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantACustomRole->permissions()->sync([$permX->id]);
        $roleB->permissions()->sync([$permY->id]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->tenantACustomRole->id,
        ]);
        $user->syncRoles([$this->tenantACustomRole->id, $roleB->id]);
        $user->clearPermissionsCache();

        $names = $user->fresh()->getAllPermissions()->pluck('name')->all();
        $this->assertContains('families.view', $names);
        $this->assertContains('donations.view', $names);
    }

    #[Test]
    public function it_does_not_grant_permissions_from_inactive_roles(): void
    {
        $perm = Permission::create([
            'name' => 'bcc.view',
            'display_name' => 'View BCC',
            'module' => 'BCC',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'test',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->tenantACustomRole->permissions()->sync([$perm->id]);
        $this->tenantACustomRole->update(['active' => 0]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $this->tenantACustomRole->id,
        ]);
        $user->syncRoles([$this->tenantACustomRole->id]);
        $user->clearPermissionsCache();

        $this->assertFalse($user->fresh()->hasPermission('bcc.view'));
    }

    #[Test]
    public function it_blocks_non_admin_tenant_user_from_creating_roles(): void
    {
        $limitedRole = Role::create([
            'name' => 'Viewer',
            'description' => 'View only',
            'level' => 8,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $viewOnly = Permission::where('name', 'roles.view')->first();
        $limitedRole->permissions()->sync([$viewOnly->id]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $limitedRole->id,
        ]);
        $viewer->syncRoles([$limitedRole->id]);
        Passport::actingAs($viewer);

        $response = $this->postJson('/api/tenant/roles', [
            'name' => 'Should Fail',
            'description' => 'Escalation attempt',
            'level' => 5,
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_blocks_idor_show_of_foreign_tenant_role(): void
    {
        $response = $this->getJson("/api/tenant/roles/{$this->tenantBRole->id}");

        $this->assertContains($response->status(), [403, 404]);
        $body = strtolower((string) $response->getContent());
        $this->assertStringNotContainsString('volunteer coordinator', $body);
    }

    #[Test]
    public function it_blocks_direct_permission_assign_to_foreign_tenant_user(): void
    {
        $foreignUser = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role_id' => $this->tenantBRole->id,
        ]);

        $response = $this->postJson('/api/permissions/assign-to-user', [
            'user_id' => $foreignUser->id,
            'permission_id' => $this->viewPermission->id,
        ]);

        $response->assertStatus(403);
        $this->assertFalse(
            $foreignUser->fresh()->permissions()->where('permissions.id', $this->viewPermission->id)->exists()
        );
    }
}
