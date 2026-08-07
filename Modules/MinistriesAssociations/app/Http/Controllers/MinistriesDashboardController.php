<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Services\MinistriesDashboardService;

class MinistriesDashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MinistriesDashboardService $dashboardService,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $user = Auth::user();
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId();
        if (! $user || $tenantId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->summary($tenantId),
        ]);
    }
}
