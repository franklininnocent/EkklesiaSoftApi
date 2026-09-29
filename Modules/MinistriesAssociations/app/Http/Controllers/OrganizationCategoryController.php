<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Http\Requests\IndexTaxonomyRequest;
use Modules\MinistriesAssociations\Http\Requests\StoreOrganizationCategoryRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateOrganizationCategoryRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateTaxonomyStatusRequest;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\DefaultSeeds\OrganizationCategoriesDefaultSeedDefinition;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class OrganizationCategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MinistriesAuditService $auditService,
        private readonly OrganizationCategoriesDefaultSeedDefinition $categoriesDefaultSeed,
    ) {
    }

    public function index(IndexTaxonomyRequest $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationCategory::class);

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $query = OrganizationCategory::query()
            ->forTenant($tenantId)
            ->orderBy('display_order')
            ->orderBy('name');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'ilike', $search)
                    ->orWhere('code', 'ilike', $search);
            });
        }

        if (array_key_exists('per_page', $validated)) {
            $paginator = $query->paginate((int) $validated['per_page']);

            return response()->json([
                'success' => true,
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(StoreOrganizationCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', OrganizationCategory::class);

        $tenantId = $this->tenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['is_system'] = false;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $category = OrganizationCategory::create($payload);

        $this->auditService->log(
            $tenantId,
            'category.created',
            'organization_category',
            $category->id,
            null,
            $this->auditSnapshot($category),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization category created successfully.',
            'data' => $category,
        ], 201);
    }

    public function update(UpdateOrganizationCategoryRequest $request, string $categoryId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $category = $this->findCategory($tenantId, $categoryId);

        $this->authorize('update', $category);

        if ($category->is_system && $request->filled('code') && $request->input('code') !== $category->code) {
            return response()->json([
                'success' => false,
                'message' => 'System category code cannot be changed.',
                'errors' => [
                    'code' => ['System category code cannot be changed.'],
                ],
            ], 422);
        }

        if ($request->has('is_active') && $request->boolean('is_active') === false) {
            $blocking = $this->activeOrganizationConflict($tenantId, $category->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($category);
        $category->update($request->validated());
        $category->refresh();

        $this->auditService->log(
            $tenantId,
            'category.updated',
            'organization_category',
            $category->id,
            $oldValues,
            $this->auditSnapshot($category),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization category updated successfully.',
            'data' => $category,
        ]);
    }

    public function updateStatus(UpdateTaxonomyStatusRequest $request, string $categoryId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $category = $this->findCategory($tenantId, $categoryId);

        $this->authorize('updateStatus', $category);

        $isActive = $request->boolean('is_active');

        if (! $isActive) {
            $blocking = $this->activeOrganizationConflict($tenantId, $category->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($category);
        $category->update(['is_active' => $isActive]);
        $category->refresh();

        $this->auditService->log(
            $tenantId,
            'category.status_changed',
            'organization_category',
            $category->id,
            $oldValues,
            $this->auditSnapshot($category),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization category status updated successfully.',
            'data' => $category,
        ]);
    }

    public function seedDefaults(): JsonResponse
    {
        $this->authorize('seedDefaults', OrganizationCategory::class);

        $tenantId = $this->tenantId();
        $userId = Auth::id() ? (int) Auth::id() : null;

        $this->categoriesDefaultSeed->execute($tenantId, $userId);

        $categories = OrganizationCategory::query()
            ->forTenant($tenantId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Default organization categories seeded successfully.',
            'data' => $categories,
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findCategory(int $tenantId, string $categoryId): OrganizationCategory
    {
        return OrganizationCategory::query()
            ->forTenant($tenantId)
            ->findOrFail($categoryId);
    }

    private function activeOrganizationConflict(int $tenantId, string $categoryId): ?JsonResponse
    {
        $hasActiveOrganizations = Organization::query()
            ->forTenant($tenantId)
            ->where('category_id', $categoryId)
            ->where('status', Organization::STATUS_ACTIVE)
            ->exists();

        if (! $hasActiveOrganizations) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Cannot deactivate category while active organizations reference it.',
            'errors' => [
                'is_active' => ['Active organizations are assigned to this category.'],
            ],
        ], 422);
    }

    /**
     * @return list<array{code: string, name: string, description?: string}>
     */
    private function defaultCategories(): array
    {
        return [
            ['code' => 'spiritual', 'name' => 'Spiritual'],
            ['code' => 'liturgical', 'name' => 'Liturgical'],
            ['code' => 'charitable', 'name' => 'Charitable'],
            ['code' => 'educational', 'name' => 'Educational'],
            ['code' => 'youth', 'name' => 'Youth'],
            ['code' => 'social', 'name' => 'Social'],
            ['code' => 'administrative', 'name' => 'Administrative'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OrganizationCategory $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'is_active' => $category->is_active,
            'is_system' => $category->is_system,
            'display_order' => $category->display_order,
        ];
    }
}
