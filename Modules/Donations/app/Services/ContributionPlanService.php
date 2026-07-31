<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\ContributionPlanRevisionHistory;

class ContributionPlanService
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function create(int $tenantId, int $userId, array $payload): ContributionPlan
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): ContributionPlan {
            $assignments = $payload['assignments'] ?? [];
            unset($payload['assignments']);

            $payload['tenant_id'] = $tenantId;
            $payload['created_by'] = $userId;
            $payload['updated_by'] = $userId;

            $plan = ContributionPlan::create($payload);

            if ($plan->plan_type === 'individual' && !empty($assignments)) {
                $this->syncAssignments($tenantId, $userId, $plan, $assignments);
            }

            $this->auditService->log($tenantId, 'plan.created', 'plan', $plan->id, null, $plan->toArray());

            return $plan->fresh(['fund', 'assignments.family']);
        });
    }

    public function update(int $tenantId, int $userId, ContributionPlan $plan, array $payload): ContributionPlan
    {
        return DB::transaction(function () use ($tenantId, $userId, $plan, $payload): ContributionPlan {
            $assignments = $payload['assignments'] ?? null;
            unset($payload['assignments']);

            $oldDefaultAmount = (float) $plan->default_amount;

            $plan->fill($payload);
            $plan->updated_by = $userId;
            $plan->save();

            if (isset($payload['default_amount']) && (float) $payload['default_amount'] !== $oldDefaultAmount) {
                $this->recordRevision(
                    $tenantId,
                    $plan->id,
                    null,
                    'default_amount',
                    $oldDefaultAmount,
                    (float) $payload['default_amount'],
                    $payload['effective_from'] ?? now()->toDateString(),
                    $payload['revision_reason'] ?? null,
                    $userId
                );
            }

            if ($assignments !== null) {
                $this->syncAssignments($tenantId, $userId, $plan, $assignments);
            }

            $this->auditService->log(
                $tenantId,
                'plan.updated',
                'plan',
                $plan->id,
                ['default_amount' => $oldDefaultAmount],
                $plan->fresh()->toArray()
            );

            return $plan->fresh(['fund', 'assignments.family']);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $assignments
     */
    public function syncAssignments(int $tenantId, int $userId, ContributionPlan $plan, array $assignments): void
    {
        foreach ($assignments as $row) {
            $familyId = $row['family_id'];
            $effectiveFrom = $row['effective_from'] ?? now()->toDateString();
            $amount = (float) ($row['amount'] ?? $plan->default_amount);

            $existing = ContributionPlanAssignment::forTenant($tenantId)
                ->where('plan_id', $plan->id)
                ->where('family_id', $familyId)
                ->where('effective_from', $effectiveFrom)
                ->first();

            $oldAmount = $existing ? (float) $existing->amount : null;

            $assignment = ContributionPlanAssignment::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'family_id' => $familyId,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'amount' => $amount,
                    'effective_to' => $row['effective_to'] ?? null,
                    'is_exempt' => (bool) ($row['is_exempt'] ?? false),
                    'status' => $row['status'] ?? 'active',
                    'notes' => $row['notes'] ?? null,
                    'updated_by' => $userId,
                    'created_by' => $existing?->created_by ?? $userId,
                ]
            );

            if ($oldAmount === null || $oldAmount !== $amount) {
                $this->recordRevision(
                    $tenantId,
                    $plan->id,
                    $familyId,
                    'assignment_amount',
                    $oldAmount,
                    $amount,
                    $effectiveFrom,
                    $row['revision_reason'] ?? null,
                    $userId
                );
            }

            unset($assignment);
        }
    }

    public function recordRevision(
        int $tenantId,
        string $planId,
        ?string $familyId,
        string $changeType,
        ?float $oldAmount,
        ?float $newAmount,
        ?string $effectiveFrom,
        ?string $reason,
        int $userId
    ): ContributionPlanRevisionHistory {
        return ContributionPlanRevisionHistory::create([
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'family_id' => $familyId,
            'change_type' => $changeType,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'changed_by' => $userId,
        ]);
    }
}
