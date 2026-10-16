<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class DonationReportBasicReportsEntitlementTest extends TestCase
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
    public function collect_report_requires_basic_reports_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantReportsPermission($ctx);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        TenantEntitlementOverride::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'feature_id' => Feature::query()->where('code', 'BASIC_REPORTS')->value('id'),
            'mode' => TenantEntitlementOverride::MODE_DISABLE,
            'reason' => 'Test',
        ]);
        app(EntitlementResolver::class)->forget((int) $ctx['tenant']->id);
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?report_type=payments&preset=this_month')
            ->assertForbidden();
    }
}
