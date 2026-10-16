<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\PlanVersionService;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;

class ApplyScheduledSubscriptionChanges extends Command
{
    protected $signature = 'subscriptions:apply-scheduled';

    protected $description = 'Activate scheduled plan versions and apply due scheduled tenant plan changes';

    public function handle(PlanVersionService $versions, SubscriptionPlanChangeService $changes): int
    {
        $activated = $versions->activateDueScheduled();

        $applied = 0;
        TenantSubscription::query()
            ->where('record_status', TenantSubscription::RECORD_PENDING)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->each(function (TenantSubscription $pending) use ($changes, &$applied): void {
                if ($changes->applyDuePending($pending)) {
                    $applied++;
                }
            });

        $this->info("Activated {$activated} scheduled plan versions; applied {$applied} scheduled tenant plan changes.");

        return self::SUCCESS;
    }
}
