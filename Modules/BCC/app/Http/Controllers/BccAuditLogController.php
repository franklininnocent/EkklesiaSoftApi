<?php

namespace Modules\BCC\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Http\Controllers\Concerns\RespondsToBccDomain;
use Modules\BCC\Http\Requests\IndexBccAuditLogRequest;
use Modules\BCC\Models\BccAuditLog;
use Modules\BCC\Services\BccFamilyMembershipService;

class BccAuditLogController extends Controller
{
    use AuthorizesRequests;
    use RespondsToBccDomain;

    public function __construct(private readonly BccFamilyMembershipService $memberships)
    {
    }

    public function index(IndexBccAuditLogRequest $request): JsonResponse
    {
        $this->authorize('viewAny', BccAuditLog::class);

        return $this->respond($request->validated(), null);
    }

    public function organizationIndex(IndexBccAuditLogRequest $request, string $bccId): JsonResponse
    {
        $this->authorize('viewAny', BccAuditLog::class);

        try {
            $this->memberships->findBcc($this->tenantId(), $bccId);
        } catch (BccDomainException $e) {
            return $this->domainError($e);
        }

        return $this->respond($request->validated(), $bccId);
    }

    private function respond(array $filters, ?string $bccId): JsonResponse
    {
        $query = BccAuditLog::query()
            ->forTenant($this->tenantId())
            ->with(['actor:id,name,email', 'bcc:id,name,bcc_code'])
            ->orderByDesc('created_at');

        if ($bccId !== null) {
            $query->forBcc($bccId);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 15));

        $items = collect($paginator->items())->map(fn (BccAuditLog $log) => [
            'id' => $log->id,
            'event' => $log->event,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'bcc_id' => $log->bcc_id,
            'bcc_name' => $log->bcc?->name,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values();

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
}
