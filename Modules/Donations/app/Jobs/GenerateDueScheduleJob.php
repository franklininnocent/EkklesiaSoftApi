<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Services\ContributionDueService;

class GenerateDueScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly string $planId,
        private readonly array $familyIds,
        private readonly string $periodLabel,
        private readonly string $dueDate,
        private readonly ?float $amountDue = null,
        private readonly ?string $notes = null
    ) {
    }

    public function handle(ContributionDueService $dueService): void
    {
        $plan = ContributionPlan::forTenant($this->tenantId)->find($this->planId);
        if (!$plan) {
            return;
        }

        $dueService->generateForFamilies(
            $this->tenantId,
            $this->userId,
            $plan,
            $this->familyIds,
            $this->periodLabel,
            $this->dueDate,
            $this->amountDue,
            $this->notes
        );
    }
}
