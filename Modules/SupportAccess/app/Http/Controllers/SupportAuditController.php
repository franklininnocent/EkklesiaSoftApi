<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SupportAccess\Services\SupportSessionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAuditController extends Controller
{
    public function __construct(
        private readonly SupportSessionService $sessions,
    ) {
    }

    public function events(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'support_session_id' => ['nullable', 'uuid'],
            'effective_tenant_id' => ['nullable', 'integer'],
            'actor_user_id' => ['nullable', 'integer'],
            'event_type' => ['nullable', 'string', 'max:64'],
            'module' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $this->sessions->searchEvents($filters, $perPage),
        ]);
    }

    public function exportEvents(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'support_session_id' => ['nullable', 'uuid'],
            'effective_tenant_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->sessions->exportEventsCsv($filters);
    }
}
