<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\AssignObligationsToCelebrationRequest;
use Modules\MassIntentions\Http\Requests\CancelMassCelebrationRequest;
use Modules\MassIntentions\Http\Requests\ConfirmMassSaidRequest;
use Modules\MassIntentions\Http\Requests\StoreMassCelebrationRequest;
use Modules\MassIntentions\Http\Requests\UpdateMassCelebrationRequest;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\MassIntentions\Services\MassCelebrationService;
use Modules\MassIntentions\Services\MassCelebrationWorkspaceService;
use Modules\MassIntentions\Services\MassIntentionFulfilmentService;
use Modules\MassIntentions\Services\MassIntentionSchedulingService;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassCelebrationController extends Controller
{
    public function __construct(
        private readonly MassCelebrationService $celebrations,
        private readonly MassCelebrationWorkspaceService $workspace,
        private readonly MassIntentionFulfilmentService $fulfilment,
        private readonly MassIntentionSchedulingService $scheduling,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $query = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('celebrated_on')
            ->orderByDesc('celebrated_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('needs_tick')) {
            $today = DonationBusinessDate::today($tenantId);
            $query->where('status', 'scheduled')
                ->whereDate('celebrated_on', '<=', $today)
                ->whereExists(function ($sub) use ($tenantId): void {
                    $sub->select(DB::raw('1'))
                        ->from('mass_intention_assignments as a')
                        ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
                        ->leftJoin('mass_intention_fulfilments as f', function ($join): void {
                            $join->on('f.obligation_id', '=', 'o.id')->whereNull('f.undone_at');
                        })
                        ->whereColumn('a.celebration_id', 'mass_celebrations.id')
                        ->where('a.tenant_id', $tenantId)
                        ->whereNull('a.unassigned_at')
                        ->where('o.status', MassObligationStatus::SCHEDULED)
                        ->whereNull('f.id');
                });
        }

        $page = $query->paginate($perPage);
        $ids = collect($page->items())->pluck('id')->all();
        $counts = [];
        if ($ids !== []) {
            $counts = DB::table('mass_intention_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('celebration_id', $ids)
                ->whereNull('unassigned_at')
                ->select('celebration_id', DB::raw('count(distinct obligation_id) as intention_count'))
                ->groupBy('celebration_id')
                ->pluck('intention_count', 'celebration_id')
                ->all();
        }

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(function ($c) use ($counts) {
                $row = $this->celebrations->toArray($c);
                $row['intention_count'] = (int) ($counts[$c->id] ?? 0);

                return $row;
            })->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function store(StoreMassCelebrationRequest $request): JsonResponse
    {
        $created = $this->celebrations->create($this->tenantId(), $this->actor(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Mass added.',
            'data' => $this->celebrations->toArray($created),
        ], 201);
    }

    public function update(UpdateMassCelebrationRequest $request, string $id): JsonResponse
    {
        $updated = $this->celebrations->update($this->tenantId(), $id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Mass updated.',
            'data' => $this->celebrations->toArray($updated),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $celebration = MassCelebration::query()
            ->where('tenant_id', $this->tenantId())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->celebrations->toArray($celebration),
        ]);
    }

    public function workspace(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->workspace->workspace($this->tenantId(), $id),
        ]);
    }

    public function assignObligations(AssignObligationsToCelebrationRequest $request, string $id): JsonResponse
    {
        $count = $this->scheduling->assignObligationsToCelebration(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated('obligation_ids'),
            $request->validated('date_variance_reason')
        );

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Intention added to this Mass.' : "{$count} intentions added to this Mass.",
            'data' => $this->workspace->workspace($this->tenantId(), $id),
        ]);
    }

    public function cancel(CancelMassCelebrationRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $celebration = $this->celebrations->cancel(
            $this->tenantId(),
            $this->actor(),
            $id,
            $validated['reason'],
            $validated['reassignments'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Mass cancelled.',
            'data' => $this->celebrations->toArray($celebration),
        ]);
    }

    public function confirmSaid(ConfirmMassSaidRequest $request, string $id): JsonResponse
    {
        $count = $this->fulfilment->confirmSaid(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated('obligation_ids'),
            $request->validated('celebrant_overrides') ?? []
        );

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Marked as said.' : "Marked {$count} as said.",
            'data' => $this->workspace->workspace($this->tenantId(), $id),
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

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $user;
    }
}
