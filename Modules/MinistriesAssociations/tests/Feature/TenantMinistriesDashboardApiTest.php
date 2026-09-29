<?php

namespace Modules\MinistriesAssociations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantMinistriesDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
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

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->admin->syncRoles([$role->id]);

        $permission = Permission::updateOrCreate(
            ['name' => 'ministries.view'],
            [
                'display_name' => 'ministries.view',
                'description' => 'Test permission',
                'module' => 'MinistriesAssociations',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'ministries',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    #[Test]
    public function dashboard_reports_vacancies_from_tenant_single_occupancy_positions_per_active_org(): void
    {
        Passport::actingAs($this->admin);

        $category = OrganizationCategory::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MIN',
            'name' => 'Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $type = OrganizationType::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PARISH',
            'name' => 'Parish Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $positionA = Position::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PRES',
            'name' => 'President',
            'single_occupancy' => true,
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $positionB = Position::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SEC',
            'name' => 'Secretary',
            'single_occupancy' => true,
            'is_system' => false,
            'is_active' => true,
            'display_order' => 2,
        ]);

        $filledOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'type_id' => $type->id,
            'code' => 'CHOIR',
            'name' => 'Parish Choir',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $vacantOrg = Organization::query()->create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'type_id' => $type->id,
            'code' => 'YOUTH',
            'name' => 'Youth Group',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        LeadershipTerm::query()->create([
            'tenant_id' => $this->tenant->id,
            'organization_id' => $filledOrg->id,
            'position_id' => $positionA->id,
            'status' => LeadershipTerm::STATUS_ACTIVE,
            'appointment_date' => now()->toDateString(),
            'effective_from' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/tenant/ministries/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organizations.active', 2);

        // Two single-occupancy positions × two active orgs = 4 slots; one filled → 3 vacancies.
        $this->assertSame(3, $response->json('data.leadership.vacancies'));
    }
}
