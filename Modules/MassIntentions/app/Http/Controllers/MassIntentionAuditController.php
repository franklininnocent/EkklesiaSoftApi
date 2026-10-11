<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\MassIntentions\Models\MassIntentionAudit;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $perPage = min(100, max(1, (int) $request->input('per_page', 30)));

        $query = MassIntentionAudit::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($requestId = $request->input('request_id')) {
            $query->where('request_id', $requestId);
        }

        if ($category = $request->input('event_category')) {
            $prefixes = match ((string) $category) {
                'intention' => ['request.', 'intention.', 'receipt.'],
                'mass' => ['celebration.'],
                'schedule' => ['schedule.', 'day_override.'],
                'transfer' => ['transfer.'],
                default => [],
            };
            if ($prefixes !== []) {
                $query->where(function ($builder) use ($prefixes) {
                    foreach ($prefixes as $index => $prefix) {
                        if ($index === 0) {
                            $builder->where('event_type', 'like', $prefix.'%');
                        } else {
                            $builder->orWhere('event_type', 'like', $prefix.'%');
                        }
                    }
                });
            }
        }

        if ($eventType = $request->input('event_type')) {
            $escaped = addcslashes((string) $eventType, '%_\\');
            $query->where('event_type', 'like', '%'.$escaped.'%');
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (MassIntentionAudit $row) => [
                'id' => $row->id,
                'event_type' => $row->event_type,
                'request_id' => $row->request_id,
                'celebration_id' => $row->celebration_id,
                'actor_user_id' => $row->actor_user_id,
                'payload' => $row->payload,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return (int) $tenantId;
    }
}
