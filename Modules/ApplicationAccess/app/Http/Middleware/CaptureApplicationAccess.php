<?php

namespace Modules\ApplicationAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\ApplicationAccess\Services\ApplicationAccessCaptureService;
use Modules\ApplicationAccess\Support\ApplicationAccessStatsKeys;
use Symfony\Component\HttpFoundation\Response;

class CaptureApplicationAccess
{
    public function __construct(
        private readonly ApplicationAccessCaptureService $captureService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(
            ApplicationAccessCaptureService::CAPTURED_AT_ATTRIBUTE,
            now()
        );

        $response = $next($request);

        try {
            $this->captureService->capture($request, $response);
        } catch (\Throwable $exception) {
            Cache::increment(ApplicationAccessStatsKeys::CAPTURE_FAILURES);
            Log::warning('Application access capture failed', [
                'message' => $exception->getMessage(),
                'path' => $request->path(),
            ]);
        }

        return $response;
    }
}
