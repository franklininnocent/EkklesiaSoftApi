<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('DRAFT');
            $table->char('currency_code', 3)->default('INR');
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->decimal('annual_price', 12, 2)->nullable();
            $table->decimal('setup_fee', 12, 2)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_rate_percent', 5, 2)->nullable();
            $table->string('tax_label', 40)->nullable();
            $table->unsignedInteger('trial_days')->nullable();
            $table->json('billing_intervals')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('retired_by')->nullable();
            $table->text('change_notes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version_number']);
            $table->index(['status', 'effective_from']);
        });

        DB::statement("CREATE UNIQUE INDEX plan_versions_one_active_per_plan ON plan_versions (plan_id) WHERE status = 'ACTIVE'");
        DB::statement("CREATE UNIQUE INDEX plan_versions_one_scheduled_per_plan ON plan_versions (plan_id) WHERE status = 'SCHEDULED'");

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->restrictOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->bigInteger('numeric_value')->nullable()
                ->comment('Limit/quota value; NULL on an enabled limit feature means unlimited');
            $table->string('tier_value', 40)->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['plan_version_id', 'feature_id']);
            $table->index('feature_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_status_chk CHECK (status IN ('DRAFT','SCHEDULED','ACTIVE','RETIRED'))");
            DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_prices_chk CHECK ((monthly_price IS NULL OR monthly_price >= 0) AND (annual_price IS NULL OR annual_price >= 0) AND (setup_fee IS NULL OR setup_fee >= 0))');
            DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_tax_chk CHECK (tax_rate_percent IS NULL OR (tax_rate_percent >= 0 AND tax_rate_percent <= 100))');
            DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_currency_chk CHECK (currency_code ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE plan_entitlements ADD CONSTRAINT plan_entitlements_numeric_chk CHECK (numeric_value IS NULL OR numeric_value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_versions');
    }
};
