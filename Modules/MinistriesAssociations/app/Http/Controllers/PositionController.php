<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Http\Requests\IndexTaxonomyRequest;
use Modules\MinistriesAssociations\Http\Requests\StorePositionRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdatePositionRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateTaxonomyStatusRequest;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Position;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class PositionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function index(IndexTaxonomyRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Position::class);

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $query = Position::query()
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

    public function store(StorePositionRequest $request): JsonResponse
    {
        $this->authorize('create', Position::class);

        $tenantId = $this->tenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['is_system'] = false;
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['single_occupancy'] = $payload['single_occupancy'] ?? true;

        $position = Position::create($payload);

        $this->auditService->log(
            $tenantId,
            'position.created',
            'position',
            $position->id,
            null,
            $this->auditSnapshot($position),
        );

        return response()->json([
            'success' => true,
            'message' => 'Position created successfully.',
            'data' => $position,
        ], 201);
    }

    public function update(UpdatePositionRequest $request, string $positionId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $position = $this->findPosition($tenantId, $positionId);

        $this->authorize('update', $position);

        if ($position->is_system && $request->filled('code') && $request->input('code') !== $position->code) {
            return response()->json([
                'success' => false,
                'message' => 'System position code cannot be changed.',
                'errors' => [
                    'code' => ['System position code cannot be changed.'],
                ],
            ], 422);
        }

        if ($request->has('is_active') && $request->boolean('is_active') === false) {
            $blocking = $this->activeLeadershipConflict($tenantId, $position->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($position);
        $position->update($request->validated());
        $position->refresh();

        $this->auditService->log(
            $tenantId,
            'position.updated',
            'position',
            $position->id,
            $oldValues,
            $this->auditSnapshot($position),
        );

        return response()->json([
            'success' => true,
            'message' => 'Position updated successfully.',
            'data' => $position,
        ]);
    }

    public function updateStatus(UpdateTaxonomyStatusRequest $request, string $positionId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $position = $this->findPosition($tenantId, $positionId);

        $this->authorize('updateStatus', $position);

        $isActive = $request->boolean('is_active');

        if (! $isActive) {
            $blocking = $this->activeLeadershipConflict($tenantId, $position->id);
            if ($blocking !== null) {
                return $blocking;
            }
        }

        $oldValues = $this->auditSnapshot($position);
        $position->update(['is_active' => $isActive]);
        $position->refresh();

        $this->auditService->log(
            $tenantId,
            'position.status_changed',
            'position',
            $position->id,
            $oldValues,
            $this->auditSnapshot($position),
        );

        return response()->json([
            'success' => true,
            'message' => 'Position status updated successfully.',
            'data' => $position,
        ]);
    }

    public function seedDefaults(): JsonResponse
    {
        $this->authorize('seedDefaults', Position::class);

        $tenantId = $this->tenantId();
        $displayOrder = 0;

        foreach ($this->defaultPositions() as $defaults) {
            Position::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $defaults['code'],
                ],
                [
                    'name' => $defaults['name'],
                    'single_occupancy' => $defaults['single_occupancy'],
                    'is_system' => true,
                    'is_active' => true,
                    'display_order' => $displayOrder,
                ]
            );

            $displayOrder++;
        }

        $positions = Position::query()
            ->forTenant($tenantId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $this->auditService->log(
            $tenantId,
            'position.defaults_seeded',
            'position',
            (string) $tenantId,
            null,
            ['count' => $positions->count()],
        );

        return response()->json([
            'success' => true,
            'message' => 'Default positions seeded successfully.',
            'data' => $positions,
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findPosition(int $tenantId, string $positionId): Position
    {
        return Position::query()
            ->forTenant($tenantId)
            ->findOrFail($positionId);
    }

    private function activeLeadershipConflict(int $tenantId, string $positionId): ?JsonResponse
    {
        $hasActiveLeadership = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('position_id', $positionId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->exists();

        if (! $hasActiveLeadership) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Cannot deactivate position while active leadership terms reference it.',
            'errors' => [
                'is_active' => ['Active leadership terms are assigned to this position.'],
            ],
        ], 422);
    }

    /**
     * @return list<array{code: string, name: string, single_occupancy: bool}>
     */
    private function defaultPositions(): array
    {
        return [
            ['code' => 'president', 'name' => 'President', 'single_occupancy' => true],
            ['code' => 'vice_president', 'name' => 'Vice President', 'single_occupancy' => true],
            ['code' => 'secretary', 'name' => 'Secretary', 'single_occupancy' => true],
            ['code' => 'joint_secretary', 'name' => 'Joint Secretary', 'single_occupancy' => true],
            ['code' => 'treasurer', 'name' => 'Treasurer', 'single_occupancy' => true],
            ['code' => 'coordinator', 'name' => 'Coordinator', 'single_occupancy' => true],
            ['code' => 'committee_member', 'name' => 'Committee Member', 'single_occupancy' => false],
            ['code' => 'member', 'name' => 'Member', 'single_occupancy' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Position $position): array
    {
        return [
            'id' => $position->id,
            'code' => $position->code,
            'name' => $position->name,
            'single_occupancy' => $position->single_occupancy,
            'is_active' => $position->is_active,
            'is_system' => $position->is_system,
            'display_order' => $position->display_order,
        ];
    }
}
