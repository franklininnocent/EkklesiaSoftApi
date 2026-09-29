<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SupportAccess\Services\SupportApprovalService;
use Modules\SupportAccess\Services\SupportSessionService;
use RuntimeException;

class SupportApprovalController extends Controller
{
    public function __construct(
        private readonly SupportApprovalService $approvals,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,consumed,expired,cancelled'],
            'tenant_id' => ['nullable', 'integer'],
            'requester_user_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('mine')) {
            $filters['mine_for'] = (int) $request->user()->getAuthIdentifier();
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->approvals->list($filters, $perPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'mode' => ['nullable', 'in:emergency'],
            'reason_code' => ['required', 'in:'.implode(',', SupportSessionService::REASON_CODES)],
            'reason_description' => ['nullable', 'string', 'max:2000'],
            'ticket_ref' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $row = $this->approvals->request($request->user(), $payload);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Emergency approval requested.',
            'data' => $row,
        ], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $row = $this->approvals->approve($request->user(), $id, $payload['decision_note'] ?? null);
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'four-eyes') ? 403 : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Emergency request approved.',
            'data' => $row,
        ]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $row = $this->approvals->reject($request->user(), $id, $payload['decision_note'] ?? null);
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'four-eyes') ? 403 : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Emergency request rejected.',
            'data' => $row,
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        try {
            $row = $this->approvals->cancel($request->user(), $id);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Emergency request cancelled.',
            'data' => $row,
        ]);
    }
}
