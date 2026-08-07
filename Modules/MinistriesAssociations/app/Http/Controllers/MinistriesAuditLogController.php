<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Http\Requests\IndexMinistriesAuditLogRequest;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;

class MinistriesAuditLogController extends Controller
{
    use AuthorizesRequests;

    public function index(IndexMinistriesAuditLogRequest $request): JsonResponse
    {
        $this->authorize('viewAny', MinistriesAuditLog::class);

        return $this->paginatedResponse(
            $this->buildQuery($this->tenantId(), $request->validated()),
            (int) ($request->validated()['per_page'] ?? 15),
            $this->tenantId(),
        );
    }

    public function organizationIndex(
        IndexMinistriesAuditLogRequest $request,
        string $organizationId,
    ): JsonResponse {
        $this->authorize('viewAny', MinistriesAuditLog::class);

        $tenantId = $this->tenantId();
        Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);

        return $this->paginatedResponse(
            $this->buildQuery($tenantId, $request->validated(), $organizationId),
            (int) ($request->validated()['per_page'] ?? 15),
            $tenantId,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildQuery(int $tenantId, array $validated, ?string $organizationId = null)
    {
        $query = MinistriesAuditLog::query()
            ->forTenant($tenantId)
            ->orderByDesc('created_at');

        if ($organizationId !== null) {
            $query->forOrganization($organizationId);
        }

        if (! empty($validated['entity_type'])) {
            $query->where('target_type', $validated['entity_type']);
        }

        if (! empty($validated['entity_id'])) {
            $query->where('target_id', $validated['entity_id']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('actor_user_id', (int) $validated['user_id']);
        }

        if (! empty($validated['action_type'])) {
            $query->where('event', $validated['action_type']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        return $query;
    }

    private function paginatedResponse($query, int $perPage, int $tenantId): JsonResponse
    {
        $paginator = $query->paginate($perPage);

        $actorIds = collect($paginator->items())
            ->pluck('actor_user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $actorNames = $actorIds === []
            ? []
            : User::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $actorIds)
                ->pluck('name', 'id')
                ->all();

        $items = collect($paginator->items())
            ->map(fn (MinistriesAuditLog $log) => $this->presentAuditLog($log, $actorNames))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
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

    /**
     * @param  array<int|string, string|null>  $actorNames
     * @return array<string, mixed>
     */
    private function presentAuditLog(MinistriesAuditLog $log, array $actorNames): array
    {
        return [
            'id' => $log->id,
            'event' => $log->event,
            'action_type' => $log->event,
            'target_type' => $log->target_type,
            'entity_type' => $log->target_type,
            'target_id' => $log->target_id,
            'entity_id' => $log->target_id,
            'organization_id' => $log->organization_id,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor_user_id ? ($actorNames[$log->actor_user_id] ?? null) : null,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }
}
