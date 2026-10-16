<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MassIntentions\Services\MassIntentionSchedulingService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionObligationController extends Controller
{
    public function __construct(
        private readonly MassIntentionSchedulingService $scheduling,
    ) {
    }

    public function pendingSchedule(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->scheduling->listPendingForScheduling($this->tenantId()),
        ]);
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
