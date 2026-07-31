<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreCampaignRequest;
use Modules\Donations\Services\DonationCampaignService;
use Modules\Donations\Services\DonationProjectService;

class DonationCampaignsController extends Controller
{
    public function __construct(
        private readonly DonationCampaignService $campaignService,
        private readonly DonationProjectService $projectService
    ) {
    }

    public function index(): JsonResponse
    {
        $tenantId = (int) Auth::user()->tenant_id;

        return response()->json([
            'success' => true,
            'data' => $this->campaignService->list($tenantId),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->campaignService->find((int) Auth::user()->tenant_id, $id),
        ]);
    }

    public function dashboard(string $id): JsonResponse
    {
        $tenantId = (int) Auth::user()->tenant_id;
        $campaign = $this->campaignService->find($tenantId, $id);

        return response()->json([
            'success' => true,
            'data' => $this->projectService->getDashboard($tenantId, $campaign),
        ]);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $user = Auth::user();
        $campaign = $this->campaignService->create(
            (int) $user->tenant_id,
            (int) $user->id,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully.',
            'data' => $campaign,
        ], 201);
    }
}
