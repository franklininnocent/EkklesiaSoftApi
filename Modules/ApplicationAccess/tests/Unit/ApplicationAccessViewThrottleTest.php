<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Modules\ApplicationAccess\Support\ApplicationAccessViewThrottle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessViewThrottleTest extends TestCase
{
    #[Test]
    public function it_allows_only_one_view_event_per_session_route_window(): void
    {
        config(['applicationaccess.view_throttle_seconds' => 60]);
        Cache::flush();

        $throttle = new ApplicationAccessViewThrottle;

        $this->assertTrue($throttle->shouldRecord('session-1', '/api/families'));
        $this->assertFalse($throttle->shouldRecord('session-1', '/api/families'));
        $this->assertTrue($throttle->shouldRecord('session-1', '/api/users'));
        $this->assertTrue($throttle->shouldRecord('session-2', '/api/families'));
    }
}
