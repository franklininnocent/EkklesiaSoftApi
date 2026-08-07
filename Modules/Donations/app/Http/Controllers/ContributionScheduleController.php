<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\ContributionDueService;

class ContributionScheduleController extends Controller
{
    public function __construct(private readonly ContributionDueService $dueService)
    {
    }

    public function generateScheduled(): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        $result = $this->dueService->generateScheduledDuesForTenant($tenantId, $userId);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled contribution dues generated.',
            'data' => $result,
        ]);
    }
}
