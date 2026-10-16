<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\WhatsAppDeliveryService;
use Modules\Donations\Services\WhatsAppOutreachService;

class WhatsAppOutreachController extends Controller
{
    public function __construct(
        private readonly WhatsAppOutreachService $outreachService,
        private readonly WhatsAppDeliveryService $deliveryService
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'family_ids' => ['nullable', 'array'],
            'family_ids.*' => ['uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->outreachService->preview(
                app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                $validated['family_ids'] ?? null,
                (int) ($validated['limit'] ?? 20)
            ),
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'family_ids' => ['required', 'array', 'min:1'],
            'family_ids.*' => ['uuid'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->outreachService->queue(
            app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
            $validated['family_ids'],
            $validated['message'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('%d WhatsApp outreach reminders queued.', $result['queued_count']),
            'data' => $result,
        ], 201);
    }

    public function deliverPending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->deliveryService->deliverPending(
            app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
            (int) ($validated['limit'] ?? 25)
        );

        return response()->json([
            'success' => true,
            'message' => sprintf('%d WhatsApp notifications processed.', $result['processed']),
            'data' => $result,
        ]);
    }

    public function deliverySummary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->deliveryService->summary(app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()),
        ]);
    }
}
