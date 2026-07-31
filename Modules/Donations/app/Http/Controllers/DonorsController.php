<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreDonorRequest;
use Modules\Donations\Http\Requests\UpdateDonorRequest;
use Modules\Donations\Models\Donor;
use Modules\Donations\Services\DonationAuditService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

class DonorsController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $query = Donor::forTenant($tenantId)->orderBy('name');

        if ($request->filled('donor_type')) {
            $query->where('donor_type', $request->string('donor_type'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search): void {
                $term = '%'.$search.'%';
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        $donors = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $donors,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $donor = Donor::forTenant($tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $donor,
        ]);
    }

    public function store(StoreDonorRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;

        if (!empty($payload['family_id'])) {
            $familyExists = Family::query()
                ->where('id', $payload['family_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (!$familyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family for tenant.',
                ], 422);
            }
        }

        if (!empty($payload['family_member_id'])) {
            $memberExists = FamilyMember::query()
                ->where('id', $payload['family_member_id'])
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->exists();
            if (!$memberExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family member for tenant.',
                ], 422);
            }
        }

        $donor = Donor::create($payload);
        $this->auditService->log($tenantId, 'donor.created', 'donor', $donor->id, null, $donor->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donor created.',
            'data' => $donor,
        ], 201);
    }

    public function update(UpdateDonorRequest $request, string $id): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $donor = Donor::forTenant($tenantId)->findOrFail($id);
        $oldValues = $donor->toArray();
        $payload = $request->validated();
        $payload['updated_by'] = $userId;
        $donor->update($payload);

        $this->auditService->log($tenantId, 'donor.updated', 'donor', $donor->id, $oldValues, $donor->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donor updated.',
            'data' => $donor,
        ]);
    }
}
