<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Controllers\Concerns\HandlesDonationIdempotency;
use Modules\Donations\Http\Requests\DecideApprovalRequest;
use Modules\Donations\Models\DonationApproval;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Services\DonationIdempotencyService;
use Modules\Donations\Services\DonationLedgerService;
use Modules\Tenants\Support\TenantContext;

class DonationApprovalsController extends Controller
{
    use HandlesDonationIdempotency;

    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationLedgerService $ledgerService,
        private readonly DonationIdempotencyService $idempotency
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $query = DonationApproval::forTenant($tenantId)->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    public function decide(string $id, DecideApprovalRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        return $this->withIdempotency($this->idempotency, $tenantId, 'approval.decide', $request, function () use ($tenantId, $userId, $id, $request) {
            $approval = DonationApproval::forTenant($tenantId)->lockForUpdate()->findOrFail($id);
            if (in_array($approval->status, ['approved', 'rejected'], true)) {
                throw new \RuntimeException('This approval has already been decided.');
            }

            $oldValues = $approval->toArray();
            $decision = $request->string('decision')->toString();

            $approval->status = $decision;
            $approval->decided_by = $userId;
            $approval->decided_at = now();
            $approval->updated_by = $userId;
            $approval->save();

            if ($approval->action === 'refund') {
                $refund = DonationRefund::forTenant($tenantId)
                    ->where('approval_id', $approval->id)
                    ->lockForUpdate()
                    ->first();

                if ($refund) {
                    if ($decision === 'approved') {
                        $this->ledgerService->completeApprovedRefund($tenantId, $userId, $refund);
                    } else {
                        $refund->status = 'rejected';
                        $refund->updated_by = $userId;
                        $refund->save();
                    }
                }
            }

            $this->auditService->log(
                $tenantId,
                'approval.decided',
                'approval',
                $approval->id,
                $oldValues,
                $approval->fresh()->toArray(),
                ['decision_note' => $request->input('note')]
            );

            return [
                'status' => 200,
                'message' => 'Approval decision saved.',
                'data' => $approval->fresh(),
                'resource_type' => 'approval',
                'resource_id' => $approval->id,
            ];
        });
    }
}
