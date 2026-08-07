<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\DecideApprovalRequest;
use Modules\Donations\Models\DonationApproval;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Services\DonationAuditService;

class DonationApprovalsController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
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
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        $approval = DonationApproval::forTenant($tenantId)->findOrFail($id);
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
                ->first();

            if ($refund) {
                $refund->status = $decision === 'approved' ? 'completed' : 'rejected';
                $refund->updated_by = $userId;
                $refund->save();
            }
        }

        $this->auditService->log(
            $tenantId,
            'approval.decided',
            'approval',
            $approval->id,
            $oldValues,
            $approval->toArray(),
            ['decision_note' => $request->input('note')]
        );

        return response()->json([
            'success' => true,
            'message' => 'Approval decision saved.',
            'data' => $approval,
        ]);
    }
}
