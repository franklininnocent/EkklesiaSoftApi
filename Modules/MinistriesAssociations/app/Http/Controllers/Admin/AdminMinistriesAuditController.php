<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesAuditService;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;

class AdminMinistriesAuditController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesAuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'event' => ['sometimes', 'nullable', 'string', 'max:120'],
            'target_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'organization_id' => ['sometimes', 'nullable', 'uuid'],
            'actor_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'meaningful_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $payload = $this->audit->list($filters);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $payload['data'],
            'meta' => $payload['meta'],
            'window' => $payload['window'],
            'event_options' => $payload['event_options'],
            'governance' => $payload['governance'],
        ]);
    }
}
