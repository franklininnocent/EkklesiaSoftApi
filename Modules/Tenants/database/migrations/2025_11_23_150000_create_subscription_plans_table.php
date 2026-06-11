<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Unique plan identifier (e.g., free, basic, premium)');
            $table->string('name')->comment('Display name (e.g., Free Plan, Basic Plan)');
            $table->text('description')->nullable()->comment('Plan description');
            $table->decimal('price', 10, 2)->default(0)->comment('Monthly price');
            $table->integer('max_users')->default(10)->comment('Maximum allowed users');
            $table->integer('max_storage_mb')->default(100)->comment('Storage limit in MB');
            $table->json('features')->nullable()->comment('Enabled features array');
            $table->integer('display_order')->default(0)->comment('Order for display in UI');
            $table->boolean('active')->default(1)->comment('Is this plan active?');
            $table->boolean('is_default')->default(0)->comment('Is this the default plan for new tenants?');
            $table->timestamps();
            $table->softDeletes();

            $table->index('active');
            $table->index('display_order');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};

