<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Services\ApplicationAccessCaptureService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApplicationAccessInvalidTokenSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['applicationaccess.telemetry_enabled' => true]);
        Cache::flush();
    }

    #[Test]
    public function invalid_bearer_records_throttled_invalid_token_signal(): void
    {
        $request = Request::create('/api/families', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->headers->set('Authorization', 'Bearer invalid.jwt.token');

        $response = new Response('', 200);

        app(ApplicationAccessCaptureService::class)->capture($request, $response);
        app(ApplicationAccessCaptureService::class)->capture($request, $response);

        $this->assertSame(1, ApplicationSecuritySignal::query()->where('signal_type', 'INVALID_TOKEN')->count());
    }
}
