<?php

namespace Modules\Tenants\Console\Commands;

use Illuminate\Console\Command;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

class GrandfatherSubscriptionViewOnly extends Command
{
    protected $signature = 'tenants:subscription-grandfather-view-only
                            {--dry-run : Report impact without writing deadlines}
                            {--apply : Apply grandfather deadlines (required to mutate data)}';

    protected $description = 'One-time view-only grandfather for eligible expired churches (does not change subscription end dates).';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if (! $dryRun && ! $apply) {
            $this->error('Use --dry-run to preview or --apply to write grandfather deadlines.');

            return self::FAILURE;
        }

        $eligible = [];
        $excluded = [];
        $evaluated = 0;

        Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($subscriptionService, &$eligible, &$excluded, &$evaluated) {
            foreach ($tenants as $tenant) {
                $evaluated++;
                $reason = $this->exclusionReason($tenant, $subscriptionService);
                if ($reason !== null) {
                    $excluded[] = ['tenant' => $tenant, 'reason' => $reason];

                    continue;
                }

                $viewOnlyDays = $subscriptionService->effectiveViewOnlyAllowanceDays($tenant);
                if ($viewOnlyDays <= 0) {
                    $excluded[] = ['tenant' => $tenant, 'reason' => 'view_only_allowance_zero'];

                    continue;
                }

                $access = $subscriptionService->evaluateAccess($tenant, true, true);
                if ($access['access_mode'] !== SubscriptionService::ACCESS_MODE_LOCKED) {
                    $excluded[] = ['tenant' => $tenant, 'reason' => 'would_not_be_locked'];

                    continue;
                }

                $deadline = now()->addDays($viewOnlyDays);
                $eligible[] = ['tenant' => $tenant, 'deadline' => $deadline, 'access' => $access];
            }
        });

        $this->info("Evaluated {$evaluated} tenant(s).");
        $this->info('Eligible for grandfather: '.count($eligible));
        $this->info('Excluded: '.count($excluded));

        foreach ($eligible as $row) {
            $t = $row['tenant'];
            $this->line(sprintf(
                '  [eligible] #%d %s — ends %s — grandfather until %s',
                $t->id,
                $t->name,
                $t->subscription_ends_at?->toIso8601String() ?? 'n/a',
                $row['deadline']->toIso8601String()
            ));
        }

        if ($dryRun) {
            $this->warn('Dry run complete. No database changes were made.');

            return self::SUCCESS;
        }

        $applied = 0;
        foreach ($eligible as $row) {
            $tenant = $row['tenant']->fresh();
            if ($tenant->view_only_grandfather_until !== null) {
                continue;
            }
            $subscriptionService->setGrandfatherUntil($tenant, $row['deadline'], null);
            $applied++;
        }

        $this->info("Applied grandfather deadline to {$applied} tenant(s).");

        return self::SUCCESS;
    }

    private function exclusionReason(Tenant $tenant, SubscriptionService $subscriptionService): ?string
    {
        if (! empty($tenant->subscription_suspended_at)) {
            return 'suspended';
        }

        if ((int) $tenant->active !== 1) {
            return 'inactive';
        }

        if ($tenant->subscription_ends_at === null) {
            return 'no_subscription_end';
        }

        if ($subscriptionService->resolveStatus($tenant) !== SubscriptionService::STATUS_EXPIRED) {
            return 'not_expired_status';
        }

        if ($tenant->view_only_grandfather_until !== null) {
            return 'already_grandfathered';
        }

        return null;
    }
}
