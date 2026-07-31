<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\UpdateDueStatusRequest;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Services\ProjectInstallmentDueService;
use Modules\Donations\Support\ContributionBalance;

class ProjectInstallmentDuesController extends Controller
{
    public function __construct(private readonly ProjectInstallmentDueService $installmentService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;

        $query = ProjectInstallmentDue::forTenant($tenantId)->with(['family', 'project']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->string('project_id'));
        }
        if ($request->filled('family_id')) {
            $query->where('family_id', $request->string('family_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->boolean('overdue_only')) {
            $query->whereDate('due_date', '<', now()->toDateString())
                ->whereIn('status', ['pending', 'partially_paid']);
        }

        $paginator = $query->orderBy('due_date')->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(function (ProjectInstallmentDue $due): ProjectInstallmentDue {
            $due->setAttribute('outstanding_amount', ContributionBalance::outstandingForDue($due));

            return $due;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function waive(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $due = ProjectInstallmentDue::forTenant($tenantId)->findOrFail($id);

        if (!in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid installments can be waived.',
            ], 422);
        }

        $due = $this->installmentService->waiveDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Installment waived successfully.',
            'data' => $due,
        ]);
    }

    public function cancel(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $due = ProjectInstallmentDue::forTenant($tenantId)->findOrFail($id);

        if (!in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid installments can be cancelled.',
            ], 422);
        }

        $due = $this->installmentService->cancelDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Installment cancelled successfully.',
            'data' => $due,
        ]);
    }
}
