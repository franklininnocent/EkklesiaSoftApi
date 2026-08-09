<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

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

class AdminMinistriesTenantsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_list_filters_and_paginates(): void
    {
        $this->authenticateAsSuperAdmin();

        $notStarted = Tenant::factory()->create([
            'name' => 'Alpha Not Started',
            'slug' => 'alpha-not-started',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $active = Tenant::factory()->create([
            'name' => 'Beta Active',
            'slug' => 'beta-active',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        Tenant::factory()->create([
            'name' => 'Gamma Disabled',
            'slug' => 'gamma-disabled',
            'features' => ['donations'],
            'active' => 1,
        ]);

        $org = $this->createOrganization($active->id, 'beta-org', 'Beta Org');
        MinistriesAuditLog::create([
            'tenant_id' => $active->id,
            'actor_user_id' => auth()->id(),
            'event' => 'organization.updated',
            'target_type' => 'organization',
            'target_id' => $org->id,
            'organization_id' => $org->id,
        ]);

        $this->assertNotNull($notStarted->id);

        $this->getJson('/api/admin/ministries/tenants?adoption_status=not_started&sort=tenant_name')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant_name', 'Alpha Not Started')
            ->assertJsonPath('data.0.adoption_status', 'not_started');

        $this->getJson('/api/admin/ministries/tenants?search=Beta&adoption_status=active')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant_slug', 'beta-active')
            ->assertJsonPath('data.0.window_actions', 1);
    }

    #[Test]
    public function tenant_detail_returns_usage_features_and_health(): void
    {
        $actor = $this->authenticateAsSuperAdmin();
        $tenant = Tenant::factory()->create([
            'name' => 'Detail Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);
        $org = $this->createOrganization($tenant->id, 'detail-org', 'Detail Org');
        MinistriesAuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor->id,
            'event' => 'membership.enrolled',
            'target_type' => 'membership',
            'target_id' => 'm1',
            'organization_id' => $org->id,
        ]);

        $this->getJson('/api/admin/ministries/tenants/'.$tenant->id.'?window_days=30')
            ->assertOk()
            ->assertJsonPath('data.summary.tenant_id', $tenant->id)
            ->assertJsonPath('data.summary.organizations_count', 1)
            ->assertJsonPath('data.feature_adoption.organizations.used', true)
            ->assertJsonPath('data.usage.30.meaningful_actions', 1)
            ->assertJsonPath('data.health.orgs_without_active_members', 1);
    }

    #[Test]
    public function export_streams_csv(): void
    {
        $this->authenticateAsSuperAdmin();
        Tenant::factory()->create([
            'name' => 'Export Parish',
            'features' => ['ministries_associations'],
            'active' => 1,
        ]);

        $response = $this->get('/api/admin/ministries/tenants/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('tenant_name', $content);
        $this->assertStringContainsString('Export Parish', $content);
    }

    #[Test]
    public function unknown_tenant_detail_is_not_found(): void
    {
        $this->authenticateAsSuperAdmin();

        $this->getJson('/api/admin/ministries/tenants/999999')
            ->assertNotFound();
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
