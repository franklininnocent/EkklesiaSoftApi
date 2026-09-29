<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Services\ContributionDueService;
use Modules\Tenants\Support\SubscriptionJobWriteGuard;

class GenerateFullContributionScheduleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly string $planId,
        private readonly ?array $familyIds = null,
        private readonly bool $reconcile = false,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->planId.':'.($this->reconcile ? 'reconcile' : 'generate');
    }

    public function handle(
        ContributionDueService $dueService,
        SubscriptionJobWriteGuard $writeGuard,
    ): void {
        if (! $writeGuard->allowsMutationsForTenantId($this->tenantId)) {
            return;
        }

        $plan = ContributionPlan::forTenant($this->tenantId)->find($this->planId);
        if (! $plan || $plan->status !== 'active') {
            return;
        }

        if ($this->reconcile) {
            $dueService->reconcileScheduleOnPlanUpdate($this->tenantId, $this->userId, $plan, true);
        } else {
            $dueService->generateSchedule($this->tenantId, $this->userId, $plan, $this->familyIds, true);
        }
    }
}
