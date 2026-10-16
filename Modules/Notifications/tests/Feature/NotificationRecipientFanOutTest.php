<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Services\NotificationPublisher;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionLifecycleNotificationPublisher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationRecipientFanOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationDefinitionsSeeder::class);
    }

    #[Test]
    public function tenant_user_with_role_granted_approval_permission_receives_refund_notification(): void
    {
        $tenant = $this->writableTenant();
        $approver = $this->tenantUserWithRolePermissions($tenant, ['donations.approvals']);
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        app(NotificationPublisher::class)->publish(new NotificationIntent(
            definitionCode: 'donations.refund.requested',
            actor: $actor,
            subjectType: 'donation_approval',
            subjectId: 'approval-1',
            tenantId: (int) $tenant->id,
            scope: InboxScope::Tenant,
            occurrenceId: 'approval-1',
            data: [
                'amount' => '100.00',
                'family_name' => 'Smith Family',
            ],
            actionStatus: 'required',
        ));

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $approver->id,
            'inbox_scope' => InboxScope::Tenant->value,
            'tenant_id' => $tenant->id,
            'status' => 'unread',
        ]);

        $this->actingAs($approver, 'api')
            ->getJson('/api/tenant/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function tenant_user_without_approval_permission_does_not_receive_refund_notification(): void
    {
        $tenant = $this->writableTenant();
        $staff = $this->tenantUserWithRolePermissions($tenant, ['donations.view']);
        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        app(NotificationPublisher::class)->publish(new NotificationIntent(
            definitionCode: 'donations.refund.requested',
            actor: $actor,
            subjectType: 'donation_approval',
            subjectId: 'approval-2',
            tenantId: (int) $tenant->id,
            scope: InboxScope::Tenant,
            occurrenceId: 'approval-2',
            data: [
                'amount' => '50.00',
                'family_name' => 'Jones Family',
            ],
            actionStatus: 'required',
        ));

        $this->assertSame(
            0,
            UserNotification::query()->where('user_id', $staff->id)->count()
        );

        $this->actingAs($staff, 'api')
            ->getJson('/api/tenant/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function platform_ekklesia_admin_with_role_granted_permission_receives_emergency_notification(): void
    {
        $approver = $this->platformUserWithRolePermissions(Role::EKKLESIA_ADMIN, ['support.sessions.approve']);
        $requester = User::factory()->create(['tenant_id' => null, 'active' => 1]);

        app(NotificationPublisher::class)->publish(new NotificationIntent(
            definitionCode: 'support.emergency_approval.pending',
            actor: $requester,
            subjectType: 'support_access_request',
            subjectId: 'request-1',
            tenantId: null,
            scope: InboxScope::Platform,
            occurrenceId: 'request-1',
            data: [
                'tenant_name' => 'St. Mary Parish',
            ],
            actionStatus: 'required',
        ));

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $approver->id,
            'inbox_scope' => InboxScope::Platform->value,
            'tenant_id' => null,
            'status' => 'unread',
        ]);

        $this->actingAs($approver, 'api')
            ->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function super_admin_lists_platform_inbox_with_published_notification(): void
    {
        $superAdmin = $this->superAdminUser();
        $requester = User::factory()->create(['tenant_id' => null, 'active' => 1]);

        app(NotificationPublisher::class)->publish(new NotificationIntent(
            definitionCode: 'support.emergency_approval.pending',
            actor: $requester,
            subjectType: 'support_access_request',
            subjectId: 'request-super',
            tenantId: null,
            scope: InboxScope::Platform,
            occurrenceId: 'request-super',
            data: [
                'tenant_name' => 'St. Paul Parish',
            ],
            actionStatus: 'required',
        ));

        $this->actingAs($superAdmin, 'api')
            ->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function tenant_administrator_receives_subscription_lifecycle_notification(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => true]);
        Mail::fake();

        $tenant = $this->writableTenant();
        $admin = $this->tenantAdministrator($tenant);
        $admin->update(['email' => 'parish-admin@example.test']);

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $admin->id,
            'inbox_scope' => InboxScope::Tenant->value,
            'tenant_id' => $tenant->id,
            'status' => 'unread',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/tenant/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function tenant_user_cannot_access_platform_inbox(): void
    {
        $tenant = $this->writableTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        $this->actingAs($user, 'api')
            ->getJson('/api/admin/notifications')
            ->assertNotFound();
    }

    private function writableTenant(): Tenant
    {
        return Tenant::factory()->create([
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function tenantUserWithRolePermissions(Tenant $tenant, array $permissionNames): User
    {
        $role = Role::create([
            'name' => 'Treasurer',
            'description' => 'Treasurer',
            'level' => 10,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->grant($role, $permissionNames, Permission::SCOPE_TENANT);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'is_primary_admin' => false,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh();
    }

    private function tenantAdministrator(Tenant $tenant): User
    {
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Parish administrator',
            'level' => 5,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'is_primary_admin' => false,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function platformUserWithRolePermissions(string $roleName, array $permissionNames): User
    {
        $role = Role::create([
            'name' => $roleName,
            'description' => $roleName,
            'level' => Role::LEVEL_EKKLESIA_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $this->grant($role, $permissionNames, Permission::SCOPE_PLATFORM);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'is_primary_admin' => false,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh();
    }

    private function superAdminUser(): User
    {
        $role = Role::create([
            'name' => Role::SUPER_ADMIN,
            'description' => 'Super administrator',
            'level' => Role::LEVEL_SUPER_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'is_primary_admin' => false,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(Role $role, array $permissionNames, string $scope): void
    {
        $ids = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => 'Notifications',
                    'category' => 'notifications',
                    'scope' => $scope,
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }

        if ($ids !== []) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }
}
