<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Services\RecurringDonationScheduleService;

class RunRecurringDonationSchedulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?int $tenantId = null)
    {
    }

    public function handle(RecurringDonationScheduleService $recurringService): void
    {
        $recurringService->runDueSchedules($this->tenantId);
    }
}
