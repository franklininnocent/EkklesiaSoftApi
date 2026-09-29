<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessSessionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_throttles_last_activity_updates(): void
    {
        config(['applicationaccess.session_touch_seconds' => 60]);
        Cache::flush();

        $repository = app(ApplicationAccessSessionRepository::class);
        $started = now()->subMinutes(5);

        $session = $repository->create([
            'identity_type' => ApplicationAccessEnums::IDENTITY_TENANT_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_TENANT,
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => $started,
            'last_activity_at' => $started,
        ]);

        $repository->touchActivity($session);
        $firstTouch = $session->refresh()->last_activity_at;

        $repository->touchActivity($session->refresh());
        $secondTouch = $session->refresh()->last_activity_at;

        $this->assertTrue($firstTouch->greaterThan($started));
        $this->assertTrue($secondTouch->equalTo($firstTouch));
    }
}
