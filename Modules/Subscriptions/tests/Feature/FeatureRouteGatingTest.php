<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;
use Modules\Subscriptions\Services\Entitlements\SubscriptionEntitlementGate;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\AuditLogViewerAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

/**
 * Plan features gate the matching API routes in enforce mode only; everyday flows stay open.
 */
class FeatureRouteGatingTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function gatedRoutes(): array
    {
        return [
            'contribution plans' => ['GET', 'api/tenant/donations/plans', 'CONTRIBUTION_PLANS'],
            'dues generation' => ['POST', 'api/tenant/donations/contributions/generate-scheduled', 'CONTRIBUTION_PLANS'],
            'command center' => ['GET', 'api/tenant/donations/dashboard/command-center', 'FINANCIAL_DASHBOARD'],
            'forecast' => ['GET', 'api/tenant/donations/dashboard/forecast', 'FINANCIAL_DASHBOARD'],
            'executive summary' => ['GET', 'api/tenant/donations/reports/executive-summary', 'ADVANCED_FINANCIAL_REPORTING'],
            'report drill-down' => ['GET', 'api/tenant/donations/reports/drill-down', 'ADVANCED_FINANCIAL_REPORTING'],
            'donation audit log' => ['GET', 'api/tenant/donations/audit-logs', 'AUDIT_LOG'],
            'data export' => ['GET', 'api/tenant/export/modules', 'IMPORT_EXPORT'],
            'custom role create' => ['POST', 'api/tenant/roles', 'RBAC_ADVANCED'],
            'role permission sync' => ['PUT', 'api/tenant/roles/{roleId}/permissions', 'RBAC_ADVANCED'],
            'leadership assign' => ['POST', 'api/church-profile/leadership/assign', 'CHURCH_LEADERSHIP'],
            'church leadership create' => ['POST', 'api/church-leadership', 'CHURCH_LEADERSHIP'],
            'bishop update request' => ['POST', 'api/tenant/bishop-updates', 'DIOCESE_BISHOPS'],
            'ministry leadership term' => ['POST', 'api/tenant/ministries/organizations/{organizationId}/leadership/assign', 'ADVANCED_MINISTRY_MANAGEMENT'],
            'mass intentions home' => ['GET', 'api/tenant/mass-intentions/home', 'MASS_INTENTIONS'],
            'mass intentions dashboard' => ['GET', 'api/tenant/mass-intentions/dashboard', 'MASS_INTENTIONS'],
        ];
    }

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function route_carries_the_expected_feature_gate(string $method, string $uri, string $feature): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "Route {$method} {$uri} is not registered.");
        $this->assertContains('entitlement:'.$feature, $route->gatherMiddleware());
    }

    #[Test]
    public function everyday_collection_and_read_routes_are_not_plan_gated(): void
    {
        foreach ([
            ['GET', 'api/tenant/donations/dashboard/summary'],
            ['GET', 'api/tenant/donations/dashboard/operations'],
            ['POST', 'api/tenant/donations/entries/collect'],
            ['POST', 'api/tenant/donations/reports/export'],
            ['GET', 'api/tenant/donations/expenses'],
            ['GET', 'api/tenant/donations/dues'],
            ['GET', 'api/tenant/roles'],
            ['PUT', 'api/tenant/users/{id}/roles'],
            ['GET', 'api/church-profile/leadership/current'],
            ['GET', 'api/church-leadership'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Route {$method} {$uri} is not registered.");
            $gates = array_filter($route->gatherMiddleware(), static fn ($m) => is_string($m) && str_starts_with($m, 'entitlement:'));
            $this->assertSame([], array_values($gates), "{$method} {$uri} must stay available on every plan.");
        }
    }

    #[Test]
    public function starter_church_is_blocked_from_higher_plan_features_only_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->useEntitlementEngine('shadow');
        $this->getJson('/api/tenant/export/modules')->assertOk();

        $this->useEntitlementEngine('enforce');
        $this->getJson('/api/tenant/export/modules')
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionException::FEATURE_NOT_AVAILABLE)
            ->assertJsonPath('feature', 'IMPORT_EXPORT');
        $this->getJson('/api/tenant/donations/plans')
            ->assertForbidden()
            ->assertJsonPath('feature', 'CONTRIBUTION_PLANS');
        $this->getJson('/api/tenant/roles')->assertOk();
    }

    #[Test]
    public function upgrading_the_plan_opens_the_gated_routes(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/export/modules')->assertOk();
        $this->getJson('/api/tenant/donations/plans')->assertOk();
    }

    #[Test]
    public function audit_log_viewing_follows_the_plan_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $admin = $ctx['user'];
        $admin->forceFill(['is_primary_admin' => true])->save();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $this->useEntitlementEngine('shadow');
        $this->assertTrue(AuditLogViewerAuthorization::canViewTenantAudit($admin->fresh()));

        $this->useEntitlementEngine('enforce');
        $this->assertFalse(AuditLogViewerAuthorization::canViewTenantAudit($admin->fresh()));

        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->assertTrue(AuditLogViewerAuthorization::canViewTenantAudit($admin->fresh()));
    }

    #[Test]
    public function grandfathered_free_church_keeps_advanced_financial_reporting_in_every_mode(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free', 'features' => null]);
        $gate = app(SubscriptionEntitlementGate::class);

        foreach (['legacy', 'shadow'] as $mode) {
            $this->useEntitlementEngine($mode);
            $this->assertTrue($gate->allows($tenant, 'ADVANCED_REPORTS'), "{$mode}: advanced reports");
            $this->assertTrue($gate->allows($tenant, 'ADVANCED_FINANCIAL_REPORTING'), "{$mode}: advanced financial reporting");
        }

        app(TenantSubscriptionBackfiller::class)->run();
        $this->assertSame('LEGACY_FREE', $this->currentSubscription($tenant)->plan->code);

        $this->useEntitlementEngine('enforce');
        $gate->forget($tenant);
        $this->assertTrue($gate->allows($tenant, 'ADVANCED_REPORTS'));
        $this->assertTrue($gate->allows($tenant, 'ADVANCED_FINANCIAL_REPORTING'));
    }

    private function findRoute(string $method, string $uri): ?RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
