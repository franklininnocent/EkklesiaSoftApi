<?php

namespace Modules\Donations\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Services\DashboardPersonaService;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DashboardPersonaServiceTest extends DonationsCertificationTestCase
{
    use RefreshDatabase;

    private const ADMIN_EMPHASIS = 'Collections, family follow-up, and parish operations in one place.';

    private const TREASURER_EMPHASIS = 'Collections, trends, and forecasts for financial stewardship.';

    private const SECRETARY_EMPHASIS = 'Fast family follow-up, receipts, and daily collections.';

    private const PRIEST_EMPHASIS = 'Families needing care and active project momentum come first.';

    private const DIOCESE_OFFICER_EMPHASIS = 'Parish comparisons and consolidated diocese performance.';

    private DashboardPersonaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardPersonaService;
    }

    #[Test]
    public function it_resolves_tenant_administrator_to_admin_persona(): void
    {
        $user = $this->userWithRoleName(Role::TENANT_ADMINISTRATOR, ['donations.collect', 'donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('admin', $result['persona']);
        $this->assertSame('Administrator View', $result['label']);
        $this->assertSame(self::ADMIN_EMPHASIS, $result['emphasis']);
        $this->assertSame('payments', $result['default_report']);
        $this->assertContains('analytics', $result['sections']);
        $this->assertContains('ai_advisor', $result['sections']);
    }

    #[Test]
    public function it_maps_persona_to_default_report_key(): void
    {
        $this->assertSame('outstanding', $this->service->defaultReportKey('priest'));
        $this->assertSame('receipts', $this->service->defaultReportKey('secretary'));
        $this->assertSame('parish_comparison', $this->service->defaultReportKey('diocese_officer'));
    }

    #[Test]
    public function it_admin_persona_emphasis_does_not_imply_finance_module_or_full_authorization(): void
    {
        $user = $this->userWithRoleName(Role::TENANT_ADMINISTRATOR, ['donations.collect', 'donations.reports']);

        $emphasis = strtolower($this->service->resolve($user, ['tier' => 'parish'])['emphasis']);

        $this->assertSame(strtolower(self::ADMIN_EMPHASIS), $emphasis);
        foreach (['financial', 'finance', 'full financial', 'full access', 'all permissions', 'complete access'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $emphasis);
        }
    }

    #[Test]
    public function it_resolves_secretary_role_to_secretary_persona(): void
    {
        $user = $this->userWithRoleName('Secretary', ['donations.collect', 'donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('secretary', $result['persona']);
        $this->assertSame('Secretary View', $result['label']);
        $this->assertSame(self::SECRETARY_EMPHASIS, $result['emphasis']);
    }

    #[Test]
    public function it_resolves_parish_priest_to_pastoral_persona(): void
    {
        $user = $this->userWithRoleName('Parish Priest', ['donations.collect', 'donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('priest', $result['persona']);
        $this->assertSame('Pastoral View', $result['label']);
        $this->assertSame(self::PRIEST_EMPHASIS, $result['emphasis']);
    }

    #[Test]
    public function it_resolves_diocese_administrator_with_reports_to_diocese_officer(): void
    {
        $user = $this->userWithRoleName(Role::TENANT_ADMINISTRATOR, ['donations.reports', 'donations.collect']);

        $result = $this->service->resolve($user, ['tier' => 'diocese']);

        $this->assertSame('diocese_officer', $result['persona']);
        $this->assertSame('Diocese Officer View', $result['label']);
        $this->assertSame(self::DIOCESE_OFFICER_EMPHASIS, $result['emphasis']);
        $this->assertSame('rollup', $result['default_dashboard_view']);
    }

    #[Test]
    public function it_resolves_treasurer_role_to_treasurer_persona_and_emphasis(): void
    {
        $user = $this->userWithRoleName('Treasurer', ['donations.collect', 'donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('treasurer', $result['persona']);
        $this->assertSame('Treasurer View', $result['label']);
        $this->assertSame(self::TREASURER_EMPHASIS, $result['emphasis']);
    }

    #[Test]
    public function it_resolves_collect_only_permissions_to_secretary_persona(): void
    {
        $user = $this->userWithRoleName('Volunteer', ['donations.collect']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('secretary', $result['persona']);
        $this->assertSame(self::SECRETARY_EMPHASIS, $result['emphasis']);
    }

    #[Test]
    public function it_resolves_reports_only_permissions_to_treasurer_persona(): void
    {
        $user = $this->userWithRoleName('Volunteer', ['donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('treasurer', $result['persona']);
        $this->assertSame(self::TREASURER_EMPHASIS, $result['emphasis']);
    }

    #[Test]
    public function it_resolves_church_administrator_title_to_secretary_persona(): void
    {
        $user = $this->userWithRoleName('Church Administrator', ['donations.collect', 'donations.reports']);

        $result = $this->service->resolve($user, ['tier' => 'parish']);

        $this->assertSame('secretary', $result['persona']);
        $this->assertSame('Secretary View', $result['label']);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithRoleName(string $roleName, array $permissionNames): User
    {
        $context = $this->makeTenantUser($permissionNames);
        $context['role']->update(['name' => $roleName]);

        return $context['user']->fresh()->load('role');
    }
}
