<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ParishExpense;

class ParishExpenseService
{
    public function monthTotal(int $tenantId, ?string $monthStart = null, ?string $monthEnd = null): float
    {
        $start = $monthStart ?? now()->startOfMonth()->toDateString();
        $end = $monthEnd ?? now()->endOfMonth()->toDateString();

        return round((float) ParishExpense::forTenant($tenantId)
            ->whereBetween('expense_date', [$start, $end])
            ->sum('amount'), 2);
    }

    public function yearTotal(int $tenantId, string $fyStart): float
    {
        return round((float) ParishExpense::forTenant($tenantId)
            ->whereDate('expense_date', '>=', $fyStart)
            ->sum('amount'), 2);
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
                'payee' => $expense->payee,
                'method' => $expense->method,
            ])
            ->values()
            ->all();
    }
}
