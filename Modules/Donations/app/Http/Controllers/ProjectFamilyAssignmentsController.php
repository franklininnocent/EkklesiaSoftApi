<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreProjectAssignmentRequest;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Services\DonationProjectService;

class ProjectFamilyAssignmentsController extends Controller
{
    public function __construct(private readonly DonationProjectService $projectService)
    {
    }

    public function index(string $projectId, Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        DonationProject::forTenant($tenantId)->findOrFail($projectId);

        $assignments = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->with('family')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('effective_from')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    public function store(string $projectId, StoreProjectAssignmentRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $project = DonationProject::forTenant($tenantId)->findOrFail($projectId);
        $payload = $request->validated();

        $this->projectService->syncAssignments($tenantId, $userId, $project, [$payload]);

        $assignment = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $payload['family_id'])
            ->whereDate('effective_from', $payload['effective_from'])
            ->with('family')
            ->latest('created_at')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Project family assignment saved successfully.',
            'data' => $assignment,
        ], 201);
    }
}
