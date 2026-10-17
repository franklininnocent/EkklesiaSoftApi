<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;

/**
 * Insert any country list prices that were added to the catalog definition after the
 * original plan_catalog_prices table was created (insert-if-missing only).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(CatalogBootstrapper::class)->syncCatalogPrices();
    }

    public function down(): void
    {
        // Insert-if-missing data backfill; do not delete commercial prices on rollback.
    }
};
