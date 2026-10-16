<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;

/**
 * Mass Intentions was added to SubscriptionCatalogDefinition after the first catalog
 * seed. Insert-if-missing never ran again, so Super Admin Features had no MASS_INTENTIONS
 * row and plans could not be entitled. Re-run the bootstrapper (still insert-if-missing;
 * existing plan access is unchanged — new boolean rows attach as disabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(CatalogBootstrapper::class)->run();
    }

    public function down(): void
    {
        // Catalog registration is additive; Super Admins may already have enabled the feature.
    }
};
