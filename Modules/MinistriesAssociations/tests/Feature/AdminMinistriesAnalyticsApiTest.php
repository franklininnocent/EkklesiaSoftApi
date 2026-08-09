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
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMinistriesAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function analytics_endpoints_require_platform_permission(): void
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

        $this->getJson('/api/admin/ministries/analytics/adoption')->assertForbidden();
        $this->getJson('/api/admin/ministries/analytics/usage')->assertForbidden();
        $this->getJson('/api/admin/ministries/analytics/features')->assertForbidden();
        $this->getJson('/api/admin/ministries/analytics/trends')->assertForbidden();
        $this->getJson('/api/admin/ministries/audit')->assertForbidden();
    }

    #[Test]
    public function analytics_and_audit_return_real_aggregations(): void
    {
        $actor = $this->authenticateAsSuperAdmin();

        $enabled = Tenant::factory()->create([
            'name' => 'Enabled Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        Tenant::factory()->create([
            'name' => 'Disabled Parish',
            'features' => ['donations'],
            'active' => 1,
        ]);

        $this->createOrganization($enabled->id, 'youth', 'Youth Ministry');
        MinistriesAuditLog::query()->create([
            'tenant_id' => $enabled->id,
            'actor_user_id' => $actor->id,
            'event' => 'organization.created',
            'target_type' => 'organization',
            'target_id' => 'org-1',
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);
        MinistriesAuditLog::query()->create([
            'tenant_id' => $enabled->id,
            'actor_user_id' => $actor->id,
            'event' => 'membership.enrolled',
            'target_type' => 'membership',
            'target_id' => 'mem-1',
            'created_at' => Carbon::now('UTC')->subDays(1),
        ]);

        if (class_exists(TenantSubscriptionAudit::class)) {
            TenantSubscriptionAudit::query()->create([
                'tenant_id' => $enabled->id,
                'actor_id' => $actor->id,
                'actor_role' => 'SuperAdmin',
                'operation' => 'plan_changed',
                'source' => 'admin',
                'reason' => 'enable ministries',
                'before_state' => ['features' => ['donations']],
                'after_state' => ['features' => ['donations', 'ministries_associations']],
                'created_at' => Carbon::now('UTC')->subDay(),
            ]);
        }

        $adoption = $this->getJson('/api/admin/ministries/analytics/adoption?window_days=30')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $adoption['funnel']['all_tenants']);
        $this->assertSame(1, $adoption['funnel']['module_enabled']);
        $this->assertSame(1, $adoption['funnel']['activated']);
        $this->assertSame(1, $adoption['funnel']['active']);
        $this->assertNotNull($adoption['rates']['activated_of_enabled']);

        $usage = $this->getJson('/api/admin/ministries/analytics/usage?window_days=30')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $usage['summary']['meaningful_actions']);
        $this->assertSame(1, $usage['summary']['active_tenants']);
        $this->assertNotEmpty($usage['top_tenants']);

        $features = $this->getJson('/api/admin/ministries/analytics/features?window_days=30&category=members')
            ->assertOk()
            ->json('data');
        $orgCat = collect($features['categories'])->firstWhere('category', 'organizations');
        $memberCat = collect($features['categories'])->firstWhere('category', 'members');
        $this->assertEquals(100.0, $orgCat['adoption_percent']);
        $this->assertEquals(100.0, $memberCat['adoption_percent']);
        $this->assertSame('members', $features['drilldown']['category']);
        $this->assertCount(1, $features['drilldown']['tenants']);

        $trends = $this->getJson('/api/admin/ministries/analytics/trends?window_days=30')
            ->assertOk()
            ->json('data');
        $this->assertSame('day', $trends['granularity']);
        $this->assertSame(2, $trends['comparison']['current_meaningful_actions']);
        $this->assertNotEmpty($trends['series']);

        $audit = $this->getJson('/api/admin/ministries/audit?window_days=30&meaningful_only=1')
            ->assertOk()
            ->json();
        $this->assertCount(2, $audit['data']);
        $this->assertTrue($audit['governance']['available']);
        $this->assertNotEmpty($audit['governance']['items']);
        $this->assertSame('enabled', $audit['governance']['items'][0]['change']);
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
            'status' => 'active',
        ]);
        $type = OrganizationType::create([
            'tenant_id' => $tenantId,
            'category_id' => $category->id,
            'code' => $code.'-type',
            'name' => $name.' Type',
            'status' => 'active',
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
