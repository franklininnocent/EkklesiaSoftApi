<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing subscription tables (owned by the Tenants module) instead of
 * creating parallel ones: subscription_plans gains catalog identity, subscription_settings
 * gains the default plan + policy document, and audits gain request context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'code')) {
                $table->string('code', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('subscription_plans', 'slug')) {
                $table->string('slug', 100)->nullable()->unique();
            }
            if (! Schema::hasColumn('subscription_plans', 'short_description')) {
                $table->string('short_description', 500)->nullable();
            }
            if (! Schema::hasColumn('subscription_plans', 'pricing_type')) {
                $table->string('pricing_type', 16)->default('FIXED');
            }
            if (! Schema::hasColumn('subscription_plans', 'status')) {
                $table->string('status', 16)->default('ACTIVE');
            }
            if (! Schema::hasColumn('subscription_plans', 'is_public')) {
                $table->boolean('is_public')->default(false);
            }
            if (! Schema::hasColumn('subscription_plans', 'is_featured')) {
                $table->boolean('is_featured')->default(false);
            }
            if (! Schema::hasColumn('subscription_plans', 'is_assignable')) {
                $table->boolean('is_assignable')->default(true);
            }
            if (! Schema::hasColumn('subscription_plans', 'is_legacy')) {
                $table->boolean('is_legacy')->default(false);
            }
            if (! Schema::hasColumn('subscription_plans', 'badge_label')) {
                $table->string('badge_label', 60)->nullable();
            }
            if (! Schema::hasColumn('subscription_plans', 'metadata')) {
                $table->json('metadata')->nullable();
            }
            if (! Schema::hasColumn('subscription_plans', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
            if (! Schema::hasColumn('subscription_plans', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (! Schema::hasColumn('subscription_plans', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->index(['status', 'is_public', 'display_order'], 'subscription_plans_status_public_idx');
        });

        Schema::table('subscription_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_settings', 'default_plan_id')) {
                $table->foreignId('default_plan_id')->nullable()
                    ->constrained('subscription_plans')->nullOnDelete();
            }
            if (! Schema::hasColumn('subscription_settings', 'policies')) {
                $table->json('policies')->nullable()
                    ->comment('Validated subscription policy document (thresholds, trials, tax, over-limit behaviour)');
            }
        });

        Schema::table('tenant_subscription_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_subscription_audits', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
            if (! Schema::hasColumn('tenant_subscription_audits', 'user_agent')) {
                $table->string('user_agent', 255)->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_pricing_type_chk CHECK (pricing_type IN ('FIXED','CUSTOM','FREE'))");
            DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_status_chk CHECK (status IN ('DRAFT','ACTIVE','ARCHIVED'))");
            DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_code_format_chk CHECK (code IS NULL OR code ~ '^[A-Z][A-Z0-9_]{1,63}$')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_pricing_type_chk');
            DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_status_chk');
            DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_code_format_chk');
        }

        Schema::table('tenant_subscription_audits', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });

        Schema::table('subscription_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_plan_id');
            $table->dropColumn('policies');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex('subscription_plans_status_public_idx');
            $table->dropUnique(['code']);
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'code', 'slug', 'short_description', 'pricing_type', 'status', 'is_public',
                'is_featured', 'is_assignable', 'is_legacy', 'badge_label', 'metadata',
                'archived_at', 'created_by', 'updated_by',
            ]);
        });
    }
};
