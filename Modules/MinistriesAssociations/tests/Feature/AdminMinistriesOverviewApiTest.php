<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Database\Seeders\AdminMinistriesPlatformPermissionSeeder;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMinistriesOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/ministries/overview')
            ->assertUnauthorized();
    }

    #[Test]
    public function tenant_user_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        Passport::actingAs($user);

        $this->getJson('/api/admin/ministries/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function ekklesia_admin_without_permission_is_forbidden(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => 2,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        Permission::updateOrCreate(
            ['name' => 'ministries.platform.overview'],
            [
                'display_name' => 'Ministries Insights overview',
                'description' => 'test',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
                'tenant_id' => null,
                'is_custom' => 0,
                'scope' => Permission::SCOPE_PLATFORM,
                'active' => 1,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        Passport::actingAs($user);

        $this->getJson('/api/admin/ministries/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function overview_returns_real_kpis_and_funnel(): void
    {
        $actor = $this->authenticateAsSuperAdmin();

        $disabled = Tenant::factory()->create([
            'name' => 'Disabled Parish',
            'features' => ['donations'],
            'active' => 1,
        ]);
        $notStarted = Tenant::factory()->create([
            'name' => 'Not Started Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $activatedInactive = Tenant::factory()->create([
            'name' => 'Inactive Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $activeTenant = Tenant::factory()->create([
            'name' => 'Active Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);

        $this->createOrganization($activatedInactive->id, 'inactive-org', 'Inactive Org');
        $activeOrg = $this->createOrganization($activeTenant->id, 'active-org', 'Active Org');

        MinistriesAuditLog::create([
            'tenant_id' => $activeTenant->id,
            'actor_user_id' => $actor->id,
            'event' => 'organization.updated',
            'target_type' => 'organization',
            'target_id' => $activeOrg->id,
            'organization_id' => $activeOrg->id,
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);
        MinistriesAuditLog::create([
            'tenant_id' => $activeTenant->id,
            'actor_user_id' => $actor->id,
            'event' => 'membership.enrolled',
            'target_type' => 'membership',
            'target_id' => 'mem-1',
            'organization_id' => $activeOrg->id,
            'created_at' => Carbon::now('UTC')->subDays(1),
        ]);

        // Ensure disabled/not-started tenants are part of the population count.
        $this->assertNotNull($disabled->id);
        $this->assertNotNull($notStarted->id);

        $response = $this->getJson('/api/admin/ministries/overview?window_days=30')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phase', 1)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.window_days', 30)
            ->assertJsonPath('data.kpis.total_tenants.value', 4)
            ->assertJsonPath('data.kpis.module_enabled.value', 3)
            ->assertJsonPath('data.kpis.not_started.value', 1)
            ->assertJsonPath('data.kpis.activated.value', 2)
            ->assertJsonPath('data.kpis.active.value', 1)
            ->assertJsonPath('data.kpis.inactive.value', 1)
            ->assertJsonPath('data.kpis.organizations.value', 2)
            ->assertJsonPath('data.kpis.meaningful_actions.value', 2)
            ->assertJsonPath('data.kpis.active_users.value', 1)
            ->assertJsonPath('data.funnel.all_tenants', 4)
            ->assertJsonPath('data.funnel.module_enabled', 3)
            ->assertJsonPath('data.funnel.activated', 2)
            ->assertJsonPath('data.funnel.active', 1);

        $attentionIds = collect($response->json('data.attention'))->pluck('id')->all();
        $this->assertContains('not_started', $attentionIds);
        $this->assertContains('inactive', $attentionIds);

        $activityEvents = collect($response->json('data.recent_activity'))->pluck('event')->all();
        $this->assertContains('organization.updated', $activityEvents);
        $this->assertContains('membership.enrolled', $activityEvents);
    }

    #[Test]
    public function attention_endpoint_returns_cards(): void
    {
        $this->authenticateAsSuperAdmin();

        Tenant::factory()->create([
            'name' => 'Not Started Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);

        $this->getJson('/api/admin/ministries/attention?window_days=30')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.window_days', 30)
            ->assertJsonStructure([
                'data' => [
                    'attention' => [
                        ['id', 'label', 'severity', 'count', 'href', 'filter'],
                    ],
                ],
            ]);

        $attentionIds = collect($this->getJson('/api/admin/ministries/attention')->json('data.attention'))
            ->pluck('id')
            ->all();
        $this->assertContains('not_started', $attentionIds);
    }

    #[Test]
    public function activity_endpoint_returns_recent_feed(): void
    {
        $actor = $this->authenticateAsSuperAdmin();
        $tenant = Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $org = $this->createOrganization($tenant->id, 'ACT-1', 'Activity Org');

        MinistriesAuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor->id,
            'event' => 'organization.updated',
            'target_type' => Organization::class,
            'target_id' => $org->id,
            'organization_id' => $org->id,
            'created_at' => Carbon::now()->subDay(),
        ]);

        $this->getJson('/api/admin/ministries/activity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.limit', 25)
            ->assertJsonFragment(['event' => 'organization.updated']);
    }

    #[Test]
    public function invalid_window_is_rejected(): void
    {
        $this->authenticateAsSuperAdmin();

        $this->getJson('/api/admin/ministries/overview?window_days=14')
            ->assertStatus(422);
    }

    #[Test]
    public function kpis_are_null_unavailable_never_fabricated_as_unknown_zero_confusion(): void
    {
        $this->authenticateAsSuperAdmin();

        $this->getJson('/api/admin/ministries/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_tenants.available', true)
            ->assertJsonPath('data.kpis.total_tenants.value', 0)
            ->assertJsonPath('data.kpis.active_users.available', true);
    }

    private function authenticateAsSuperAdmin(): User
    {
        $this->seed(AdminMinistriesPlatformPermissionSeeder::class);

        $role = Role::query()->firstOrCreate(
            ['name' => Role::SUPER_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Super Admin',
                'level' => 0,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOrganization(int $tenantId, string $code, string $name): Organization
    {
        $category = OrganizationCategory::create([
            'tenant_id' => $tenantId,
            'code' => $code.'-cat',
            'name' => $name.' Category',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $type = OrganizationType::create([
            'tenant_id' => $tenantId,
            'code' => $code.'-type',
            'name' => $name.' Type',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        return Organization::create([
            'tenant_id' => $tenantId,
            'category_id' => $category->id,
            'type_id' => $type->id,
            'code' => $code,
            'name' => $name,
            'status' => Organization::STATUS_ACTIVE,
        ]);
    }
}
