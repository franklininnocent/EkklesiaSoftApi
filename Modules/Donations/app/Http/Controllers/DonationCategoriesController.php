<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreDonationCategoryRequest;
use Modules\Donations\Http\Requests\UpdateDonationCategoryRequest;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationCategory;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Services\DonationAuditService;

class DonationCategoriesController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function index(): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $categories = DonationCategory::forTenant($tenantId)->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(StoreDonationCategoryRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;

        $category = DonationCategory::create($payload);
        $this->auditService->log($tenantId, 'category.created', 'donation_category', $category->id, null, $category->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation category created.',
            'data' => $category,
        ], 201);
    }

    public function seedDefaults(): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        (new \Modules\Donations\Database\Seeders\DonationCategorySeeder())->run($tenantId, $userId);

        $categories = DonationCategory::forTenant($tenantId)->orderBy('name')->get();
        $this->auditService->log($tenantId, 'category.defaults_seeded', 'donation_category', (string) $tenantId, null, ['count' => $categories->count()]);

        return response()->json([
            'success' => true,
            'message' => 'Default donation categories seeded.',
            'data' => $categories,
        ]);
    }

    public function update(UpdateDonationCategoryRequest $request, string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $category = DonationCategory::forTenant($tenantId)->findOrFail($id);
        $oldValues = $category->toArray();
        $payload = $request->validated();
        $payload['updated_by'] = $userId;
        $category->update($payload);

        $this->auditService->log($tenantId, 'category.updated', 'donation_category', $category->id, $oldValues, $category->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation category updated.',
            'data' => $category->fresh(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $category = DonationCategory::forTenant($tenantId)->findOrFail($id);

        $hasDonations = Donation::forTenant($tenantId)
            ->where('donation_category_id', $category->id)
            ->exists();

        $hasActiveSchedules = RecurringDonationSchedule::forTenant($tenantId)
            ->where('donation_category_id', $category->id)
            ->where('status', 'active')
            ->exists();

        if ($hasDonations || $hasActiveSchedules) {
            return response()->json([
                'success' => false,
                'message' => 'This category is in use. Deactivate it instead of deleting.',
            ], 422);
        }

        $oldValues = $category->toArray();
        $category->delete();

        $this->auditService->log($tenantId, 'category.deleted', 'donation_category', $category->id, $oldValues, null);

        return response()->json([
            'success' => true,
            'message' => 'Donation category deleted.',
        ]);
    }
}
