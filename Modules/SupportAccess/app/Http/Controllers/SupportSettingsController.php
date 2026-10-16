<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\SupportAccess\Services\SupportSettingsService;

class SupportSettingsController extends Controller
{
    public function __construct(
        private readonly SupportSettingsService $settings,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settings->getOpsSettings(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'timeout_minutes' => ['sometimes', 'integer', 'in:15,30,60,120'],
            'max_concurrent_sessions' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'max_sessions_per_user' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'start_rate_limit_per_hour' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'notification_mode' => ['sometimes', 'in:never,immediate,digest'],
            'customer_disclosure_enabled' => ['sometimes', 'boolean'],
            'emergency_requires_approval' => ['sometimes', 'boolean'],
            'require_customer_grant' => ['sometimes', 'boolean'],
            'jit_enabled' => ['sometimes', 'boolean'],
            'jit_timeout_minutes' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'approval_request_ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'require_ticket_ref' => ['sometimes', 'boolean'],
            'ticket_validation_mode' => ['sometimes', 'in:off,required_format,adapter'],
            'ip_binding_mode' => ['sometimes', 'in:off,soft,strict'],
        ]);

        try {
            $data = $this->settings->updateOpsSettings($payload);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support settings updated.',
            'data' => $data,
        ]);
    }
}
