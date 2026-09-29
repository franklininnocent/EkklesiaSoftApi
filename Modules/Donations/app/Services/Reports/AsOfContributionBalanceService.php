<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;

/**
 * Reconstructs due balances as-of a parish calendar date using allocation replay.
 */
final class AsOfContributionBalanceService
{
    public function isExcludedAsOf(ContributionDue|ProjectInstallmentDue $due, string $asOf): bool
    {
        $status = (string) ($due->status ?? 'pending');
        if (! in_array($status, ['waived', 'cancelled'], true)) {
            return false;
        }

        $changedAt = $due->status_changed_at;
        if ($changedAt === null) {
            return true;
        }

        return $changedAt->toDateString() <= $asOf;
    }

    public function replayedPaidForDue(int $tenantId, ContributionDue $due, string $asOf): string
    {
        $parishToday = DonationBusinessDate::today($tenantId);
        if ($asOf >= $parishToday) {
            return MoneyMath::normalize($due->amount_paid ?? 0);
        }

        $paid = DB::table('payment_allocations as pa')
            ->join('donation_payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.tenant_id', $tenantId)
            ->whereNull('pa.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pa.allocatable_type', 'due')
            ->where('pa.allocatable_id', $due->id)
            ->whereDate('p.payment_date', '<=', $asOf)
            ->where('p.status', 'succeeded')
            ->whereNotExists(function ($sub) use ($asOf): void {
                $sub->selectRaw('1')
                    ->from('payment_reversals as r')
                    ->whereColumn('r.payment_id', 'p.id')
                    ->whereNull('r.deleted_at')
                    ->whereDate('r.reversed_at', '<=', $asOf);
            })
            ->sum('pa.amount');

        return MoneyMath::normalize($paid ?? 0);
    }

    public function outstandingForDue(int $tenantId, ContributionDue $due, string $asOf): float
    {
        if ($this->isExcludedAsOf($due, $asOf)) {
            return 0.0;
        }

        $paid = $this->replayedPaidForDue($tenantId, $due, $asOf);
        $outstanding = MoneyMath::outstanding($due->amount_due ?? 0, $paid);

        return MoneyMath::toApiNumber($outstanding);
    }

    public function replayedPaidForInstallmentDue(int $tenantId, ProjectInstallmentDue $due, string $asOf): string
    {
        $parishToday = DonationBusinessDate::today($tenantId);
        if ($asOf >= $parishToday) {
            return MoneyMath::normalize($due->amount_paid ?? 0);
        }

        $paid = DB::table('payment_allocations as pa')
            ->join('donation_payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.tenant_id', $tenantId)
            ->whereNull('pa.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pa.allocatable_type', 'project_installment')
            ->where('pa.allocatable_id', $due->id)
            ->whereDate('p.payment_date', '<=', $asOf)
            ->where('p.status', 'succeeded')
            ->whereNotExists(function ($sub) use ($asOf): void {
                $sub->selectRaw('1')
                    ->from('payment_reversals as r')
                    ->whereColumn('r.payment_id', 'p.id')
                    ->whereNull('r.deleted_at')
                    ->whereDate('r.reversed_at', '<=', $asOf);
            })
            ->sum('pa.amount');

        return MoneyMath::normalize($paid ?? 0);
    }

    public function outstandingForInstallmentDue(int $tenantId, ProjectInstallmentDue $due, string $asOf): float
    {
        if ($this->isExcludedAsOf($due, $asOf)) {
            return 0.0;
        }

        $paid = $this->replayedPaidForInstallmentDue($tenantId, $due, $asOf);
        $outstanding = MoneyMath::outstanding($due->amount_due ?? 0, $paid);

        return MoneyMath::toApiNumber($outstanding);
    }

    public function isCollectableAsOf(int $tenantId, ContributionDue $due, string $asOf): bool
    {
        if ($this->isExcludedAsOf($due, $asOf)) {
            return false;
        }

        if (! ContributionBalance::isCollectable($due, $asOf)) {
            return false;
        }

        return MoneyMath::isPositive($this->outstandingForDue($tenantId, $due, $asOf));
    }

    public function isOverdueAsOf(int $tenantId, ContributionDue $due, string $asOf): bool
    {
        if (! $this->isCollectableAsOf($tenantId, $due, $asOf)) {
            return false;
        }

        $dueDate = $due->due_date?->format('Y-m-d');
        if ($dueDate === null) {
            return false;
        }

        return $dueDate < $asOf;
    }

    public function isCollectableInstallmentAsOf(int $tenantId, ProjectInstallmentDue $due, string $asOf): bool
    {
        if ($this->isExcludedAsOf($due, $asOf)) {
            return false;
        }

        if (! in_array((string) ($due->status ?? 'pending'), ['pending', 'partially_paid'], true)) {
            return false;
        }

        $dueDate = $due->due_date?->format('Y-m-d');
        if ($dueDate !== null && $dueDate > $asOf) {
            return false;
        }

        return MoneyMath::isPositive($this->outstandingForInstallmentDue($tenantId, $due, $asOf));
    }

    public function isOverdueInstallmentAsOf(int $tenantId, ProjectInstallmentDue $due, string $asOf): bool
    {
        if (! $this->isCollectableInstallmentAsOf($tenantId, $due, $asOf)) {
            return false;
        }

        $dueDate = $due->due_date?->format('Y-m-d');
        if ($dueDate === null) {
            return false;
        }

        return $dueDate < $asOf;
    }
}
