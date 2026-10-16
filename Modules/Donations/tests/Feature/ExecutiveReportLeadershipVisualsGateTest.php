<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ParishExpense;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Services\ParishExpenseService;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class ExecutiveReportLeadershipVisualsGateTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use InteractsWithTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
        $this->bindTenantContext($this->tenantAdminUser);
    }

    #[Test]
    public function executive_summary_omits_projects_and_expenses_when_not_applicable(): void
    {
        $response = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();

        $this->assertNull($response->json('data.visuals.projects'));
        $this->assertNull($response->json('data.visuals.expenses_vs_collections'));
        $snapshot = $response->json('data.visuals.collection_snapshot');
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('expected', $snapshot);
        $this->assertArrayHasKey('collected', $snapshot);
    }

    #[Test]
    public function executive_collections_bar_uses_comparable_previous_month(): void
    {
        $metrics = app(ExecutiveReportMetricsService::class);
        $comparable = $metrics->comparablePreviousMonthCollected($this->tenant->id);
        $fullRange = $metrics->previousMonthCollectionRange($this->tenant->id);
        $full = $metrics->sumSucceededPayments($this->tenant->id, $fullRange['start'], $fullRange['end']);

        $response = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $this->assertSame(
            $comparable,
            (float) $response->json('data.visuals.collections.previous_month_collected')
        );
        $this->assertSame($full, (float) $response->json('data.visuals.collections.previous_month_full_collected'));
    }

    #[Test]
    public function executive_summary_includes_expenses_vs_collections_when_fy_has_disbursements(): void
    {
        $businessDate = DonationBusinessDate::today($this->tenant->id);

        ParishExpense::create([
            'tenant_id' => $this->tenant->id,
            'category' => 'Utilities',
            'amount' => 850,
            'expense_date' => $businessDate,
            'payee' => 'Vendor',
            'method' => 'cash',
            'status' => 'recorded',
        ]);

        $monthStart = DonationBusinessDate::monthStart($this->tenant->id);
        $metrics = app(ParishExpenseService::class);
        $this->assertTrue($metrics->hasAnySince($this->tenant->id, DonationBusinessDate::currentFinancialYearBounds($this->tenant->id, $businessDate)['start']));
        $this->assertSame(850.0, $metrics->monthTotal($this->tenant->id, $monthStart, $businessDate));

        $response = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $visual = $response->json('data.visuals.expenses_vs_collections');

        $this->assertIsArray($visual);
        $this->assertArrayHasKey('month_collected', $visual);
        $this->assertArrayHasKey('month_expenses', $visual);
        $this->assertSame(850.0, (float) $visual['month_expenses']);
        $this->assertArrayNotHasKey('surplus', $visual);
        $this->assertArrayNotHasKey('deficit', $visual);
    }

    #[Test]
    public function executive_summary_includes_project_panel_when_active_projects_exist(): void
    {
        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Roof Repair',
            'code' => 'ROOF-1',
            'status' => 'active',
            'target_amount' => 5000,
            'raised_amount' => 1200,
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $projects = $response->json('data.visuals.projects');

        $this->assertIsArray($projects);
        $this->assertCount(1, $projects);
        $this->assertSame('Roof Repair', $projects[0]['name'] ?? null);
    }
}
