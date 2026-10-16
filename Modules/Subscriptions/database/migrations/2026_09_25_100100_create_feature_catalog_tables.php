<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 40)->default('general');
            $table->string('module_key', 64)->nullable()
                ->comment('Owning application module (informational / navigation grouping)');
            $table->string('feature_type', 16)->default('BOOLEAN');
            $table->string('unit', 20)->nullable();
            $table->string('legacy_key', 64)->nullable()->unique()
                ->comment('Legacy tenants.features key this code replaces');
            $table->boolean('is_core')->default(false)
                ->comment('System-safe feature: always enabled regardless of plan');
            $table->boolean('legacy_default')->default(false)
                ->comment('Ungated before plan entitlements existed; enabled for grandfathered plans');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('tier_options')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['category', 'display_order']);
            $table->index('is_active');
        });

        Schema::create('feature_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->foreignId('depends_on_feature_id')->constrained('features')->restrictOnDelete();
            $table->string('dependency_type', 16)->default('REQUIRES');
            $table->timestamps();

            $table->unique(['feature_id', 'depends_on_feature_id'], 'feature_dependencies_pair_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE features ADD CONSTRAINT features_type_chk CHECK (feature_type IN ('BOOLEAN','LIMIT','QUOTA','TIER','MODULE','USAGE'))");
            DB::statement("ALTER TABLE features ADD CONSTRAINT features_code_format_chk CHECK (code ~ '^[A-Z][A-Z0-9_]{1,63}$')");
            DB::statement('ALTER TABLE feature_dependencies ADD CONSTRAINT feature_dependencies_no_self_chk CHECK (feature_id <> depends_on_feature_id)');
            DB::statement("ALTER TABLE feature_dependencies ADD CONSTRAINT feature_dependencies_type_chk CHECK (dependency_type IN ('REQUIRES'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_dependencies');
        Schema::dropIfExists('features');
    }
};
