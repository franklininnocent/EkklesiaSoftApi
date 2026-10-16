<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class ReportDrillDownSecurityTest extends DonationsCertificationTestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_never_returns_other_tenant_families_in_outstanding_drill_down(): void
    {
        $a = $this->actingAsTenantWith(['donations.reports', 'donations.view']);
        $seedA = $this->seedDue($a['tenant']->id, null, '500.00');
        $seedA['due']->update(['due_date' => now()->subDays(10)->toDateString()]);

        $b = $this->makeTenantUser(['donations.reports', 'donations.view']);
        $seedB = $this->seedDue($b['tenant']->id, null, '900.00');
        $seedB['due']->update(['due_date' => now()->subDays(12)->toDateString()]);

        Passport::actingAs($a['user']);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'outstanding_overdue',
            'data_element_id' => 'overdue_amount',
            'slice_id' => 'overdue',
            'per_page' => 50,
        ]));

        $drill->assertOk();
        $familyIds = collect($drill->json('data.data.data'))->pluck('family_id');
        $this->assertTrue($familyIds->contains($seedA['family']->id));
        $this->assertFalse($familyIds->contains($seedB['family']->id));
    }

    #[Test]
    public function it_returns_no_rows_for_foreign_bcc_filter(): void
    {
        $a = $this->actingAsTenantWith(['donations.reports', 'donations.view']);
        $other = $this->makeTenantUser(['donations.view']);
        $foreignBcc = BCC::factory()->create(['tenant_id' => $other['tenant']->id]);

        $seed = $this->seedDue($a['tenant']->id);
        $seed['due']->update(['due_date' => now()->subDays(5)->toDateString()]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'outstanding_overdue',
            'data_element_id' => 'overdue_amount',
            'slice_id' => 'overdue',
            'filters' => ['bcc_id' => $foreignBcc->id],
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.data.total', 0);
    }

    #[Test]
    public function it_forbids_drill_down_without_donations_reports_permission(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $role = Role::create([
            'name' => 'Donations Viewer '.uniqid(),
            'description' => 'View only',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);

        $permission = Permission::updateOrCreate(
            ['name' => 'donations.view'],
            [
                'display_name' => 'donations.view',
                'description' => 'Test',
                'module' => 'Donations',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'donations',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $role->permissions()->sync([$permission->id]);

        Passport::actingAs($user);

        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]))->assertForbidden();
    }
}
