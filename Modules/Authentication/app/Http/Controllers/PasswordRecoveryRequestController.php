<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Authentication\Http\Requests\RejectPasswordRecoveryRequestRequest;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordRecoveryAuthorizationService;
use Modules\Authentication\Services\PasswordRecoveryRequestService;
use RuntimeException;

class PasswordRecoveryRequestController extends Controller
{
    public function __construct(
        private readonly PasswordRecoveryRequestService $requests,
        private readonly PasswordRecoveryAuthorizationService $authorization,
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        if (! $this->authorization->canViewRequests($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view password recovery requests.',
            ], 403);
        }

        $paginator = $this->requests->listForActor($actor, [
            'status' => request()->query('status'),
            'email' => request()->query('email'),
            'tenant_id' => request()->query('tenant_id'),
            'date_from' => request()->query('date_from'),
            'date_to' => request()->query('date_to'),
        ], (int) request()->query('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Password recovery requests retrieved.',
            'data' => $paginator->getCollection()->map(fn (PasswordRecoveryRequest $item) => $this->serializeRequest($item, $actor)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        try {
            $request = $this->requests->showForActor($actor, $id);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password recovery request retrieved.',
            'data' => $this->serializeRequest($request, $actor, detailed: true),
        ]);
    }

    public function approve(string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        try {
            $request = $this->requests->approve($actor, $id);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'A temporary password has been sent to the user\'s registered email address.',
            'data' => [
                'status' => $request->status,
            ],
        ]);
    }

    public function reject(RejectPasswordRecoveryRequestRequest $formRequest, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $formRequest->user();

        try {
            $request = $this->requests->reject($actor, $id, $formRequest->input('reason'));
        } catch (RuntimeException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password recovery request rejected.',
            'data' => [
                'status' => $request->status,
            ],
        ]);
    }

    public function retryDelivery(string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        try {
            $request = $this->requests->retryDelivery($actor, $id);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'A temporary password has been sent to the user\'s registered email address.',
            'data' => [
                'status' => $request->status,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRequest(PasswordRecoveryRequest $request, User $actor, bool $detailed = false): array
    {
        $user = $request->user;
        $tenant = $request->tenant;

        $payload = [
            'id' => $request->id,
            'status' => $request->status,
            'requester_name' => $user?->name,
            'requester_email' => $request->requester_email,
            'requester_role' => $user?->role?->name,
            'requester_classification' => $request->requester_classification,
            'tenant_name' => $tenant?->name,
            'tenant_id' => $request->tenant_id,
            'requested_at' => $request->created_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'can_approve' => $this->authorization->canApprove($actor, $request),
            'can_reject' => $this->authorization->canReject($actor, $request),
            'can_retry_delivery' => $this->authorization->canRetryDelivery($actor, $request),
        ];

        if ($detailed) {
            $payload['request_ip'] = $request->request_ip;
            $payload['user_agent'] = $request->user_agent;
            $payload['rejection_reason'] = $request->rejection_reason;
            $payload['failure_reason'] = $request->failure_reason;
            $payload['processed_at'] = $request->processed_at?->toIso8601String();
            $payload['completed_at'] = $request->completed_at?->toIso8601String();
            $payload['user_active'] = $user ? (bool) $user->active : null;
        }

        return $payload;
    }

    private function errorResponse(RuntimeException $e): JsonResponse
    {
        $status = in_array($e->getCode(), [403, 404, 422], true) ? (int) $e->getCode() : 422;

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $status);
    }
}
