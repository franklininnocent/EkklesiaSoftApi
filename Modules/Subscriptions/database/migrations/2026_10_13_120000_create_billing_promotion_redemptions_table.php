<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_promotion_id')->constrained('billing_promotions')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('billing_order_id')->nullable()->constrained('billing_orders')->nullOnDelete();
            $table->string('status', 32)->default('reserved');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['billing_promotion_id', 'billing_order_id'], 'billing_promo_redemption_order_unique');
            $table->index(['billing_promotion_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_promotion_redemptions');
    }
};
