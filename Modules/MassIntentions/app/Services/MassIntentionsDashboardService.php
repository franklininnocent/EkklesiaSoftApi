<?php

namespace Modules\MassIntentions\Services;

use App\Support\MoneyMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Services\MassIntentionOfficeCloseService;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassObligationStatus;

class MassIntentionsDashboardService
{
    public function __construct(
        private readonly MassIntentionOfficeCloseService $officeClose,
    ) {
    }

    /**
     * Parish Mass Intention workspace snapshot. Counts are tenant-scoped.
     *
     * @return array<string, mixed>
     */
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

        $period = [
            'label' => $now->format('F Y'),
            'intentions_registered_this_month' => $intentionsThisMonth,
            'intentions_registered_last_month' => $intentionsLastMonth,
        ];

        $payload = [
            'queue' => $queue,
            'kpis' => [
                'open' => $requestCounts['open'],
                'closed' => $requestCounts['closed'],
                'intentions_registered_this_month' => $intentionsThisMonth,
                'intentions_registered_last_month' => $intentionsLastMonth,
            ],
            'requests' => $requestCounts,
            'period' => $period,
            'trend' => $this->monthlyTrend($tenantId, $now, $includeOfferings),
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
        return MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $fromInclusive)
            ->where('created_at', '<', $toExclusive)
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

    private function countNeedsTick(int $tenantId): int
    {
        $today = DonationBusinessDate::today($tenantId);

        return (int) DB::table('mass_intention_obligations as o')
            ->join('mass_intention_assignments as a', function ($join): void {
                $join->on('a.obligation_id', '=', 'o.id')->whereNull('a.unassigned_at');
            })
            ->join('mass_celebrations as c', 'c.id', '=', 'a.celebration_id')
            ->leftJoin('mass_intention_fulfilments as f', function ($join): void {
                $join->on('f.obligation_id', '=', 'o.id')->whereNull('f.undone_at');
            })
            ->where('o.tenant_id', $tenantId)
            ->where('o.status', MassObligationStatus::SCHEDULED)
            ->where('c.status', 'scheduled')
            ->whereDate('c.celebrated_on', '<=', $today)
            ->whereNull('f.id')
            ->count();
    }

    private function countUpcomingCelebrations(int $tenantId, string $today): int
    {
        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->whereDate('celebrated_on', '>=', $today)
            ->count();
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
        return (int) DB::table('mass_intention_fulfilments')
            ->where('tenant_id', $tenantId)
            ->whereNull('undone_at')
            ->where('fulfilled_at', '>=', $fromInclusive)
            ->where('fulfilled_at', '<', $toExclusive)
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

        for ($offset = 5; $offset >= 0; $offset--) {
            $month = $now->copy()->subMonthsNoOverflow($offset)->startOfMonth();
            $from = $month->toDateString();
            $to = $month->copy()->addMonthNoOverflow()->toDateString();

            $point = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'registered' => $this->countCreatedBetween($tenantId, $from, $to),
                'closed' => MassIntentionRequest::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', MassIntentionStatus::CLOSED)
                    ->where('closed_at', '>=', $from)
                    ->where('closed_at', '<', $to)
                    ->count(),
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
        return MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('celebrated_on', '>=', DonationBusinessDate::today($tenantId))
            ->orderBy('celebrated_on')
            ->orderBy('celebrated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'celebrated_on' => $c->celebrated_on?->format('Y-m-d'),
                'celebrated_at' => $c->celebrated_at,
                'place' => $c->place,
                'celebrant_name' => $c->celebrant_name,
            ])
            ->all();
    }
}
