<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\GenerateProjectInstallmentsRequest;
use Modules\Donations\Http\Requests\StoreProjectRequest;
use Modules\Donations\Http\Requests\UpdateProjectRequest;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Services\DonationProjectService;
use Modules\Donations\Services\ProjectInstallmentDueService;

class DonationProjectsController extends Controller
{
    public function __construct(
        private readonly DonationProjectService $projectService,
        private readonly ProjectInstallmentDueService $installmentService
    ) {
    }

    public function index(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;

        $projects = DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'project')
            ->with('fund')
            ->withCount(['assignments', 'installmentDues'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DonationProject $project) use ($tenantId): array {
                $dashboard = $this->projectService->getDashboard($tenantId, $project);

                return array_merge($project->toArray(), [
                    'collection_percentage' => $dashboard['totals']['collection_percentage'],
                    'families_enrolled' => $dashboard['families']['enrolled'],
                ]);
            });

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;

        $project = DonationProject::forTenant($tenantId)
            ->with(['fund', 'assignments.family'])
            ->withCount(['assignments', 'installmentDues'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    public function dashboard(string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->projectService->getDashboard($tenantId, $project),
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();

        $project = $this->projectService->create($tenantId, $userId, $request->validated());

        if ($project->auto_generate_installments && $project->status === 'active') {
            $this->installmentService->generateForProject($tenantId, $userId, $project);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project created successfully.',
            'data' => $project->fresh(['fund', 'assignments.family']),
        ], 201);
    }

    public function update(string $id, UpdateProjectRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);

        $project = $this->projectService->update($tenantId, $userId, $project, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully.',
            'data' => $project,
        ]);
    }

    public function generateInstallments(string $id, GenerateProjectInstallmentsRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);

        $dues = $this->installmentService->generateForProject(
            $tenantId,
            $userId,
            $project,
            $request->validated()['family_ids'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Project installment dues generated successfully.',
            'data' => $dues,
        ]);
    }
}
