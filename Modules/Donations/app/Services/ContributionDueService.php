<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Support\ContributionPeriod;
use Modules\Family\Models\Family;

class ContributionDueService
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function resolveAmountForFamily(ContributionPlan $plan, string $familyId, string $asOfDate): ?float
    {
        $override = $this->findActiveAssignment($plan, $familyId, $asOfDate);

        if ($override?->is_exempt) {
            return null;
        }

        if ($override) {
            return (float) $override->amount;
        }

        if ($plan->plan_type === 'individual') {
            return null;
        }

        return (float) $plan->default_amount;
    }

    /**
     * @return array<int, string>
     */
    public function getEnrolledFamilyIds(ContributionPlan $plan, string $asOfDate): array
    {
        if ($plan->plan_type === 'uniform') {
            return Family::query()
                ->where('tenant_id', $plan->tenant_id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
        }

        return ContributionPlanAssignment::forTenant($plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->where('is_exempt', false)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->pluck('family_id')
            ->unique()
            ->values()
            ->all();
    }

    public function generateForFamilies(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        array $familyIds,
        string $periodLabel,
        string $dueDate,
        ?float $amountDue = null,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($tenantId, $userId, $plan, $familyIds, $periodLabel, $dueDate, $amountDue, $notes): array {
            $validFamilies = Family::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $familyIds)
                ->pluck('id')
                ->all();

            if (count($validFamilies) !== count($familyIds)) {
                throw new \RuntimeException('One or more families are invalid for this tenant.');
            }

            $created = [];
            $asOfDate = Carbon::parse($dueDate)->toDateString();

            foreach ($validFamilies as $familyId) {
                $resolvedAmount = $amountDue ?? $this->resolveAmountForFamily($plan, $familyId, $asOfDate);
                if ($resolvedAmount === null || $resolvedAmount <= 0) {
                    continue;
                }

                $due = $this->upsertDue(
                    $tenantId,
                    $userId,
                    $plan,
                    $familyId,
                    $periodLabel,
                    $dueDate,
                    $resolvedAmount,
                    $notes
                );

                if ($due) {
                    $created[] = $due;
                }
            }

            $this->auditService->log(
                $tenantId,
                'due.bulk_generated',
                'plan',
                $plan->id,
                null,
                ['count' => count($created), 'period_label' => $periodLabel],
                ['family_ids' => $validFamilies]
            );

            return $created;
        });
    }

    public function generateCurrentPeriod(int $tenantId, int $userId, ContributionPlan $plan, ?Carbon $reference = null): array
    {
        if (!$this->isPlanActiveOnDate($plan, ($reference ?? Carbon::now())->toDateString())) {
            return [];
        }

        if ($plan->frequency === 'one_time') {
            $alreadyIssued = ContributionDue::forTenant($tenantId)
                ->where('plan_id', $plan->id)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($alreadyIssued) {
                return [];
            }
        }

        $period = ContributionPeriod::currentForPlan($plan, $reference);
        $dueDate = ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days);
        $familyIds = $this->getEnrolledFamilyIds($plan, $period['period_start']);

        return $this->generateForFamilies(
            $tenantId,
            $userId,
            $plan,
            $familyIds,
            $period['period_label'],
            $dueDate
        );
    }

    /**
     * @return array{processed:int, generated:int}
     */
    public function generateScheduledDuesForTenant(int $tenantId, int $userId): array
    {
        $processed = 0;
        $generated = 0;

        $plans = ContributionPlan::forTenant($tenantId)
            ->where('status', 'active')
            ->where('auto_generate', true)
            ->get();

        foreach ($plans as $plan) {
            $processed++;
            $created = $this->generateCurrentPeriod($tenantId, $userId, $plan);
            $generated += count($created);
        }

        return ['processed' => $processed, 'generated' => $generated];
    }

    /**
     * @return array{processed:int, generated:int}
     */
    public function generateScheduledDuesForAllTenants(): array
    {
        $processed = 0;
        $generated = 0;

        $tenantIds = ContributionPlan::query()
            ->where('status', 'active')
            ->where('auto_generate', true)
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $result = $this->generateScheduledDuesForTenant((int) $tenantId, 0);
            $processed += $result['processed'];
            $generated += $result['generated'];
        }

        return ['processed' => $processed, 'generated' => $generated];
    }

    public function waiveDue(int $tenantId, int $userId, ContributionDue $due, ?string $reason = null): ContributionDue
    {
        $oldStatus = $due->status;
        $due->status = 'waived';
        $due->notes = trim(($due->notes ? $due->notes . ' ' : '') . ($reason ?? 'Waived'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'due.waived',
            'due',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'waived', 'reason' => $reason]
        );

        return $due->fresh(['family', 'plan']);
    }

    public function cancelDue(int $tenantId, int $userId, ContributionDue $due, ?string $reason = null): ContributionDue
    {
        $oldStatus = $due->status;
        $due->status = 'cancelled';
        $due->notes = trim(($due->notes ? $due->notes . ' ' : '') . ($reason ?? 'Cancelled'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'due.cancelled',
            'due',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'reason' => $reason]
        );

        return $due->fresh(['family', 'plan']);
    }

    private function upsertDue(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        string $familyId,
        string $periodLabel,
        string $dueDate,
        float $amountDue,
        ?string $notes
    ): ?ContributionDue {
        $existing = ContributionDue::forTenant($tenantId)
            ->where('plan_id', $plan->id)
            ->where('family_id', $familyId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($existing && in_array($existing->status, ['paid', 'waived', 'cancelled'], true)) {
            return null;
        }

        if ($existing) {
            $existing->due_date = $dueDate;
            $existing->amount_due = $amountDue;
            if ($notes !== null) {
                $existing->notes = $notes;
            }
            $existing->updated_by = $userId;
            $existing->save();

            return $existing->fresh(['family', 'plan']);
        }

        return ContributionDue::create([
            'tenant_id' => $tenantId,
            'family_id' => $familyId,
            'plan_id' => $plan->id,
            'period_label' => $periodLabel,
            'due_date' => $dueDate,
            'amount_due' => $amountDue,
            'amount_paid' => 0,
            'status' => 'pending',
            'notes' => $notes,
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->fresh(['family', 'plan']);
    }

    private function findActiveAssignment(ContributionPlan $plan, string $familyId, string $asOfDate): ?ContributionPlanAssignment
    {
        return ContributionPlanAssignment::forTenant($plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isPlanActiveOnDate(ContributionPlan $plan, string $asOfDate): bool
    {
        if ($plan->status !== 'active') {
            return false;
        }

        if ($plan->start_date && $plan->start_date->toDateString() > $asOfDate) {
            return false;
        }

        if ($plan->end_date && $plan->end_date->toDateString() < $asOfDate) {
            return false;
        }

        return true;
    }
}
