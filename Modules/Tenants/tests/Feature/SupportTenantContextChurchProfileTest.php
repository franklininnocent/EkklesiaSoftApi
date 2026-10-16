<?php

namespace Modules\Tenants\Tests\Feature;

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

class SupportTenantContextChurchProfileTest extends TestCase
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
    public function platform_operator_without_support_session_gets_shell_church_profile_404(): void
    {
        [$user] = $this->seedSupportOperator();

        Passport::actingAs($user);

        $this->getJson('/api/tenant/church-profile')
            ->assertStatus(404)
            ->assertJsonPath('message', 'User is not associated with a tenant/church');
    }

    #[Test]
    public function platform_operator_with_support_session_gets_shell_church_profile(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/church-profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $tenant->id);
    }

    #[Test]
    public function platform_operator_with_support_session_gets_extended_church_profile(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/church-profile')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function platform_operator_with_support_session_gets_church_leadership(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/church-leadership')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function platform_operator_with_support_session_gets_church_statistics(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/church-statistics?limit=12')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function platform_operator_without_support_session_gets_church_leadership_404(): void
    {
        [$user] = $this->seedSupportOperator();

        Passport::actingAs($user);

        $this->getJson('/api/church-leadership')
            ->assertStatus(404)
            ->assertJsonPath('message', 'User is not associated with a tenant/church');
    }

    #[Test]
    public function tenant_administrator_still_gets_shell_church_profile(): void
    {
        $tenant = Tenant::factory()->active()->create();
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

        Passport::actingAs($admin);

        $this->getJson('/api/tenant/church-profile')
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id);
    }

    /**
     * @return array{0: User}
     */
    private function seedSupportOperator(): array
    {
        $role = Role::create([
            'name' => Role::SUPPORT_ADMIN,
            'description' => 'Support operator',
            'level' => Role::LEVEL_SUPPORT_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_SUPPORT,
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

        return [$user->fresh(['role'])];
    }

    /**
     * @return array{0: User, 1: Tenant, 2: SupportSession}
     */
    private function seedActiveReadonlySession(): array
    {
        [$user] = $this->seedSupportOperator();
        $tenant = Tenant::factory()->active()->create();

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

        return [$user, $tenant, $session];
    }
}
