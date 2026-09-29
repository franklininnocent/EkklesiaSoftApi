<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\Authentication\Mail\PasswordRecoveryTemporaryPasswordMail;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordRecoveryService;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordRecoveryApiTest extends TestCase
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
    public function request_returns_generic_response_without_recovery_id(): void
    {
        $user = $this->createTenantUser('recoverable@test.local', 'Current*123');

        $existing = $this->postJson('/api/auth/password/recovery/request', [
            'email' => $user->email,
        ]);

        $missing = $this->postJson('/api/auth/password/recovery/request', [
            'email' => 'nobody@test.local',
        ]);

        $message = PasswordRecoveryService::GENERIC_REQUEST_MESSAGE;

        $existing->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', $message)
            ->assertJsonMissing(['recovery_id']);

        $missing->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', $message)
            ->assertJsonMissing(['recovery_id']);

        $this->assertSame(
            array_keys($existing->json()),
            array_keys($missing->json())
        );
    }

    #[Test]
    public function tenant_user_request_creates_pending_approval_for_primary_admin(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->createUserWithRole($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $this->assertDatabaseHas('password_recovery_requests', [
            'user_id' => $staff->id,
            'status' => PasswordRecoveryRequest::STATUS_PENDING_APPROVAL,
            'requester_classification' => PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER,
            'intended_approver_user_id' => $admin->id,
        ]);
    }

    #[Test]
    public function primary_admin_can_approve_tenant_user_recovery_and_force_password_change(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->createUserWithRole($tenant->id, $staffRole, [
            'email' => 'staff@test.local',
            'password' => Hash::make('Current*123'),
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(PasswordRecoveryTemporaryPasswordMail::class);

        $staff->refresh();
        $request->refresh();

        $this->assertTrue((bool) $staff->force_password_change);
        $this->assertSame(PasswordRecoveryRequest::STATUS_COMPLETED, $request->status);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/approve")
            ->assertForbidden();
    }

    #[Test]
    public function primary_admin_without_process_permission_cannot_approve(): void
    {
        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $view = Permission::query()->where('name', 'password.recovery.requests.view')->firstOrFail();
        $adminRole->givePermissionTo($view);

        $admin = $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => 'admin-view-only@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->createUserWithRole($tenant->id, $staffRole, [
            'email' => 'staff-view-only@test.local',
        ]);

        $request = $this->makeRecoveryRequest($staff, $tenant->id);

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame(PasswordRecoveryRequest::STATUS_PENDING_APPROVAL, $request->fresh()->status);
    }

    #[Test]
    public function approver_cannot_approve_own_or_other_tenant_or_expired_request(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenantA->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRoleA = $this->makeTenantRole($tenantA->id, 'Secretary', 3);
        $staffRoleB = $this->makeTenantRole($tenantB->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->createUserWithRole($tenantA->id, $adminRole, [
            'email' => 'admin-a@test.local',
            'is_primary_admin' => true,
        ]);
        $staffA = $this->createUserWithRole($tenantA->id, $staffRoleA, [
            'email' => 'staff-a@test.local',
        ]);
        $staffB = $this->createUserWithRole($tenantB->id, $staffRoleB, [
            'email' => 'staff-b@test.local',
        ]);

        $own = $this->makeRecoveryRequest($admin, $tenantA->id);
        $foreign = $this->makeRecoveryRequest($staffB, $tenantB->id);
        $expired = $this->makeRecoveryRequest($staffA, $tenantA->id, now()->subHour());

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$own->id}/approve")->assertForbidden();
        $this->postJson("/api/auth/password-recovery-requests/{$foreign->id}/approve")->assertForbidden();
        $this->postJson("/api/auth/password-recovery-requests/{$expired->id}/approve")->assertForbidden();

        $this->assertSame(PasswordRecoveryRequest::STATUS_PENDING_APPROVAL, $own->fresh()->status);
        $this->assertSame(PasswordRecoveryRequest::STATUS_PENDING_APPROVAL, $foreign->fresh()->status);
        $this->assertSame(PasswordRecoveryRequest::STATUS_PENDING_APPROVAL, $expired->fresh()->status);
    }

    #[Test]
    public function login_response_includes_force_password_change_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $role = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $user = $this->createUserWithRole($tenant->id, $role, [
            'email' => 'forced@test.local',
            'password' => Hash::make('Current*123'),
            'force_password_change' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Current*123',
        ])
            ->assertOk()
            ->assertJsonPath('force_password_change', true);
    }

    #[Test]
    public function recovery_audit_events_have_null_actor_for_public_request(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => 'admin@test.local',
            'is_primary_admin' => true,
        ]);
        $staff = $this->createUserWithRole($tenant->id, $staffRole, [
            'email' => 'audit@test.local',
            'password' => Hash::make('Current*123'),
        ]);

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'user.password_recovery_requested',
            'actor_user_id' => null,
        ]);
    }

    private function makeRecoveryRequest(User $user, int $tenantId, $expiresAt = null): PasswordRecoveryRequest
    {
        return PasswordRecoveryRequest::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'requester_email' => $user->email,
            'requester_domain' => PasswordRecoveryRequest::DOMAIN_TENANT,
            'requester_classification' => PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER,
            'status' => PasswordRecoveryRequest::STATUS_PENDING_APPROVAL,
            'expires_at' => $expiresAt ?? now()->addDay(),
        ]);
    }

    private function seedPasswordGrantClient(): void
    {
        if (DB::table('oauth_clients')->exists()) {
            return;
        }

        DB::table('oauth_clients')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Password Grant Client',
            'secret' => Str::random(40),
            'provider' => 'users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password', 'refresh_token']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenantUser(string $email, string $password): User
    {
        $tenant = Tenant::factory()->create();
        $role = $this->makeTenantRole($tenant->id, 'Secretary', 3);

        return $this->createUserWithRole($tenant->id, $role, [
            'email' => $email,
            'password' => Hash::make($password),
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
    private function createUserWithRole(int $tenantId, Role $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
            'active' => 1,
        ], $overrides));
        $user->syncRoles([$role->id]);

        return $user->fresh(['role', 'roles']);
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
