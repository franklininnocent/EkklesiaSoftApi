<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Services\RecurringDonationScheduleService;
use Modules\Tenants\Support\SubscriptionJobWriteGuard;

class RunRecurringDonationSchedulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?int $tenantId = null)
    {
    }

    public function handle(
        RecurringDonationScheduleService $recurringService,
        SubscriptionJobWriteGuard $writeGuard,
    ): void {
        if ($this->tenantId !== null && ! $writeGuard->allowsMutationsForTenantId($this->tenantId)) {
            return;
        }

        $recurringService->runDueSchedules($this->tenantId);
    }
}
