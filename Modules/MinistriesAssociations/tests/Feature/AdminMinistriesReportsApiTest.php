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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMinistriesReportsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reports_catalog_and_summary_require_platform_permission(): void
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

        $this->getJson('/api/admin/ministries/reports')->assertForbidden();
        $this->getJson('/api/admin/ministries/reports/module_adoption')->assertForbidden();
        $this->get('/api/admin/ministries/reports/module_adoption/export')->assertForbidden();
    }

    #[Test]
    public function catalog_summary_and_export_work_for_super_admin(): void
    {
        $actor = $this->authenticateAsSuperAdmin();

        $enabled = Tenant::factory()->create([
            'name' => 'Enabled Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        Tenant::factory()->create([
            'name' => 'Not Started Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);

        $this->createOrganization($enabled->id, 'choir', 'Choir');
        MinistriesAuditLog::query()->create([
            'tenant_id' => $enabled->id,
            'actor_user_id' => $actor->id,
            'event' => 'organization.created',
            'target_type' => 'organization',
            'target_id' => 'org-1',
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);

        $catalog = $this->getJson('/api/admin/ministries/reports')
            ->assertOk()
            ->json('data');
        $this->assertCount(9, $catalog);
        $this->assertSame('module_adoption', $catalog[0]['type']);

        $this->getJson('/api/admin/ministries/reports/unknown_type')
            ->assertNotFound();

        $adoption = $this->getJson('/api/admin/ministries/reports/module_adoption?window_days=30')
            ->assertOk()
            ->json('data');
        $this->assertSame('module_adoption', $adoption['type']);
        $this->assertSame(2, $adoption['summary']['module_enabled']);
        $this->assertSame(1, $adoption['summary']['activated']);

        $features = $this->getJson('/api/admin/ministries/reports/feature_adoption?window_days=30')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($features['preview']);

        $health = $this->getJson('/api/admin/ministries/reports/data_health')
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('indicators', $health['summary']);

        $export = $this->get('/api/admin/ministries/reports/module_adoption/export?window_days=30');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString('tenant_id', $csv);
        $this->assertStringContainsString('Enabled Parish', $csv);
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
