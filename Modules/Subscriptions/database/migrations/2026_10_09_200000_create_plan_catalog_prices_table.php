<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;

/**
 * Country-specific SaaS list prices (billed currency on the row).
 * Selection is by tenant official-address country; amounts are never converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_catalog_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->decimal('annual_price', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'country_code']);
            $table->index('country_code');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE plan_catalog_prices ADD CONSTRAINT plan_catalog_prices_country_chk CHECK (country_code ~ '^[A-Z]{2}$')");
            DB::statement("ALTER TABLE plan_catalog_prices ADD CONSTRAINT plan_catalog_prices_currency_chk CHECK (currency_code ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE plan_catalog_prices ADD CONSTRAINT plan_catalog_prices_amounts_chk CHECK ((monthly_price IS NULL OR monthly_price >= 0) AND (annual_price IS NULL OR annual_price >= 0))');
        }

        app(CatalogBootstrapper::class)->syncCatalogPrices();
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_catalog_prices');
    }
};
