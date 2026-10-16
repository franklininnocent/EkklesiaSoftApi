<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SupportAccess\Services\SupportGrantService;
use Modules\Tenants\Support\TenantContext;
use RuntimeException;

/**
 * Parish self-serve access windows for the effective tenant only.
 */
class TenantSupportGrantController extends Controller
{
    public function __construct(
        private readonly SupportGrantService $grants,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->requireEffectiveTenantId();
        $filters = $request->validate([
            'status' => ['nullable', 'in:active,revoked,expired'],
        ]);
        $filters['tenant_id'] = $tenantId;
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->grants->list($filters, $perPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->requireEffectiveTenantId();
        $payload = $request->validate([
            'allowed_mode' => ['required', 'in:readonly,standard,emergency,any'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:2000'],
            'max_sessions' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $row = $this->grants->createForOwnTenant($request->user(), $tenantId, $payload);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support access window created.',
            'data' => $row,
        ], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->requireEffectiveTenantId();

        try {
            $row = $this->grants->revokeForOwnTenant($request->user(), $tenantId, $id);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support access window revoked.',
            'data' => $row,
        ]);
    }
}
