<?php

namespace Modules\SupportAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportAccess\Models\SupportSession;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportSessionTenantAccessTest extends TestCase
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
    public function platform_user_without_support_session_is_denied_tenant_ministries_api(): void
    {
        [$user, $tenant] = $this->seedSupportOperator();

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/ministries/categories');

        $response->assertStatus(403);
    }

    #[Test]
    public function active_readonly_support_session_allows_ministries_categories_get(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        OrganizationCategory::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Worship',
            'code' => 'worship',
            'display_order' => 1,
            'is_active' => true,
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/ministries/categories');

        $response->assertOk()
            ->assertJsonPath('success', true);
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
            ->getJson('/api/tenant/ministries/categories');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Support session is no longer active.');
    }

    #[Test]
    public function readonly_support_session_blocks_category_create(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->postJson('/api/tenant/ministries/categories', [
                'name' => 'Blocked',
                'code' => 'blocked',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Support session is read-only.');
    }

    #[Test]
    public function support_event_creation_works_for_active_owned_session(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->postJson("/api/support/sessions/{$session->id}/events", [
                'event_type' => 'page_view',
                'module' => 'ministries',
                'page' => '/ministries',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function active_readonly_support_session_allows_donations_dashboard_summary_get(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession([
            'features' => ['donations'],
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/donations/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function active_readonly_support_session_allows_donations_command_center_get(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession([
            'features' => ['donations'],
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/donations/dashboard/command-center?period=month');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function active_readonly_support_session_allows_donations_expenses_get(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession([
            'features' => ['donations'],
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/tenant/donations/expenses');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function active_readonly_support_session_allows_sacraments_dashboard_summary_get(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->getJson('/api/sacraments/dashboard/summary?include_marriage_gaps=0');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function readonly_support_session_blocks_parish_ticket_create(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->postJson('/api/tenant/support/tickets', [
                'subject' => 'Blocked',
                'description' => 'Should not create as support session',
            ]);

        $response->assertStatus(403);
        $this->assertContains($response->json('message'), [
            'Support sessions cannot modify parish tickets. Use Support Center.',
            'Support session is read-only.',
        ]);
    }

    #[Test]
    public function support_event_rejects_mismatched_session_header(): void
    {
        [$user, $tenant, $session] = $this->seedActiveReadonlySession();
        $other = SupportSession::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'support_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        Passport::actingAs($user);

        $response = $this->withHeader('X-Support-Session-Id', $session->id)
            ->postJson("/api/support/sessions/{$other->id}/events", [
                'event_type' => 'page_view',
            ]);

        $response->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant}
     */
    private function seedSupportOperator(array $tenantOverrides = []): array
    {
        $tenant = Tenant::factory()->active()->create(array_merge([
            'features' => ['ministries_associations'],
        ], $tenantOverrides));

        $role = $this->supportRole();
        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'password' => Hash::make('secret'),
        ]);
        $user->syncRoles([$role->id]);

        return [$user->fresh(['role']), $tenant];
    }

    /**
     * @return array{0: User, 1: Tenant, 2: SupportSession}
     */
    /**
     * @param  array<string, mixed>  $tenantOverrides
     * @return array{0: User, 1: Tenant, 2: SupportSession}
     */
    private function seedActiveReadonlySession(array $tenantOverrides = []): array
    {
        [$user, $tenant] = $this->seedSupportOperator($tenantOverrides);

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

    private function supportRole(): Role
    {
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

        return $role;
    }
}
