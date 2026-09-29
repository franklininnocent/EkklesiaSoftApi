<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownValidationTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function it_rejects_per_page_above_fifty(): void
    {
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'per_page' => 51,
        ]))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_invalid_sort_field(): void
    {
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'sort' => 'hacked_column',
        ]))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_unknown_filter_keys(): void
    {
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'filters' => ['tenant_id' => '999'],
        ]))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_unknown_graph_id(): void
    {
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'not_a_graph',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_element_slice_mismatch(): void
    {
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'previous_month_collected',
            'slice_id' => 'current_month',
        ]))->assertStatus(422);
    }
}
