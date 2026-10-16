<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class MassIntentionsModuleController extends Controller
{
    public function status(): JsonResponse
    {
        Gate::authorize('massIntentions.viewModuleStatus');

        $tenantId = app(TenantContext::class)->effectiveTenantId();
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $tenant?->supportsMassIntentions() ?? false,
            ],
        ]);
    }
}
