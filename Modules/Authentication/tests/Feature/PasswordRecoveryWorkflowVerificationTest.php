<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Extended workflow verification for administrator-approved password recovery.
 */
class PasswordRecoveryWorkflowVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPasswordGrantClient();

        config([
            'authentication.recovery.mail_enabled' => true,
            'authentication.recovery.daily_initiations' => 3,
            'authentication.recovery.request_ttl_hours' => 24,
        ]);
    }

    #[Test]
    public function super_admin_does_not_see_tenant_user_requests(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $this->makeTenantUser($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->makeTenantUser($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();
        $superAdmin = $this->makeSuperAdmin();

        Passport::actingAs($superAdmin, ['*']);

        $this->getJson('/api/auth/password-recovery-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/auth/password-recovery-requests/{$request->id}")
            ->assertNotFound();
    }

    #[Test]
    public function primary_admin_can_reject_pending_request(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->makeTenantUser($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->makeTenantUser($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/reject", [
            'reason' => 'Not recognized',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', PasswordRecoveryRequest::STATUS_REJECTED);
    }

    #[Test]
    public function duplicate_inflight_request_returns_generic_response(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $this->makeTenantUser($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->makeTenantUser($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $this->assertSame(
            1,
            PasswordRecoveryRequest::query()->where('user_id', $staff->id)->count()
        );
    }

    #[Test]
    public function requester_cannot_approve_their_own_request(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $this->makeTenantUser($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->makeTenantUser($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();

        Passport::actingAs($staff, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/approve")
            ->assertForbidden();
    }

    private function seedPasswordGrantClient(): void
    {
        if (\Illuminate\Support\Facades\DB::table('oauth_clients')->exists()) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('oauth_clients')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Password Grant Client',
            'secret' => \Illuminate\Support\Str::random(40),
            'provider' => 'users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password', 'refresh_token']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenantUser(int $tenantId, Role $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
            'active' => 1,
        ], $overrides));
        $user->syncRoles([$role->id]);

        return $user->fresh(['role', 'roles']);
    }

    private function makeSuperAdminUser(Role $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh(['role', 'roles']);
    }

    private function makeSuperAdmin(): User
    {
        $role = Role::query()->where('name', Role::SUPER_ADMIN)->first()
            ?? $this->makePlatformRole(Role::SUPER_ADMIN, Role::LEVEL_SUPER_ADMIN);

        return $this->makeSuperAdminUser($role);
    }

    private function seedRecoveryPermissions(): void
    {
        foreach ($this->recoveryPermissions() as $permission) {
            Permission::updateOrCreate(['name' => $permission->name], [
                'display_name' => $permission->name,
                'description' => $permission->name,
                'module' => 'Password & Account Security',
                'category' => 'Manage',
                'scope' => Permission::SCOPE_BOTH,
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]);
        }
    }

    /**
     * @return array<int, Permission>
     */
    private function recoveryPermissions(): array
    {
        return [
            Permission::query()->firstOrCreate(['name' => 'password.recovery.requests.view'], [
                'display_name' => 'View Password Recovery Requests',
                'description' => 'View',
                'module' => 'Password & Account Security',
                'category' => 'Manage',
                'scope' => Permission::SCOPE_BOTH,
                'active' => 1,
            ]),
            Permission::query()->firstOrCreate(['name' => 'password.recovery.requests.process'], [
                'display_name' => 'Process Password Recovery Requests',
                'description' => 'Process',
                'module' => 'Password & Account Security',
                'category' => 'Manage',
                'scope' => Permission::SCOPE_BOTH,
                'active' => 1,
            ]),
        ];
    }
}
