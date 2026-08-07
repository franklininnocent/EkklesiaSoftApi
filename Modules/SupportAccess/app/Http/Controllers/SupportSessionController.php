<?php

namespace Modules\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SupportAccess\Services\SupportSessionService;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportSessionController extends Controller
{
    public function __construct(
        private readonly SupportSessionService $sessions,
    ) {
    }

    public function active(Request $request): JsonResponse
    {
        $session = $this->sessions->activeForActor($request->user());

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    public function monitor(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        return response()->json([
            'success' => true,
            'data' => $this->sessions->listActiveGlobal($perPage),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        // Platform history (filters) when any history filter present or `scope=all`.
        if ($request->query('scope') === 'all' || $request->hasAny([
            'status', 'mode', 'tenant_id', 'support_user_id', 'from', 'to', 'q',
        ])) {
            $filters = $request->validate([
                'status' => ['nullable', 'in:active,ended,expired'],
                'mode' => ['nullable', 'in:readonly,standard,emergency'],
                'tenant_id' => ['nullable', 'integer'],
                'support_user_id' => ['nullable', 'integer'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'q' => ['nullable', 'string', 'max:100'],
                'scope' => ['nullable', 'in:all,mine'],
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->sessions->searchHistory($filters, $perPage),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessions->listForActor($request->user(), $perPage),
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:active,ended,expired'],
            'mode' => ['nullable', 'in:readonly,standard,emergency'],
            'tenant_id' => ['nullable', 'integer'],
            'support_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->sessions->exportHistoryCsv($filters);
    }

    public function show(Request $request, string $sessionId): JsonResponse
    {
        try {
            $session = $this->sessions->findForActor($request->user(), $sessionId);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'mode' => ['required', 'in:readonly,standard,emergency'],
            'reason_code' => ['required', 'in:'.implode(',', SupportSessionService::REASON_CODES)],
            'reason_description' => ['nullable', 'string', 'max:2000'],
            'ticket_ref' => ['nullable', 'string', 'max:128'],
            'password' => ['required', 'string'],
            'confirm_emergency' => ['sometimes', 'boolean'],
            'approval_request_id' => ['nullable', 'uuid', 'exists:support_access_requests,id'],
        ]);

        try {
            $session = $this->sessions->start(
                $request->user(),
                $payload,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            $message = strtolower($e->getMessage());
            $status = 403;
            if (str_contains($message, 'password')
                || str_contains($message, 'emergency')
                || str_contains($message, 'approval')
                || str_contains($message, 'grant')
                || str_contains($message, 'access window')
                || str_contains($message, 'ticket')) {
                $status = 422;
            } elseif (str_contains($message, 'rate limit') || str_contains($message, 'concurrent')) {
                $status = 429;
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session started.',
            'data' => $session,
        ], 201);
    }

    public function end(Request $request, string $sessionId): JsonResponse
    {
        try {
            $session = $this->sessions->end($request->user(), $sessionId, 'manual');
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session ended.',
            'data' => $session,
        ]);
    }

    public function forceEnd(Request $request, string $sessionId): JsonResponse
    {
        try {
            $session = $this->sessions->end($request->user(), $sessionId, 'force_ended');
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session force-ended.',
            'data' => $session,
        ]);
    }

    public function renew(Request $request, string $sessionId): JsonResponse
    {
        $payload = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $session = $this->sessions->renew(
                $request->user(),
                $sessionId,
                $payload,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'password') ? 422 : 403;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session renewed.',
            'data' => $session,
        ]);
    }

    public function metrics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->sessions->metrics(),
        ]);
    }

    public function recordEvent(Request $request, string $sessionId): JsonResponse
    {
        $payload = $request->validate([
            'event_type' => ['nullable', 'string', 'max:64'],
            'module' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'string', 'max:128'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $event = $this->sessions->recordEvent($request->user(), $sessionId, $payload);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $event,
        ], 201);
    }
}
