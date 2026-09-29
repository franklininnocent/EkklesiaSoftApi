<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;

/**
 * Seeds platform permissions, the feature catalog and launch plans, grandfathers legacy
 * plans, and pins every existing tenant to its grandfathered plan version (zero-loss).
 * Idempotent: safe to re-run via `php artisan subscriptions:backfill`.
 */
return new class extends Migration
{
    public function up(): void
    {
        SubscriptionsPermissionCatalog::syncPermissionsAndRoles();
        User::flushRequestPermissionCache();

        app(CatalogBootstrapper::class)->run();
        app(TenantSubscriptionBackfiller::class)->run();
    }

    public function down(): void
    {
        // Data migration: schema rollback removes the tables; permissions remain (no-op).
    }
};
