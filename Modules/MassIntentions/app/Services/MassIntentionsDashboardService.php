<?php

namespace Modules\MassIntentions\Services;

use App\Support\MoneyMath;
use App\Support\UserFacingDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Services\MassGenerationHealthService;
use Modules\MassIntentions\Services\MassIntentionOfficeCloseService;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassIntentionsParishTime;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionsDashboardService
{
    public function __construct(
        private readonly MassIntentionOfficeCloseService $officeClose,
        private readonly MassGenerationHealthService $generationHealth,
        private readonly MassNextUpcomingCelebrationService $nextUpcomingCelebrations,
    ) {
    }

    /**
     * Same counts as the Mass Intentions home snapshot. Does not run office-close side effects.
     *
     * @return array{
     *   open: int,
     *   intentions_registered_this_month: int,
     *   registered_from: string,
     *   registered_to: string,
     *   needs_a_mass: int,
     *   needs_a_tick: int,
     *   schedule_attention: int,
     *   this_week_masses: int
     * }
     */
    public function executiveOperationalCounts(int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $nextMonthStart = $now->copy()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $weekBounds = MassIntentionsParishTime::weekBoundsContaining($tenantId, $today);

        return [
            'open' => $this->requestStatusCounts($tenantId)['open'],
            'intentions_registered_this_month' => $this->countCreatedBetween($tenantId, $monthStart, $nextMonthStart),
            'registered_from' => $monthStart,
            'registered_to' => $nextMonthStart,
            'needs_a_mass' => $this->countNeedsAMass($tenantId),
            'needs_a_tick' => $this->countCelebrationsNeedingTick($tenantId),
            'schedule_attention' => $this->countScheduleAttention($tenantId),
            'this_week_masses' => $this->countCelebrationsInWeek($tenantId, $weekBounds['sunday'], $weekBounds['saturday']),
        ];
    }

    public function homeSummary(int $tenantId, bool $includeOfferings = false): array
    {
        $this->officeClose->closeExpiredForTenant($tenantId);

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $nextMonthStart = $now->copy()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $thisMonthStartExclusiveEnd = $nextMonthStart;

        $requestCounts = $this->requestStatusCounts($tenantId);
        $queue = [
            'open' => $requestCounts['open'],
            'closed' => $requestCounts['closed'],
        ];

        $intentionsThisMonth = $this->countCreatedBetween($tenantId, $monthStart, $thisMonthStartExclusiveEnd);
        $intentionsLastMonth = $this->countCreatedBetween($tenantId, $lastMonthStart, $monthStart);

        $weekBounds = MassIntentionsParishTime::weekBoundsContaining($tenantId, $today);
        $thisWeekMasses = $this->countCelebrationsInWeek($tenantId, $weekBounds['sunday'], $weekBounds['saturday']);
        $needsTick = $this->countCelebrationsNeedingTick($tenantId);
        $needsAMass = $this->countNeedsAMass($tenantId);
        $scheduleAttention = $this->countScheduleAttention($tenantId);
        $generation = $this->generationHealth->assessForTenant($tenantId);
        $upcomingCelebrations = $this->upcomingCelebrationsWithCounts($tenantId, 8);

        $period = [
            'label' => UserFacingDate::formatMonthYear($now),
            'intentions_registered_this_month' => $intentionsThisMonth,
            'intentions_registered_last_month' => $intentionsLastMonth,
            'created_from' => $monthStart,
            'created_to' => $thisMonthStartExclusiveEnd,
        ];

        $payload = [
            'queue' => $queue,
            'kpis' => [
                'open' => $requestCounts['open'],
                'closed' => $requestCounts['closed'],
                'intentions_registered_this_month' => $intentionsThisMonth,
                'intentions_registered_last_month' => $intentionsLastMonth,
                'upcoming_masses' => $this->nextUpcomingCelebrations->countUpcomingCelebrations($tenantId),
                'needs_a_tick' => $needsTick,
                'needs_a_mass' => $needsAMass,
                'schedule_attention' => $scheduleAttention,
                'this_week_masses' => $thisWeekMasses,
            ],
            'requests' => $requestCounts,
            'period' => $period,
            'trend' => $this->monthlyTrend($tenantId, $now, $includeOfferings),
            'operational' => [
                'upcoming' => $this->countUpcomingIntentions($tenantId),
                'needs_a_tick' => $needsTick,
                'needs_a_mass' => $needsAMass,
                'schedule_attention' => $scheduleAttention,
            ],
            'upcoming_celebrations' => $upcomingCelebrations,
            'generation' => [
                'attention_required' => $generation['attention_required'],
                'attention_reason' => $generation['attention_reason'],
            ],
            'meta' => [
                'parish_today' => $today,
                'timezone' => $timezone,
                'parish_now' => $now->toIso8601String(),
                'next_upcoming_celebration_id' => $upcomingCelebrations[0]['id'] ?? null,
                'week_from' => $weekBounds['sunday'],
                'week_to' => $weekBounds['saturday'],
            ],
        ];

        if ($includeOfferings) {
            $offeringThisMonth = $this->sumOfferingsBetween($tenantId, $monthStart, $thisMonthStartExclusiveEnd);
            $offeringLastMonth = $this->sumOfferingsBetween($tenantId, $lastMonthStart, $monthStart);
            $receiptsThisMonth = $this->countReceiptsBetween($tenantId, $monthStart, $thisMonthStartExclusiveEnd);

            $payload['period']['offering_received_this_month'] = $offeringThisMonth;
            $payload['period']['offering_received_last_month'] = $offeringLastMonth;
            $payload['offerings'] = [
                'received_this_month' => $offeringThisMonth,
                'received_last_month' => $offeringLastMonth,
                'receipts_this_month' => $receiptsThisMonth,
            ];
            $payload['kpis']['offering_received_this_month'] = $offeringThisMonth;
            $payload['kpis']['offering_received_last_month'] = $offeringLastMonth;
            $payload['kpis']['receipts_this_month'] = $receiptsThisMonth;
        }

        return $payload;
    }

    /**
     * @param  list<string>  $statuses
     */
    /**
     * @return array{open: int, closed: int}
     */
    private function requestStatusCounts(int $tenantId): array
    {
        $counts = [
            'open' => 0,
            'closed' => 0,
        ];

        $rows = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            if ($status === MassIntentionStatus::OPEN) {
                $counts['open'] = (int) $count;
            } elseif ($status === MassIntentionStatus::CLOSED) {
                $counts['closed'] = (int) $count;
            }
        }

        return $counts;
    }

    private function countCreatedBetween(int $tenantId, string $fromInclusive, string $toExclusive): int
    {
        [$startUtc, $endUtc] = MassIntentionsParishTime::timestampRangeUtc($tenantId, $fromInclusive, $toExclusive);

        return MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function obligationStatusCounts(int $tenantId): array
    {
        $counts = [
            MassObligationStatus::PENDING => 0,
            MassObligationStatus::SCHEDULED => 0,
            MassObligationStatus::SAID => 0,
        ];

        $rows = DB::table('mass_intention_obligations as o')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->where('o.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::ACCEPTED)
            ->selectRaw('o.status, count(*) as aggregate')
            ->groupBy('o.status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            if (array_key_exists((string) $status, $counts)) {
                $counts[(string) $status] = (int) $count;
            }
        }

        return $counts;
    }

    private function countNotScheduledObligations(int $tenantId): int
    {
        return (int) DB::table('mass_intention_obligations as o')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->where('o.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::ACCEPTED)
            ->where('o.status', MassObligationStatus::PENDING)
            ->count();
    }

    private function countStillToSay(int $tenantId): int
    {
        return (int) DB::table('mass_intention_obligations as o')
            ->join('mass_intention_requests as r', 'r.id', '=', 'o.request_id')
            ->where('o.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::ACCEPTED)
            ->whereIn('o.status', [MassObligationStatus::PENDING, MassObligationStatus::SCHEDULED])
            ->count();
    }

    private function countScheduleAttention(int $tenantId): int
    {
        $count = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'regular')
            ->where('status', 'active')
            ->value('last_preview_conflict_count');

        return max(0, (int) $count);
    }

    private function countNeedsAMass(int $tenantId): int
    {
        return (int) DB::table('mass_intention_requests as r')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::OPEN)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from('mass_intention_obligations as o')
                    ->join('mass_intention_assignments as a', function ($join): void {
                        $join->on('a.obligation_id', '=', 'o.id')->whereNull('a.unassigned_at');
                    })
                    ->whereColumn('o.request_id', 'r.id');
            })
            ->count();
    }

    private function countUpcomingIntentions(int $tenantId): int
    {
        $upcomingCelebrationIds = $this->nextUpcomingCelebrations
            ->upcomingCelebrationsQuery($tenantId)
            ->select('id');

        return (int) DB::table('mass_intention_requests as r')
            ->join('mass_intention_obligations as o', 'o.request_id', '=', 'r.id')
            ->join('mass_intention_assignments as a', function ($join): void {
                $join->on('a.obligation_id', '=', 'o.id')->whereNull('a.unassigned_at');
            })
            ->join('mass_celebrations as c', 'c.id', '=', 'a.celebration_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', MassIntentionStatus::OPEN)
            ->whereIn('c.id', $upcomingCelebrationIds)
            ->distinct('r.id')
            ->count('r.id');
    }

    private function countCelebrationsNeedingTick(int $tenantId): int
    {
        $today = DonationBusinessDate::today($tenantId);

        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('generation_status', MassGenerationStatus::ACTIVE)
            ->whereDate('celebrated_on', '<=', $today)
            ->where(function ($q): void {
                $q->where('generation_status', MassGenerationStatus::ACTIVE)
                    ->orWhere('status', 'cancelled');
            })
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
            })
            ->count();
    }

    private function countCelebrationsInWeek(int $tenantId, string $from, string $to): int
    {
        return $this->celebrationsWeekListQuery($tenantId, $from, $to)->count();
    }

    private function celebrationsWeekListQuery(int $tenantId, string $from, string $to)
    {
        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('celebrated_on', '>=', $from)
            ->whereDate('celebrated_on', '<=', $to)
            ->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('status', 'scheduled')
                        ->where('generation_status', MassGenerationStatus::ACTIVE);
                })->orWhere('status', 'cancelled');
            })
            ->where(function ($q): void {
                $q->where('generation_status', MassGenerationStatus::ACTIVE)
                    ->orWhere('status', 'cancelled');
            });
    }

    private function countAcceptedBetween(int $tenantId, string $fromInclusive, string $toExclusive): int
    {
        return MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MassIntentionStatus::ACCEPTED)
            ->where('accepted_at', '>=', $fromInclusive)
            ->where('accepted_at', '<', $toExclusive)
            ->count();
    }

    private function countFulfilmentsBetween(int $tenantId, string $fromInclusive, string $toExclusive): int
    {
        [$startUtc, $endUtc] = MassIntentionsParishTime::timestampRangeUtc($tenantId, $fromInclusive, $toExclusive);

        return (int) DB::table('mass_intention_fulfilments')
            ->where('tenant_id', $tenantId)
            ->whereNull('undone_at')
            ->where('fulfilled_at', '>=', $startUtc)
            ->where('fulfilled_at', '<', $endUtc)
            ->count();
    }

    private function countReceiptsBetween(int $tenantId, string $fromInclusive, string $toExclusive): int
    {
        return (int) DB::table('mass_intention_offering_receipts')
            ->where('tenant_id', $tenantId)
            ->whereNull('voided_at')
            ->where('received_on', '>=', $fromInclusive)
            ->where('received_on', '<', $toExclusive)
            ->count();
    }

    private function sumOfferingsBetween(int $tenantId, string $fromInclusive, string $toExclusive): string
    {
        $sum = DB::table('mass_intention_offering_receipts')
            ->where('tenant_id', $tenantId)
            ->whereNull('voided_at')
            ->where('received_on', '>=', $fromInclusive)
            ->where('received_on', '<', $toExclusive)
            ->sum(DB::raw($this->amountSumSql()));

        return MoneyMath::normalize($sum ?: '0');
    }

    private function amountSumSql(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'amount::numeric'
            : 'CAST(amount AS REAL)';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyTrend(int $tenantId, Carbon $now, bool $includeOfferings): array
    {
        $points = [];

        for ($offset = 0; $offset <= 3; $offset++) {
            $month = $now->copy()->subMonthsNoOverflow($offset)->startOfMonth();
            $from = $month->toDateString();
            $to = $month->copy()->addMonthNoOverflow()->toDateString();

            [$closedStartUtc, $closedEndUtc] = MassIntentionsParishTime::timestampRangeUtc($tenantId, $from, $to);

            $point = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'registered' => $this->countCreatedBetween($tenantId, $from, $to),
                'closed' => MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', MassIntentionStatus::CLOSED)
                    ->where('closed_at', '>=', $closedStartUtc)
                    ->where('closed_at', '<', $closedEndUtc)
                    ->count(),
                'said' => $this->countFulfilmentsBetween($tenantId, $from, $to),
                'is_current' => $offset === 0,
            ];

            if ($includeOfferings) {
                $point['offering'] = $this->sumOfferingsBetween($tenantId, $from, $to);
            }

            $points[] = $point;
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function upcomingCelebrations(int $tenantId, int $limit = 8): array
    {
        return $this->upcomingCelebrationsWithCounts($tenantId, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingCelebrationsWithCounts(int $tenantId, int $limit): array
    {
        $rows = $this->nextUpcomingCelebrations->listUpcomingCelebrations($tenantId, $limit);

        $ids = $rows->pluck('id')->all();
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

        return $rows->map(fn ($c) => [
            'id' => $c->id,
            'celebrated_on' => $c->celebrated_on?->format('Y-m-d'),
            'celebrated_at' => $c->celebrated_at,
            'place' => $c->place,
            'celebrant_name' => $c->celebrant_name,
            'intention_count' => (int) ($counts[$c->id] ?? 0),
        ])->all();
    }
}
