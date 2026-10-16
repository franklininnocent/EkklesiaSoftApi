<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownTimezoneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function parish_clock_uses_tenant_timezone_when_utc_calendar_day_differs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 14:00:00', 'UTC'));

        $tenant = Tenant::factory()->active()->create([
            'settings' => [
                'timezone' => 'Pacific/Auckland',
                'language' => 'en',
                'currency' => 'USD',
            ],
        ]);

        $this->assertSame('2026-02-01', DonationBusinessDate::today($tenant->id));
        $this->assertSame('2026-02-01', DonationBusinessDate::monthStart($tenant->id));

        $metrics = app(ExecutiveReportMetricsService::class);
        $clock = $metrics->parishClock($tenant->id);

        $this->assertSame('2026-02-01', $clock['business_date']);
        $this->assertSame('2026-02-01', $clock['start']);
        $this->assertSame('2026-02-01', $clock['end']);
        $this->assertSame('Pacific/Auckland', $clock['timezone']);
    }
}
