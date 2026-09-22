<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use Modules\Donations\Models\ContributionPlan;

class ContributionPeriod
{
    public static function currentForPlan(ContributionPlan $plan, ?Carbon $reference = null): array
    {
        if ($reference === null) {
            $businessToday = DonationBusinessDate::today((int) $plan->tenant_id);
            $reference = Carbon::parse($businessToday);
        }

        return self::periodForFrequency($plan, $reference);
    }

    /**
     * @return array<int, array{period_label: string, period_start: string, period_end: string, due_date: string}>
     */
    public static function enumerateForPlan(ContributionPlan $plan, ?string $from = null, ?string $through = null): array
    {
        if ($plan->frequency === 'one_time') {
            $single = self::oneTime($plan, $plan->start_date ? Carbon::parse($plan->start_date) : Carbon::now());

            return [$single];
        }

        if (!$plan->start_date) {
            $businessToday = DonationBusinessDate::today((int) $plan->tenant_id);
            $current = self::currentForPlan($plan, Carbon::parse($businessToday));

            return [$current];
        }

        if ($from !== null) {
            $rangeStart = $from;
        } elseif ($plan->end_date === null) {
            $businessToday = DonationBusinessDate::today((int) $plan->tenant_id);
            $fy = DonationBusinessDate::currentFinancialYearBounds((int) $plan->tenant_id, $businessToday);
            $rangeStart = max($plan->start_date->toDateString(), $fy['start']);
        } else {
            $rangeStart = $plan->start_date->toDateString();
        }

        if ($through !== null) {
            $rangeEnd = $through;
        } else {
            $businessToday = DonationBusinessDate::today((int) $plan->tenant_id);
            $rangeEnd = self::resolveGenerationThrough($plan, $businessToday);
        }

        if ($rangeEnd < $rangeStart) {
            return [];
        }

        $cursor = Carbon::parse($rangeStart)->startOfDay();
        $endBound = Carbon::parse($rangeEnd)->endOfDay();
        $periods = [];
        $seenLabels = [];
        $iterations = 0;

        while ($cursor->lte($endBound) && $iterations < ContributionPlanFrequencies::MAX_PERIODS_PER_RUN) {
            $period = self::periodForFrequency($plan, $cursor);
            $label = $period['period_label'];

            if (!isset($seenLabels[$label])) {
                if ($period['period_end'] >= $rangeStart && $period['period_start'] <= $rangeEnd) {
                    $periods[] = $period;
                    $seenLabels[$label] = true;
                }
            }

            $nextCursor = Carbon::parse($period['period_end'])->addDay()->startOfDay();
            if ($nextCursor->lte($cursor)) {
                throw new InvalidArgumentException('Schedule cursor did not advance for frequency '.$plan->frequency);
            }
            $cursor = $nextCursor;
            $iterations++;
        }

        if ($iterations >= ContributionPlanFrequencies::MAX_PERIODS_PER_RUN && $cursor->lte($endBound)) {
            throw new InvalidArgumentException(
                'Schedule exceeds the maximum of '.ContributionPlanFrequencies::MAX_PERIODS_PER_RUN.' periods per run.'
            );
        }

        return $periods;
    }

    public static function resolveGenerationThrough(ContributionPlan $plan, string $businessToday): string
    {
        $currentPeriod = self::currentForPlan($plan, Carbon::parse($businessToday));
        $currentPeriodEnd = $currentPeriod['period_end'];

        if ($plan->end_date) {
            return min($plan->end_date->toDateString(), $currentPeriodEnd);
        }

        $fy = DonationBusinessDate::currentFinancialYearBounds((int) $plan->tenant_id, $businessToday);

        return min($fy['end'], $currentPeriodEnd);
    }

    /**
     * @return array{period_label: string, period_start: string, period_end: string, due_date: string}
     */
    public static function periodForFrequency(ContributionPlan $plan, Carbon $reference): array
    {
        return match ($plan->frequency) {
            'one_time' => self::oneTime($plan, $reference),
            'weekly' => self::weekly($reference),
            'monthly' => self::monthly($reference),
            'quarterly' => self::quarterly($reference),
            'half_yearly' => self::halfYearly($reference),
            'yearly' => self::yearly($reference),
            'custom' => self::custom($reference, (int) ($plan->custom_interval_days ?? 30)),
            default => throw new InvalidArgumentException('Unsupported contribution plan frequency: '.$plan->frequency),
        };
    }

    public static function oneTime(ContributionPlan $plan, Carbon $reference): array
    {
        $start = $plan->start_date
            ? Carbon::parse($plan->start_date)
            : $reference->copy()->startOfDay();
        $dueDate = $plan->end_date
            ? Carbon::parse($plan->end_date)->toDateString()
            : $start->toDateString();

        return [
            'period_label' => 'ONCE-'.$start->format('Ymd'),
            'period_start' => $start->toDateString(),
            'period_end' => $dueDate,
            'due_date' => $dueDate,
        ];
    }

    public static function weekly(Carbon $reference): array
    {
        $start = $reference->copy()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

        return [
            'period_label' => $start->format('o').'-W'.str_pad((string) $start->isoWeek(), 2, '0', STR_PAD_LEFT),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function monthly(Carbon $reference): array
    {
        $start = $reference->copy()->startOfMonth();
        $end = $reference->copy()->endOfMonth();

        return [
            'period_label' => $reference->format('Y-m'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function quarterly(Carbon $reference): array
    {
        $quarter = (int) ceil($reference->month / 3);
        $start = $reference->copy()->month(($quarter - 1) * 3 + 1)->startOfMonth();
        $end = $start->copy()->addMonths(2)->endOfMonth();

        return [
            'period_label' => $reference->format('Y').'-Q'.$quarter,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function halfYearly(Carbon $reference): array
    {
        $half = $reference->month <= 6 ? 1 : 2;
        $start = $reference->copy()->month($half === 1 ? 1 : 7)->startOfMonth();
        $end = $start->copy()->addMonths(5)->endOfMonth();

        return [
            'period_label' => $reference->format('Y').'-H'.$half,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function yearly(Carbon $reference): array
    {
        $start = $reference->copy()->startOfYear();
        $end = $reference->copy()->endOfYear();

        return [
            'period_label' => $reference->format('Y'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function custom(Carbon $reference, int $intervalDays): array
    {
        $intervalDays = max(1, min($intervalDays, ContributionPlanFrequencies::MAX_CUSTOM_INTERVAL_DAYS));
        $epoch = Carbon::create(2000, 1, 1);
        $daysSinceEpoch = (int) $epoch->diffInDays($reference);
        $periodIndex = intdiv($daysSinceEpoch, $intervalDays);
        $start = $epoch->copy()->addDays($periodIndex * $intervalDays);
        $end = $start->copy()->addDays($intervalDays - 1);

        return [
            'period_label' => 'C'.$intervalDays.'-'.$start->format('Ymd'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $end->toDateString(),
        ];
    }

    public static function applyGraceDays(string $dueDate, int $graceDays): string
    {
        if ($graceDays <= 0) {
            return $dueDate;
        }

        return Carbon::parse($dueDate)->addDays($graceDays)->toDateString();
    }

    public static function isCurrentPeriod(array $period, string $businessDate): bool
    {
        return $businessDate >= $period['period_start'] && $businessDate <= $period['period_end'];
    }
}
