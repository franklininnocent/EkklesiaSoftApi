<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use Modules\Donations\Models\ContributionPlan;

class ContributionPeriod
{
    public static function currentForPlan(ContributionPlan $plan, ?Carbon $reference = null): array
    {
        $reference ??= Carbon::now();

        return match ($plan->frequency) {
            'one_time' => self::oneTime($plan, $reference),
            'weekly' => self::weekly($reference),
            'monthly' => self::monthly($reference),
            'quarterly' => self::quarterly($reference),
            'half_yearly' => self::halfYearly($reference),
            'yearly' => self::yearly($reference),
            'custom' => self::custom($reference, (int) ($plan->custom_interval_days ?? 30)),
            default => self::monthly($reference),
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
            'period_label' => 'ONCE-' . $start->format('Ymd'),
            'period_start' => $start->toDateString(),
            'period_end' => $dueDate,
            'due_date' => $dueDate,
        ];
    }

    public static function weekly(Carbon $reference): array
    {
        $start = $reference->copy()->startOfWeek();
        $end = $reference->copy()->endOfWeek();

        return [
            'period_label' => $reference->format('o') . '-W' . str_pad((string) $reference->isoWeek(), 2, '0', STR_PAD_LEFT),
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
            'period_label' => $reference->format('Y') . '-Q' . $quarter,
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
            'period_label' => $reference->format('Y') . '-H' . $half,
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
        $intervalDays = max(1, $intervalDays);
        $epoch = Carbon::create(2000, 1, 1);
        $daysSinceEpoch = $epoch->diffInDays($reference);
        $periodIndex = intdiv($daysSinceEpoch, $intervalDays);
        $start = $epoch->copy()->addDays($periodIndex * $intervalDays);
        $end = $start->copy()->addDays($intervalDays - 1);

        return [
            'period_label' => 'C' . $intervalDays . '-' . $start->format('Ymd'),
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
}
