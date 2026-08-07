<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Http\Requests\IndexTaxonomyRequest;
use Modules\MinistriesAssociations\Http\Requests\StoreOrganizationTypeRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateOrganizationTypeRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateTaxonomyStatusRequest;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class OrganizationTypeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function index(IndexTaxonomyRequest $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationType::class);

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $query = OrganizationType::query()
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

    public function store(StoreOrganizationTypeRequest $request): JsonResponse
    {
        $this->authorize('create', OrganizationType::class);

        $tenantId = $this->tenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['is_system'] = false;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $type = OrganizationType::create($payload);

        $this->auditService->log(
            $tenantId,
            'type.created',
            'organization_type',
            $type->id,
            null,
            $this->auditSnapshot($type),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization type created successfully.',
            'data' => $type,
        ], 201);
    }

    public function update(UpdateOrganizationTypeRequest $request, string $typeId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $type = $this->findType($tenantId, $typeId);

        $this->authorize('update', $type);

        if ($type->is_system && $request->filled('code') && $request->input('code') !== $type->code) {
            return response()->json([
                'success' => false,
                'message' => 'System type code cannot be changed.',
                'errors' => [
                    'code' => ['System type code cannot be changed.'],
                ],
            ], 422);
        }

        if ($request->has('is_active') && $request->boolean('is_active') === false) {
            $blocking = $this->activeOrganizationConflict($tenantId, $type->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($type);
        $type->update($request->validated());
        $type->refresh();

        $this->auditService->log(
            $tenantId,
            'type.updated',
            'organization_type',
            $type->id,
            $oldValues,
            $this->auditSnapshot($type),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization type updated successfully.',
            'data' => $type,
        ]);
    }

    public function updateStatus(UpdateTaxonomyStatusRequest $request, string $typeId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $type = $this->findType($tenantId, $typeId);

        $this->authorize('updateStatus', $type);

        $isActive = $request->boolean('is_active');

        if (! $isActive) {
            $blocking = $this->activeOrganizationConflict($tenantId, $type->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($type);
        $type->update(['is_active' => $isActive]);
        $type->refresh();

        $this->auditService->log(
            $tenantId,
            'type.status_changed',
            'organization_type',
            $type->id,
            $oldValues,
            $this->auditSnapshot($type),
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization type status updated successfully.',
            'data' => $type,
        ]);
    }

    public function seedDefaults(): JsonResponse
    {
        $this->authorize('seedDefaults', OrganizationType::class);

        $tenantId = $this->tenantId();
        $displayOrder = 0;

        foreach ($this->defaultTypes() as $defaults) {
            OrganizationType::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $defaults['code'],
                ],
                [
                    'name' => $defaults['name'],
                    'description' => $defaults['description'] ?? null,
                    'is_system' => true,
                    'is_active' => true,
                    'display_order' => $displayOrder,
                ]
            );

            $displayOrder++;
        }

        $types = OrganizationType::query()
            ->forTenant($tenantId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $this->auditService->log(
            $tenantId,
            'type.defaults_seeded',
            'organization_type',
            (string) $tenantId,
            null,
            ['count' => $types->count()],
        );

        return response()->json([
            'success' => true,
            'message' => 'Default organization types seeded successfully.',
            'data' => $types,
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findType(int $tenantId, string $typeId): OrganizationType
    {
        return OrganizationType::query()
            ->forTenant($tenantId)
            ->findOrFail($typeId);
    }

    private function activeOrganizationConflict(int $tenantId, string $typeId): ?JsonResponse
    {
        $hasActiveOrganizations = Organization::query()
            ->forTenant($tenantId)
            ->where('type_id', $typeId)
            ->where('status', Organization::STATUS_ACTIVE)
            ->exists();

        if (! $hasActiveOrganizations) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Cannot deactivate type while active organizations reference it.',
            'errors' => [
                'is_active' => ['Active organizations are assigned to this type.'],
            ],
        ], 422);
    }

    /**
     * @return list<array{code: string, name: string, description?: string}>
     */
    private function defaultTypes(): array
    {
        return [
            ['code' => 'ministry', 'name' => 'Ministry'],
            ['code' => 'association', 'name' => 'Association'],
            ['code' => 'society', 'name' => 'Society'],
            ['code' => 'fellowship', 'name' => 'Fellowship'],
            ['code' => 'committee', 'name' => 'Committee'],
            ['code' => 'choir', 'name' => 'Choir'],
            ['code' => 'prayer_group', 'name' => 'Prayer Group'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OrganizationType $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'is_active' => $type->is_active,
            'is_system' => $type->is_system,
            'display_order' => $type->display_order,
        ];
    }
}
