<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\ApplyMassScheduleProposalRequest;
use Modules\MassIntentions\Http\Requests\AssignObligationsToCelebrationRequest;
use Modules\MassIntentions\Http\Requests\CancelMassCelebrationRequest;
use Modules\MassIntentions\Http\Requests\ConfirmMassSaidRequest;
use Modules\MassIntentions\Http\Requests\StoreMassCelebrationRequest;
use Modules\MassIntentions\Http\Requests\UpdateMassCelebrationRequest;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassIntentionsSql;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\MassIntentions\Services\MassCelebrationService;
use Modules\MassIntentions\Services\MassCelebrationWorkspaceService;
use Modules\MassIntentions\Services\MassIntentionFulfilmentService;
use Modules\MassIntentions\Services\MassIntentionSchedulingService;
use Modules\MassIntentions\Services\MassNextUpcomingCelebrationService;
use Modules\MassIntentions\Services\MassOccurrenceMaterializationService;
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
        private readonly MassOccurrenceMaterializationService $materialization,
        private readonly MassNextUpcomingCelebrationService $nextUpcoming,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();

        $fromInput = (string) $request->input('from', '');
        $toInput = (string) $request->input('to', '');
        if ($fromInput === '' && $toInput === '') {
            $fromInput = $today->toDateString();
            $toInput = $today->copy()->addDays(MassScheduleConstants::DEFAULT_LIST_RANGE_DAYS)->toDateString();
        }

        if ($fromInput !== '' && $toInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput)) {
            $days = Carbon::parse($fromInput)->diffInDays(Carbon::parse($toInput));
            if ($days > MassScheduleConstants::MAX_LIST_RANGE_DAYS) {
                throw new HttpException(422, 'Date range cannot exceed '.MassScheduleConstants::MAX_LIST_RANGE_DAYS.' days.');
            }

            $this->materialization->ensureForCelebrationList($tenantId, $fromInput, $toInput);
        }

        $query = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('celebrated_on')
            ->orderBy('celebrated_at')
            ->orderBy('id');

        if ($fromInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput)) {
            $query->whereDate('celebrated_on', '>=', $fromInput);
        }
        if ($toInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput)) {
            $query->whereDate('celebrated_on', '<=', $toInput);
        }

        if ($request->boolean('needs_tick')) {
            // needs_tick block below adds its own status filters.
        } elseif ($status = $request->input('status')) {
            $query->where('status', $status);
        } elseif ($request->boolean('include_cancelled')) {
            $query->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('status', 'scheduled')
                        ->where('generation_status', MassGenerationStatus::ACTIVE);
                })->orWhere('status', 'cancelled');
            });
        } else {
            $query->where('status', 'scheduled')
                ->where('generation_status', MassGenerationStatus::ACTIVE);
        }

        if (! $request->boolean('include_orphaned')) {
            $query->where(function ($q): void {
                $q->where('generation_status', MassGenerationStatus::ACTIVE)
                    ->orWhere('status', 'cancelled');
            });
        }

        if ($origin = $request->input('origin')) {
            $query->where('origin', $origin);
        }

        if ($request->boolean('assignable_only')) {
            $query->where('status', 'scheduled')
                ->where('generation_status', MassGenerationStatus::ACTIVE)
                ->where(function ($q): void {
                    $q->whereNull('suppression_reason')
                        ->orWhere('suppression_reason', '');
                });
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $like = MassIntentionsSql::likeOperator();
            $query->where(function ($q) use ($term, $like, $search): void {
                $q->where('place', $like, $term)
                    ->orWhere('celebrant_name', $like, $term);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) === 1) {
                    $q->orWhereDate('celebrated_on', $search);
                }
                if (preg_match('/^\d{1,2}:\d{2}/', $search) === 1) {
                    $q->orWhere('celebrated_at', $like, substr($search, 0, 5).'%');
                }
            });
        }

        $celebratedOn = (string) $request->input('celebrated_on', '');
        if ($celebratedOn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $celebratedOn)) {
            $query->whereDate('celebrated_on', $celebratedOn);
        }

        if ($request->boolean('needs_tick')) {
            $query->where('status', 'scheduled')
                ->where('generation_status', MassGenerationStatus::ACTIVE)
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

        $next = $this->nextUpcoming->resolve($tenantId);
        $parishTimezone = DonationBusinessDate::timezoneForTenant($tenantId);

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
            'meta' => [
                'next_upcoming_celebration_id' => $next['id'] ?? null,
                'next_upcoming_starts_at' => $next['starts_at'] ?? null,
                'parish_timezone' => $next['parish_timezone'] ?? $parishTimezone,
                'parish_now' => Carbon::now($parishTimezone)->toIso8601String(),
            ],
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

    public function applyScheduleProposal(ApplyMassScheduleProposalRequest $request, string $id): JsonResponse
    {
        $celebration = $this->celebrations->applyScheduleProposal(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'The new schedule time was applied to this Mass. Intentions stay on this Mass.',
            'data' => $this->celebrations->toArray($celebration),
        ]);
    }

    public function keepOnSchedule(string $id): JsonResponse
    {
        $celebration = $this->celebrations->keepOnSchedule($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'This Mass will stay as-is when you save schedule changes.',
            'data' => $this->celebrations->toArray($celebration),
        ]);
    }

    public function markScheduleChanged(string $id): JsonResponse
    {
        $celebration = $this->celebrations->markScheduleChanged($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'This Mass was marked schedule changed.',
            'data' => $this->celebrations->toArray($celebration),
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
