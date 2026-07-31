<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreFundRequest;
use Modules\Donations\Models\Fund;
use Modules\Donations\Services\DonationAuditService;

class FundsController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;

        $query = Fund::forTenant($tenantId)->orderBy('name');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(StoreFundRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;

        $fund = Fund::create($payload);

        $this->auditService->log($tenantId, 'fund.created', 'fund', $fund->id, null, $fund->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Fund created successfully.',
            'data' => $fund,
        ], 201);
    }
}
