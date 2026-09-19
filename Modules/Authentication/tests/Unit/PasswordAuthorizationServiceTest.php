<?php

namespace Modules\Authentication\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordAuthorizationService;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

class PasswordAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PasswordAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PasswordAuthorizationService::class);
    }

    public function test_super_admin_can_reset_lower_platform_user_but_not_super_admin_target(): void
    {
        $superRole = $this->makePlatformRole(Role::SUPER_ADMIN, Role::LEVEL_SUPER_ADMIN);
        $adminRole = $this->makePlatformRole(Role::EKKLESIA_ADMIN, Role::LEVEL_EKKLESIA_ADMIN);

        $super = $this->makeUser(null, $superRole);
        $target = $this->makeUser(null, $adminRole);

        $this->assertTrue($this->service->canResetPassword($super, $target));
        $this->assertFalse($this->service->canResetPassword($super, $super));
        $this->assertFalse($this->service->canResetPassword($adminRoleUser = $this->makeUser(null, $adminRole), $super));
    }

    public function test_tenant_admin_can_reset_lower_tenant_user_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);

        $admin = $this->makeUser($tenant->id, $adminRole);
        $staff = $this->makeUser($tenant->id, $staffRole);

        $this->seedPermission(PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES, Permission::SCOPE_BOTH);
        $adminRole->givePermissionTo(Permission::whereName(PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES)->first());

        $this->assertTrue($this->service->canResetPassword($admin, $staff));
    }

    public function test_tenant_admin_cannot_reset_peer_or_other_tenant_user(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminRoleA = $this->makeTenantRole($tenantA->id, Role::TENANT_ADMINISTRATOR, 1);
        $adminRoleB = $this->makeTenantRole($tenantB->id, Role::TENANT_ADMINISTRATOR, 1);

        $adminA = $this->makeUser($tenantA->id, $adminRoleA);
        $adminB = $this->makeUser($tenantB->id, $adminRoleB);

        $this->seedPermission(PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES, Permission::SCOPE_BOTH);
        $perm = Permission::query()->where('name', PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES)->first();
        $adminRoleA->givePermissionTo($perm);

        $this->assertFalse($this->service->canResetPassword($adminA, $adminB));
    }

    public function test_self_change_is_allowed_for_active_user(): void
    {
        $role = $this->makePlatformRole(Role::EKKLESIA_USER, Role::LEVEL_EKKLESIA_USER);
        $user = $this->makeUser(null, $role);

        $this->assertTrue($this->service->canChangeOwnPassword($user));
    }

    private function makePlatformRole(string $name, int $level): Role
    {
        return Role::create([
            'name' => $name,
            'description' => $name,
            'level' => $level,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);
    }

    private function makeTenantRole(int $tenantId, string $name, int $level): Role
    {
        return Role::create([
            'name' => $name,
            'description' => $name,
            'level' => $level,
            'active' => 1,
            'tenant_id' => $tenantId,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_DEFAULT_TEMPLATE,
        ]);
    }

    private function makeUser(?int $tenantId, Role $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh(['role', 'roles']);
    }

    private function seedPermission(string $name, string $scope): void
    {
        Permission::updateOrCreate(['name' => $name], [
            'display_name' => $name,
            'description' => $name,
            'module' => 'Password & Account Security',
            'category' => 'Manage',
            'scope' => $scope,
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
    }
}
