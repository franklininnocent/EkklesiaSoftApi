<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Controllers\Concerns\HandlesDonationIdempotency;
use Modules\Donations\Http\Requests\CollectVoluntaryDonationRequest;
use Modules\Donations\Http\Requests\StoreDonationRequest;
use Modules\Donations\Http\Requests\UpdateDonationRequest;
use Modules\Donations\Models\Donation;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Services\DonationIdempotencyService;
use Modules\Donations\Services\VoluntaryDonationService;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Support\TenantContext;

class DonationsController extends Controller
{
    use HandlesDonationIdempotency;

    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly VoluntaryDonationService $voluntaryService,
        private readonly DonationIdempotencyService $idempotency
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $query = Donation::forTenant($tenantId)->with(['donor', 'category'])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('donation_category_id')) {
            $query->where('donation_category_id', $request->string('donation_category_id'));
        }

        if ($request->filled('family_id')) {
            $query->where('family_id', $request->string('family_id'));
        }

        if ($request->boolean('anonymous_only')) {
            $query->where('is_anonymous', true);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search): void {
                $term = '%'.$search.'%';
                $q->where('title', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        $donations = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $donations,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $donation = Donation::forTenant($tenantId)->with(['donor', 'category'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $donation,
        ]);
    }

    public function store(StoreDonationRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['collected_amount'] = 0;
        $payload['status'] = 'pledged';
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;

        if (! empty($payload['family_id'])) {
            $familyExists = Family::query()
                ->where('id', $payload['family_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (! $familyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family for tenant.',
                ], 422);
            }
        }

        if (! empty($payload['family_member_id'])) {
            $memberExists = FamilyMember::query()
                ->where('id', $payload['family_member_id'])
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->exists();
            if (! $memberExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family member for tenant.',
                ], 422);
            }
        }

        $donation = Donation::create($payload);
        $this->auditService->log($tenantId, 'donation.created', 'donation', $donation->id, null, $donation->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation entry created.',
            'data' => $donation,
        ], 201);
    }

    public function update(UpdateDonationRequest $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $donation = Donation::forTenant($tenantId)->findOrFail($id);
        $oldValues = $donation->toArray();
        $payload = $request->validated();

        if (MoneyMath::isPositive($donation->collected_amount ?? 0)) {
            if (array_key_exists('pledged_amount', $payload) || array_key_exists('donor_id', $payload) || array_key_exists('family_id', $payload)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This donation already has collected money. Reverse or refund the payment instead of editing the historical record.',
                ], 422);
            }
        }

        $payload['updated_by'] = $userId;
        $donation->update($payload);

        $this->auditService->log($tenantId, 'donation.updated', 'donation', $donation->id, $oldValues, $donation->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation entry updated.',
            'data' => $donation->fresh(['donor', 'category']),
        ]);
    }

    public function collect(CollectVoluntaryDonationRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        return $this->withIdempotency($this->idempotency, $tenantId, 'donation.collect', $request, function () use ($tenantId, $userId, $request) {
            $result = $this->voluntaryService->collect($tenantId, $userId, $request->validated());

            return [
                'status' => 201,
                'message' => 'Voluntary donation collected.',
                'data' => $result,
                'resource_type' => 'payment',
                'resource_id' => $result['payment']->id,
            ];
        });
    }
}
