<?php

namespace Modules\Subscriptions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;

/**
 * Idempotent: permissions, feature catalog, launch plans, policies, then tenant backfill.
 * Never overwrites catalog rows edited by Super Admin.
 */
class SubscriptionsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionsPermissionCatalog::syncPermissionsAndRoles();
        User::flushRequestPermissionCache();

        $catalog = app(CatalogBootstrapper::class)->run();
        $backfill = app(TenantSubscriptionBackfiller::class)->run();

        $this->command?->info(sprintf(
            'Subscriptions: %d features, %d plans created, %d legacy plans grandfathered, %d tenants backfilled (%d overrides).',
            $catalog['features_created'],
            count($catalog['plans_created']),
            count($catalog['legacy_converted']),
            $backfill['tenants_backfilled'],
            $backfill['overrides_created'],
        ));
    }
}
