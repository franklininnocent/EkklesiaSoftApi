<?php

namespace Modules\MassIntentions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\MassIntentions\Services\MassGenerationHealthService;
use Modules\MassIntentions\Services\MassIntentionsDashboardService;
use Modules\MassIntentions\Services\MassIntentionOfficeCloseService;
use Modules\MassIntentions\Services\MassNextUpcomingCelebrationService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassIntentionsExecutiveCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function executive_operational_counts_does_not_run_office_close(): void
    {
        $tenant = Tenant::factory()->create();

        $officeClose = Mockery::mock(MassIntentionOfficeCloseService::class);
        $officeClose->shouldNotReceive('closeExpiredForTenant');

        $service = new MassIntentionsDashboardService(
            $officeClose,
            app(MassGenerationHealthService::class),
            app(MassNextUpcomingCelebrationService::class),
        );

        $counts = $service->executiveOperationalCounts((int) $tenant->id);

        $this->assertArrayHasKey('needs_a_mass', $counts);
        $this->assertArrayHasKey('this_week_masses', $counts);
    }
}
