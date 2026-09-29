<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Modules\Authentication\Mail\PasswordRecoveryRequestNotificationMail;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Notifications\Models\NotificationEvent;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Services\NotificationPublisher;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordRecoveryInAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPasswordGrantClient();
        $this->seed(NotificationDefinitionsSeeder::class);

        config([
            'authentication.recovery.mail_enabled' => true,
            'authentication.recovery.daily_initiations' => 3,
            'authentication.recovery.request_ttl_hours' => 24,
            'notifications.mail_owner' => 'legacy',
        ]);
    }

    #[Test]
    public function tenant_user_request_creates_in_app_notification_for_primary_admin_only(): void
    {
        Mail::fake();

        [$tenant, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();

        Mail::assertQueued(PasswordRecoveryRequestNotificationMail::class);

        $notification = UserNotification::query()
            ->where('user_id', $admin->id)
            ->where('status', 'unread')
            ->whereHas('event', function ($query) use ($request) {
                $query->where('subject_type', 'password_recovery_request')
                    ->where('subject_id', $request->id);
            })
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('required', $notification->action_status);
        $this->assertSame(InboxScope::Tenant->value, $notification->inbox_scope);
        $this->assertSame((int) $tenant->id, (int) $notification->tenant_id);

        $event = $notification->event;
        $this->assertSame('auth.password_recovery.requested', $event->definition->code);
        $this->assertSame('New Forgot Password Request', $event->title);
        $this->assertStringContainsString((string) $staff->email, (string) $event->body);
        $this->assertArrayNotHasKey('password', $event->data ?? []);
        $this->assertArrayNotHasKey('token', $event->data ?? []);
        $this->assertArrayNotHasKey('otp', $event->data ?? []);

        $this->assertSame(
            0,
            UserNotification::query()->where('user_id', $staff->id)->count()
        );
    }

    #[Test]
    public function duplicate_publish_for_same_request_does_not_create_second_event(): void
    {
        Mail::fake();

        [, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();

        app(NotificationPublisher::class)->publish(new NotificationIntent(
            definitionCode: 'auth.password_recovery.requested',
            actor: $staff,
            subjectType: 'password_recovery_request',
            subjectId: (string) $request->id,
            tenantId: (int) $request->tenant_id,
            scope: InboxScope::Tenant,
            occurrenceId: (string) $request->id,
            data: [
                'requester_name' => (string) $staff->name,
                'requester_email' => (string) $staff->email,
                'requester_role' => 'Church User',
                'tenant_name' => 'Test',
            ],
            explicitRecipientIds: [(int) $admin->id],
            actionStatus: 'required',
        ));

        $this->assertSame(
            1,
            NotificationEvent::query()
                ->where('subject_type', 'password_recovery_request')
                ->where('subject_id', $request->id)
                ->count()
        );
        $this->assertSame(
            1,
            UserNotification::query()->where('user_id', $admin->id)->count()
        );
    }

    #[Test]
    public function other_tenant_admin_cannot_access_notification_or_recovery_request(): void
    {
        Mail::fake();

        [, $adminA, $staffA] = $this->createTenantAdminAndStaff('a');
        [$tenantB, $adminB] = $this->createTenantAdminOnly('b');

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staffA->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staffA->id)->firstOrFail();
        $notification = UserNotification::query()->where('user_id', $adminA->id)->firstOrFail();

        Passport::actingAs($adminB, ['*']);

        $this->getJson('/api/tenant/notifications/'.$notification->id)
            ->assertNotFound();

        $this->getJson('/api/auth/password-recovery-requests/'.$request->id)
            ->assertNotFound();

        $this->assertSame((int) $tenantB->id, (int) $adminB->tenant_id);
    }

    #[Test]
    public function non_admin_tenant_user_cannot_list_recovery_notification(): void
    {
        Mail::fake();

        [, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $notification = UserNotification::query()->where('user_id', $admin->id)->firstOrFail();

        Passport::actingAs($staff, ['*']);

        $this->getJson('/api/tenant/notifications/'.$notification->id)
            ->assertNotFound();

        $this->getJson('/api/tenant/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function approving_request_completes_notification_action_and_returns_live_status(): void
    {
        Mail::fake();

        [, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();
        $notification = UserNotification::query()->where('user_id', $admin->id)->firstOrFail();

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/approve")
            ->assertOk();

        $notification->refresh();
        $this->assertSame('completed', $notification->action_status);

        $this->getJson("/api/auth/password-recovery-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.status', PasswordRecoveryRequest::STATUS_COMPLETED)
            ->assertJsonMissing(['password', 'token', 'otp', 'temp_password']);
    }

    #[Test]
    public function rejecting_request_completes_notification_action(): void
    {
        Mail::fake();

        [, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();
        $notification = UserNotification::query()->where('user_id', $admin->id)->firstOrFail();

        Passport::actingAs($admin, ['*']);

        $this->postJson("/api/auth/password-recovery-requests/{$request->id}/reject", [
            'reason' => 'Not recognized',
        ])->assertOk();

        $notification->refresh();
        $this->assertSame('completed', $notification->action_status);
    }

    #[Test]
    public function open_deep_link_returns_request_query_for_authorized_admin(): void
    {
        Mail::fake();

        [, $admin, $staff] = $this->createTenantAdminAndStaff();

        $this->postJson('/api/auth/password/recovery/request', [
            'email' => $staff->email,
        ])->assertOk();

        $request = PasswordRecoveryRequest::query()->where('user_id', $staff->id)->firstOrFail();
        $notification = UserNotification::query()->where('user_id', $admin->id)->firstOrFail();

        Passport::actingAs($admin, ['*']);

        $this->getJson('/api/tenant/notifications/'.$notification->id.'/open')
            ->assertOk()
            ->assertJsonPath('data.route', '/settings/forgot-password-requests')
            ->assertJsonPath('data.params.request', $request->id);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: User}
     */
    private function createTenantAdminAndStaff(string $suffix = 't'): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Parish '.$suffix]);
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $staffRole = $this->makeTenantRole($tenant->id, 'Secretary', 3);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => "admin-{$suffix}@test.local",
            'is_primary_admin' => true,
            'name' => 'Admin '.$suffix,
        ]);
        $staff = $this->createUserWithRole($tenant->id, $staffRole, [
            'email' => "staff-{$suffix}@test.local",
            'name' => 'Staff '.$suffix,
            'password' => Hash::make('Current*123'),
        ]);

        return [$tenant, $admin, $staff];
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenantAdminOnly(string $suffix): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Parish '.$suffix]);
        $adminRole = $this->makeTenantRole($tenant->id, Role::TENANT_ADMINISTRATOR, 1);
        $this->seedRecoveryPermissions();
        $adminRole->givePermissionTo($this->recoveryPermissions());

        $admin = $this->createUserWithRole($tenant->id, $adminRole, [
            'email' => "admin-{$suffix}@test.local",
            'is_primary_admin' => true,
        ]);

        return [$tenant, $admin];
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
