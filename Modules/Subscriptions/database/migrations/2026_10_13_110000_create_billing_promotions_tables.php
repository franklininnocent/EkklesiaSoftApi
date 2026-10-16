<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 160);
            $table->string('status', 32)->default('draft');
            $table->string('discount_type', 32); // percent|fixed_amount|fixed_price
            $table->decimal('discount_value', 14, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('discounted_periods')->nullable();
            $table->string('renewal_policy', 64)->default('list_price');
            $table->boolean('requires_assignment')->default(true);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemption_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_promotion_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_promotion_id')->constrained('billing_promotions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('billing_interval', 16)->nullable();
            $table->unique(['billing_promotion_id', 'plan_id', 'billing_interval'], 'billing_promo_plan_interval_unique');
        });

        Schema::create('billing_tenant_price_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('billing_promotion_id')->nullable()->constrained('billing_promotions')->nullOnDelete();
            $table->string('billing_interval', 16)->nullable();
            $table->decimal('negotiated_amount', 14, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('scope', 32)->default('next_purchase');
            $table->string('renewal_policy', 64)->default('list_price');
            $table->unsignedSmallInteger('discounted_periods')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tenant_price_assignments');
        Schema::dropIfExists('billing_promotion_plans');
        Schema::dropIfExists('billing_promotions');
    }
};
