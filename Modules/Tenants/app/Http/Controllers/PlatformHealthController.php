<?php

namespace Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Tenants\Services\PlatformHealthService;

class PlatformHealthController extends Controller
{
    public function show(PlatformHealthService $healthService): JsonResponse
    {
        $snapshot = $healthService->snapshot();
        $statusCode = match ($snapshot['status']) {
            'fail' => 503,
            'degraded' => 200,
            default => 200,
        };

        return response()->json([
            'success' => $snapshot['status'] !== 'fail',
            'data' => $snapshot,
        ], $statusCode);
    }
}
