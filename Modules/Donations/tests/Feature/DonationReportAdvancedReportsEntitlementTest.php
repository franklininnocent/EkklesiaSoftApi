<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class DonationReportAdvancedReportsEntitlementTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    private function grantReportsPermission(array $ctx): void
    {
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
    }

    #[Test]
    public function starter_plan_can_preview_core_collect_reports_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantReportsPermission($ctx);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?report_type=payments&preset=this_month')
            ->assertOk();
    }

    #[Test]
    public function starter_plan_is_blocked_from_advanced_reports_tier_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantReportsPermission($ctx);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?report_type=outstanding')
            ->assertForbidden();
    }

    #[Test]
    public function standard_plan_can_preview_follow_up_reports_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantReportsPermission($ctx);
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?report_type=participation&preset=last_90')
            ->assertOk();
    }
}
