<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SupportAccess\Services\SupportGrantService;
use RuntimeException;

class SupportGrantController extends Controller
{
    public function __construct(
        private readonly SupportGrantService $grants,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:active,revoked,expired'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->grants->list($filters, $perPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'allowed_mode' => ['required', 'in:readonly,standard,emergency,any'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:2000'],
            'max_sessions' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $row = $this->grants->create($request->user(), $payload);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support access grant created.',
            'data' => $row,
        ], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        try {
            $row = $this->grants->revoke($request->user(), $id);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support access grant revoked.',
            'data' => $row,
        ]);
    }
}
