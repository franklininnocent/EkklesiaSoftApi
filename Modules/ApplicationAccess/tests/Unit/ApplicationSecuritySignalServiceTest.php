<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Services\ApplicationSecuritySignalService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationSecuritySignalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['applicationaccess.telemetry_enabled' => true]);
    }

    #[Test]
    public function it_upserts_signals_within_the_same_minute_window(): void
    {
        $service = app(ApplicationSecuritySignalService::class);

        $service->upsertMinuteWindow('REPEATED_403', '198.51.100.10');
        $service->upsertMinuteWindow('REPEATED_403', '198.51.100.10');

        $this->assertSame(1, ApplicationSecuritySignal::query()->count());
        $this->assertSame(2, ApplicationSecuritySignal::query()->value('event_count'));
    }
}
