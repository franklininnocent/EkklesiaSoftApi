<?php

namespace Modules\Donations\Support;

use Illuminate\Database\Eloquent\Builder;

class ContributionBalance
{
    public static function outstandingForDue(object $due): float
    {
        return max(0, round((float) $due->amount_due - (float) $due->amount_paid, 2));
    }

    public static function sumOutstanding(Builder $query): float
    {
        return (float) $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as outstanding')
            ->value('outstanding');
    }
}
