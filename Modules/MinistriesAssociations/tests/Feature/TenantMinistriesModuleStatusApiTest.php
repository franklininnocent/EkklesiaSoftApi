<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportAccess\Models\SupportSession;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantMinistriesModuleStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (app()->bound(TenantContext::class)) {
            app()->forgetInstance(TenantContext::class);
        }

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();
    }

    protected function tearDown(): void
    {
        if (app()->bound(TenantContext::class)) {
            app()->forgetInstance(TenantContext::class);
        }

        User::flushRequestPermissionCache();

        parent::tearDown();
    }

    #[Test]
    public function tenant_administrator_receives_module_status_for_home_tenant(): void
    {
        [$admin, $tenant] = $this->seedTenantAdmin([
            'features' => ['ministries_associations'],
        ]);

        Passport::actingAs($admin);

        $response = $this->getJson('/api/tenant/ministries/module-status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.feature_key', 'ministries_associations');
    }

    #[Test]
    public function tenant_user_with_ministries_view_receives_module_status(): void
    {
        [$user, $tenant] = $this->seedTenantUserWithPermission('ministries.view', [
            'features' => ['ministries_associations'],
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/ministries/module-status');

        $response->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    #[Test]
    public function tenant_user_without_ministries_view_is_denied(): void
    {
        [$user] = $this->seedTenantUserWithPermission('families.view', [
            'features' => ['ministries_associations'],
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/ministries/module-status');

        $response->assertStatus(403);
    }

    #[Test]
    public function platform_ekklesia_admin_without_tenant_context_is_denied(): void
    {
        $user = $this->seedEkklesiaAdmin();

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/ministries/module-status');

        $response->assertStatus(403);
        $this->assertSame('Tenant context required.', $response->json('message'));
    }

    #[Test]
    public function platform_user_with_active_support_session_receives_session_tenant_status(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession([
            'features' => ['ministries_associations'],
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/ministries/module-status');

        $response->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.feature_key', 'ministries_associations');
    }

    #[Test]
    public function support_session_returns_disabled_when_feature_not_enabled_on_session_tenant(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession([
            'features' => [],
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/ministries/module-status');

        $response->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function tenant_without_ministries_feature_returns_enabled_false_not_feature_middleware_block(): void
    {
        [$admin] = $this->seedTenantAdmin([
            'features' => [],
        ]);

        Passport::actingAs($admin);

        $response = $this->getJson('/api/tenant/ministries/module-status');

        $response->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function expired_support_session_header_is_rejected(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();
        $session->update([
            'status' => SupportSession::STATUS_EXPIRED,
            'ended_at' => now(),
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/ministries/module-status');

        $response->assertStatus(403);
    }

    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenantAdmin(array $tenantOverrides = []): array
    {
        $tenant = Tenant::factory()->active()->create(array_merge([
            'features' => ['ministries_associations'],
        ], $tenantOverrides));

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $admin->syncRoles([$role->id]);

        return [$admin->fresh(['role']), $tenant];
    }

    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant}
     */
    private function seedTenantUserWithPermission(string $permissionName, array $tenantOverrides = []): array
    {
        $tenant = Tenant::factory()->active()->create(array_merge([
            'features' => ['ministries_associations'],
        ], $tenantOverrides));

        $role = Role::create([
            'name' => 'MinistryViewer',
            'description' => 'Ministry viewer',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permission = Permission::create([
            'name' => $permissionName,
            'display_name' => $permissionName,
            'description' => $permissionName,
            'module' => 'MinistriesAssociations',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'ministries',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        return [$user->fresh(['role']), $tenant];
    }

    private function seedEkklesiaAdmin(): User
    {
        $role = Role::create([
            'name' => Role::EKKLESIA_ADMIN,
            'description' => 'Ekklesia admin',
            'level' => Role::LEVEL_EKKLESIA_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'password' => Hash::make('secret'),
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh(['role']);
    }

    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant, 2: SupportSession}
     */
    private function seedActiveReadonlySession(array $tenantOverrides = []): array
    {
        $tenant = Tenant::factory()->active()->create(array_merge([
            'features' => ['ministries_associations'],
        ], $tenantOverrides));

        $role = Role::create([
            'name' => 'SupportAdmin',
            'description' => 'Support operator',
            'level' => Role::LEVEL_EKKLESIA_MANAGER,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $names = [
            'support.sessions.start',
            'support.sessions.readonly',
            'support.sessions.view',
        ];

        $permissionIds = [];
        foreach ($names as $name) {
            $permissionIds[] = Permission::create([
                'name' => $name,
                'display_name' => $name,
                'description' => $name,
                'module' => 'SupportAccess',
                'scope' => Permission::SCOPE_PLATFORM,
                'category' => 'support',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ])->id;
        }
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'password' => Hash::make('secret'),
        ]);
        $user->syncRoles([$role->id]);

        $session = SupportSession::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'support_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        return [$user->fresh(['role']), $tenant, $session];
    }
}
