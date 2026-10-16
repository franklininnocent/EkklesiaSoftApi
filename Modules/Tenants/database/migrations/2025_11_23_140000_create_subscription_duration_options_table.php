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
        Schema::create('subscription_duration_options', function (Blueprint $table) {
            $table->id();
            $table->integer('months')->unique()->comment('Duration in months');
            $table->string('label')->comment('Display label (e.g., "1 Month", "1 Year", "3 Years")');
            $table->integer('display_order')->default(0)->comment('Order for display in dropdown');
            $table->boolean('active')->default(1)->comment('Whether this option is active');
            $table->timestamps();
            
            $table->index('active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_duration_options');
    }
};

