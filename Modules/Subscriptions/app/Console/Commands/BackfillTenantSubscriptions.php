<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;

class BackfillTenantSubscriptions extends Command
{
    protected $signature = 'subscriptions:backfill {--dry-run : Report what would change without writing}';

    protected $description = 'Seed the feature catalog (insert-if-missing), grandfather legacy plans and pin tenants without a subscription';

    public function handle(CatalogBootstrapper $bootstrapper, TenantSubscriptionBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $catalog = $bootstrapper->run();
            $this->info(sprintf(
                'Catalog: %d features created, %d missing entitlements attached (off), legacy plans grandfathered: [%s], launch plans created: [%s]',
                $catalog['features_created'],
                $catalog['entitlements_attached'],
                implode(', ', $catalog['legacy_converted']),
                implode(', ', $catalog['plans_created'])
            ));
        }

        $stats = $backfiller->run($dryRun);
        $this->info(sprintf(
            '%s %d tenants, %d overrides; %d already had a subscription.',
            $dryRun ? 'Would backfill' : 'Backfilled',
            $stats['tenants_backfilled'],
            $stats['overrides_created'],
            $stats['skipped']
        ));

        if ($stats['fallback_plan_tenants'] !== []) {
            $this->warn('Tenants with an unknown plan key were pinned to LEGACY_FREE: '.implode(', ', $stats['fallback_plan_tenants']));
        }

        return self::SUCCESS;
    }
}
