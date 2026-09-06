<?php

namespace Modules\Donations\Support;

use Illuminate\Database\Eloquent\Builder;

class ContributionBalance
{
    public static function outstandingForDue(object $due): float
    {
        return MoneyMath::toApiNumber(MoneyMath::outstanding($due->amount_due ?? 0, $due->amount_paid ?? 0));
    }

    public static function outstandingString(object $due): string
    {
        return MoneyMath::outstanding($due->amount_due ?? 0, $due->amount_paid ?? 0);
    }

    public static function applyPaid(object $due, string|float|int $amount): string
    {
        $paid = MoneyMath::add($due->amount_paid ?? 0, $amount);
        $dueAmount = MoneyMath::normalize($due->amount_due ?? 0);
        if (MoneyMath::compare($paid, $dueAmount) > 0) {
            $paid = $dueAmount;
        }
        $due->amount_paid = $paid;

        return $paid;
    }

    public static function unwindPaid(object $due, string|float|int $amount): string
    {
        $paid = MoneyMath::floorAtZero(MoneyMath::subtract($due->amount_paid ?? 0, $amount));
        $due->amount_paid = $paid;

        return $paid;
    }

    public static function statusFromPaid(object $due): string
    {
        $current = (string) ($due->status ?? 'pending');
        if (in_array($current, ['waived', 'cancelled'], true)) {
            return $current;
        }

        if (! MoneyMath::isPositive($due->amount_paid ?? 0)) {
            return 'pending';
        }

        if (MoneyMath::compare($due->amount_paid, $due->amount_due ?? 0) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }

    public static function sumOutstanding(Builder $query): float
    {
        $value = $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as outstanding')
            ->value('outstanding');

        return MoneyMath::toApiNumber($value ?? 0);
    }
}
