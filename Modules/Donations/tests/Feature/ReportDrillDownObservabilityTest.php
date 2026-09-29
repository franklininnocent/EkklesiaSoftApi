<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownObservabilityTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function drill_down_logs_duration_and_db_time_without_pii(): void
    {
        Log::spy();

        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]))->assertOk();

        Log::assertLogged(function ($log) {
            return $log->level === 'info'
                && $log->message === 'report.drill_down'
                && isset($log->context['duration_ms'], $log->context['db_time_ms'], $log->context['graph_id'])
                && ! array_key_exists('payer_name', $log->context ?? []);
        });
    }
}
