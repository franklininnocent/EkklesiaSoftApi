<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationCategory;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class DefaultSeedsApiTest extends TestCase
{
    use InteractsWithTenantContext;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Modules\RolesAndPermissions\Database\Seeders\TenantPermissionCatalogSeeder::class);
        $this->seed(\Modules\Donations\Database\Seeders\DonationsPermissionSeeder::class);
        $this->seed(\Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsPermissionSeeder::class);

        $this->tenant = Tenant::factory()->create([
            'active' => 1,
            'trial_ends_at' => now()->addYear(),
            'subscription_ends_at' => now()->addYear(),
            'features' => ['donations', 'ministries_associations'],
        ]);

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        foreach (['settings.default-seeds.view', 'settings.default-seeds.run', 'donations.manage', 'ministries.configure'] as $permName) {
            $permission = Permission::query()->where('name', $permName)->first();
            if ($permission) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $this->admin->syncRoles([$role->id]);
        $this->admin->clearPermissionsCache();
        $this->admin->unsetRelation('roles');

        Passport::actingAs($this->admin->fresh());
        $this->bindTenantContext($this->admin, (int) $this->tenant->id);
    }

    #[Test]
    public function it_lists_default_seed_catalog_for_tenant_admin(): void
    {
        $response = $this->getJson('/api/tenant/default-seeds');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['available', 'partially_initialized', 'initialized', 'total'],
                    'seeders' => [
                        ['id', 'display_name', 'status', 'missing_count', 'missing_names'],
                    ],
                ],
            ]);

        $ids = collect($response->json('data.seeders'))->pluck('id')->all();
        $this->assertContains('donations.categories', $ids);
        $this->assertContains('ministries.categories', $ids);
    }

    #[Test]
    public function it_adds_missing_donation_categories_without_overwriting_custom_names(): void
    {
        DonationCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'GENERAL',
            'name' => 'Custom General Name',
            'active' => true,
            'is_tax_deductible' => true,
        ]);

        $this->postJson('/api/tenant/default-seeds', [
            'ids' => ['donations.categories'],
        ])->assertOk();

        $this->assertSame(
            'Custom General Name',
            DonationCategory::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('code', 'GENERAL')
                ->value('name')
        );

        $this->assertGreaterThanOrEqual(7, DonationCategory::query()->where('tenant_id', $this->tenant->id)->count());
    }

    #[Test]
    public function it_is_idempotent_on_repeat_execution(): void
    {
        $this->postJson('/api/tenant/default-seeds', [
            'ids' => ['donations.categories'],
        ])->assertOk();

        $countAfterFirst = DonationCategory::query()->where('tenant_id', $this->tenant->id)->count();

        $this->postJson('/api/tenant/default-seeds', [
            'ids' => ['donations.categories'],
        ])->assertOk();

        $this->assertSame(
            $countAfterFirst,
            DonationCategory::query()->where('tenant_id', $this->tenant->id)->count()
        );
    }

    #[Test]
    public function it_rejects_invalid_seeder_ids(): void
    {
        $this->postJson('/api/tenant/default-seeds', [
            'ids' => ['evil.seeder'],
        ])->assertStatus(422);
    }

    #[Test]
    public function it_reports_partial_ministries_status_when_legacy_onboarding_rows_exist(): void
    {
        OrganizationCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ministry',
            'name' => 'Ministry',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $response = $this->getJson('/api/tenant/default-seeds');
        $categories = collect($response->json('data.seeders'))
            ->firstWhere('id', 'ministries.categories');

        $this->assertNotNull($categories);
        $this->assertSame('available', $categories['status']);
        $this->assertTrue($categories['has_other_records']);
        $this->assertSame(7, $categories['missing_count']);
    }
}
