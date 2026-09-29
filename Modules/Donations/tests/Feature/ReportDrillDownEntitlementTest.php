<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class ReportDrillDownEntitlementTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function starter_plan_is_blocked_from_drill_down_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $permission = Permission::updateOrCreate(
            ['name' => 'donations.reports'],
            [
                'display_name' => 'donations.reports',
                'description' => 'Test',
                'module' => 'Donations',
                'scope' => Permission::SCOPE_TENANT,
                'category' => 'donations',
                'tenant_id' => null,
                'is_custom' => false,
                'active' => 1,
            ]
        );
        $ctx['role']->permissions()->syncWithoutDetaching([$permission->id]);

        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]))
            ->assertForbidden()
            ->assertJsonPath('feature', 'ADVANCED_FINANCIAL_REPORTING');
    }
}
