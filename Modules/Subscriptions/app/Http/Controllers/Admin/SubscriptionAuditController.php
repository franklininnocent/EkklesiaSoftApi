<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\SubscriptionCatalogAudit;

class SubscriptionAuditController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity_type' => ['nullable', Rule::in(['plan', 'plan_version', 'feature', 'policy'])],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = SubscriptionCatalogAudit::query()
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($filters['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (SubscriptionCatalogAudit $a) => [
                'id' => $a->id,
                'entity_type' => $a->entity_type,
                'entity_id' => $a->entity_id,
                'operation' => $a->operation,
                'actor_id' => $a->actor_id,
                'actor_role' => $a->actor_role,
                'reason' => $a->reason,
                'before_state' => $a->before_state,
                'after_state' => $a->after_state,
                'correlation_id' => $a->correlation_id,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
