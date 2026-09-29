<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ParishExpense;
use Modules\Donations\Support\DonationBusinessDate;

class ParishExpenseService
{
    public function monthTotal(int $tenantId, ?string $monthStart = null, ?string $monthEnd = null): float
    {
        $start = $monthStart ?? DonationBusinessDate::monthStart($tenantId);
        $end = $monthEnd ?? DonationBusinessDate::today($tenantId);

        return round((float) ParishExpense::forTenant($tenantId)
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->sum('amount'), 2);
    }

    public function yearTotal(int $tenantId, string $fyStart, ?string $fyEnd = null): float
    {
        $end = $fyEnd ?? DonationBusinessDate::today($tenantId);
        $effectiveEnd = $end > $fyStart ? $end : $fyStart;

        return round((float) ParishExpense::forTenant($tenantId)
            ->whereDate('expense_date', '>=', $fyStart)
            ->whereDate('expense_date', '<=', $effectiveEnd)
            ->sum('amount'), 2);
    }

    public function hasAnySince(int $tenantId, string $startDate): bool
    {
        return ParishExpense::forTenant($tenantId)
            ->whereDate('expense_date', '>=', $startDate)
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $tenantId, int $limit = 10): array
    {
        return ParishExpense::forTenant($tenantId)
            ->orderByDesc('expense_date')
            ->limit($limit)
            ->get()
            ->map(fn (ParishExpense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date?->toDateString(),
            ])
            ->all();
    }
}
