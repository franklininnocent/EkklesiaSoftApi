<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\CollectionForecastService;

class CollectionForecastController extends Controller
{
    public function __construct(private readonly CollectionForecastService $forecastService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->forecastService->build(
                app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                (int) ($validated['months'] ?? 3)
            ),
        ]);
    }
}
