<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Services\MassIntentionsDashboardService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionsDashboardController extends Controller
{
    public function __construct(
        private readonly MassIntentionsDashboardService $dashboard
    ) {
    }

    public function home(): JsonResponse
    {
        $tenantId = $this->tenantId();

        return response()->json([
            'success' => true,
            'data' => $this->dashboard->homeSummary($tenantId, $this->canViewOfferings()),
        ]);
    }

    private function canViewOfferings(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->hasPermission('mass.intentions.offerings.view');
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return (int) $tenantId;
    }
}
