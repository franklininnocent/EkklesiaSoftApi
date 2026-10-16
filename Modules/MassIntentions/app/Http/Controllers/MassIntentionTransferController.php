<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\InitiateMassIntentionTransferRequest;
use Modules\MassIntentions\Http\Requests\RejectMassIntentionTransferRequest;
use Modules\MassIntentions\Services\MassIntentionTransferService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionTransferController extends Controller
{
    public function __construct(
        private readonly MassIntentionTransferService $transfers,
    ) {
    }

    public function targets(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->transfers->listTransferTargets($this->tenantId()),
        ]);
    }

    public function pending(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->transfers->listPendingForTenant($this->tenantId()),
        ]);
    }

    public function initiate(InitiateMassIntentionTransferRequest $request, string $requestId): JsonResponse
    {
        $transfer = $this->transfers->initiate(
            $this->tenantId(),
            $this->actor(),
            $requestId,
            (int) $request->validated('to_tenant_id'),
            $request->validated('note')
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer sent to the other parish for acceptance.',
            'data' => [
                'id' => $transfer->id,
                'status' => $transfer->status,
            ],
        ], 201);
    }

    public function accept(string $transferId): JsonResponse
    {
        $transfer = $this->transfers->accept($this->tenantId(), $this->actor(), $transferId);

        return response()->json([
            'success' => true,
            'message' => 'Transfer accepted. The intention is now in your parish.',
            'data' => [
                'id' => $transfer->id,
                'request_id' => $transfer->request_id,
                'status' => $transfer->status,
            ],
        ]);
    }

    public function reject(RejectMassIntentionTransferRequest $request, string $transferId): JsonResponse
    {
        $transfer = $this->transfers->reject(
            $this->tenantId(),
            $this->actor(),
            $transferId,
            $request->validated('note')
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer declined. The intention stays with the sending parish.',
            'data' => [
                'id' => $transfer->id,
                'status' => $transfer->status,
            ],
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

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $user;
    }
}
