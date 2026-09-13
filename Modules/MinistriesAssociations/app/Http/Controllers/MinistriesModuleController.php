<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class MinistriesModuleController extends Controller
{
    use AuthorizesRequests;

    private const FEATURE_KEY = 'ministries_associations';

    public function moduleStatus(): JsonResponse
    {
        $this->authorize('ministries.viewModuleStatus');

        $user = Auth::user();
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if (! $user || $tenantId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $tenant->supportsMinistriesAssociations(),
                'feature_key' => self::FEATURE_KEY,
            ],
        ]);
    }
}
