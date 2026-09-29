<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

class NotificationPreferenceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationDefinitionsSeeder::class);
    }

    public function test_tenant_admin_with_donation_approval_sees_refund_rows_only(): void
    {
        $user = $this->tenantAdminWith(['donations.approvals']);

        $codes = $this->preferenceCodes($user, '/api/tenant/notifications/preferences');

        $this->assertContains('donations.refund.requested', $codes);
        $this->assertContains('donations.refund.decided', $codes);
        $this->assertNotContains('application.security.threat', $codes);
        $this->assertNotContains('support.emergency_approval.pending', $codes);
    }

    public function test_tenant_admin_without_donation_approval_cannot_save_refund_preference(): void
    {
        $user = $this->tenantAdminWith([]);

        $codes = $this->preferenceCodes($user, '/api/tenant/notifications/preferences');

        $this->assertNotContains('donations.refund.requested', $codes);
        $this->assertNotContains('donations.refund.decided', $codes);
        $this->assertNotContains('application.security.threat', $codes);

        $this->actingAs($user, 'api')
            ->putJson('/api/tenant/notifications/preferences', [
                'preferences' => [[
                    'definition_code' => 'donations.refund.requested',
                    'in_app' => false,
                    'email' => false,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $user->id,
            'definition_code' => 'donations.refund.requested',
        ]);
    }

    public function test_ekklesia_admin_sees_platform_rows_they_can_receive(): void
    {
        $user = $this->ekklesiaAdminWith(['support.sessions.approve']);

        $codes = $this->preferenceCodes($user, '/api/admin/notifications/preferences');

        $this->assertContains('support.emergency_approval.pending', $codes);
        $this->assertNotContains('donations.refund.requested', $codes);
        $this->assertNotContains('donations.refund.decided', $codes);
        $this->assertNotContains('tenants.subscription.lifecycle', $codes);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function tenantAdminWith(array $permissionNames): User
    {
        $tenant = Tenant::factory()->create(['active' => 1]);
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Parish administrator',
            'level' => 5,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
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

    /**
     * @param  list<string>  $permissionNames
     */
    private function ekklesiaAdminWith(array $permissionNames): User
    {
        $role = Role::create([
            'name' => Role::EKKLESIA_ADMIN,
            'description' => 'Ekklesia administrator',
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

    /**
     * @return list<string>
     */
    private function preferenceCodes(User $user, string $url): array
    {
        $response = $this->actingAs($user, 'api')->getJson($url);

        $response->assertOk()->assertJsonPath('success', true);

        return collect($response->json('data'))
            ->pluck('definition_code')
            ->all();
    }
}
