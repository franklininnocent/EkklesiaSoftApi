<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-level subscription records. Lifecycle status (trial / active / grace / expired /
 * suspended) stays on the tenants table and is resolved by the existing SubscriptionService;
 * tenant_subscriptions only records which plan version a tenant is pinned to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->string('record_status', 16)->default('CURRENT');
            $table->string('billing_interval', 16)->default('MONTHLY');
            $table->char('currency_code', 3)->default('INR');
            $table->decimal('contracted_price', 12, 2)->nullable()
                ->comment('Agreed price per billing interval (no payment processing)');
            $table->json('custom_limits')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('source', 32)->default('ASSIGNMENT');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'record_status']);
            $table->index(['record_status', 'scheduled_for']);
            $table->index('plan_version_id');
        });

        DB::statement("CREATE UNIQUE INDEX tenant_subscriptions_one_current ON tenant_subscriptions (tenant_id) WHERE record_status = 'CURRENT'");
        DB::statement("CREATE UNIQUE INDEX tenant_subscriptions_one_pending ON tenant_subscriptions (tenant_id) WHERE record_status = 'PENDING'");

        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->restrictOnDelete();
            $table->string('mode', 16);
            $table->bigInteger('numeric_value')->nullable();
            $table->string('tier_value', 40)->nullable();
            $table->string('reason', 500);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index('effective_until');
        });

        DB::statement('CREATE UNIQUE INDEX tenant_entitlement_overrides_one_open ON tenant_entitlement_overrides (tenant_id, feature_id) WHERE revoked_at IS NULL');

        Schema::create('tenant_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->bigInteger('usage_value')->default(0);
            $table->bigInteger('limit_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'feature_id', 'snapshot_date'], 'tenant_usage_snapshots_daily_unique');
            $table->index('snapshot_date');
        });

        Schema::create('subscription_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->foreignId('current_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('requested_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('requested_billing_interval', 16)->nullable();
            $table->string('feature_code', 64)->nullable()
                ->comment('Feature that triggered the request (upgrade prompt context)');
            $table->text('message')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('resulting_subscription_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement("CREATE UNIQUE INDEX subscription_upgrade_requests_one_pending ON subscription_upgrade_requests (tenant_id) WHERE status = 'PENDING'");

        Schema::create('subscription_catalog_audits', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('operation', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT tenant_subscriptions_record_status_chk CHECK (record_status IN ('PENDING','CURRENT','SUPERSEDED','CANCELLED'))");
            DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT tenant_subscriptions_interval_chk CHECK (billing_interval IN ('MONTHLY','ANNUAL','CUSTOM','NONE'))");
            DB::statement('ALTER TABLE tenant_subscriptions ADD CONSTRAINT tenant_subscriptions_price_chk CHECK (contracted_price IS NULL OR contracted_price >= 0)');
            DB::statement("ALTER TABLE tenant_entitlement_overrides ADD CONSTRAINT tenant_entitlement_overrides_mode_chk CHECK (mode IN ('ENABLE','DISABLE','SET_LIMIT','UNLIMITED','SET_TIER'))");
            DB::statement('ALTER TABLE tenant_entitlement_overrides ADD CONSTRAINT tenant_entitlement_overrides_window_chk CHECK (effective_until IS NULL OR effective_from IS NULL OR effective_until > effective_from)');
            DB::statement("ALTER TABLE subscription_upgrade_requests ADD CONSTRAINT subscription_upgrade_requests_status_chk CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_catalog_audits');
        Schema::dropIfExists('subscription_upgrade_requests');
        Schema::dropIfExists('tenant_usage_snapshots');
        Schema::dropIfExists('tenant_entitlement_overrides');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
