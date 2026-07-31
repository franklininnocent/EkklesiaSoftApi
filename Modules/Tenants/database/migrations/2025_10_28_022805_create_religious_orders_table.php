<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the religious_orders table - a normalized lookup table
     * for Catholic religious orders and congregations.
     */
    public function up(): void
    {
        Schema::create('religious_orders', function (Blueprint $table) {
            $table->id();
            $table->string('abbreviation', 20)->unique()->comment('Standard abbreviation (e.g., SJ, OFM, OMI, MSFS)');
            $table->string('full_name', 255)->comment('Full name of the religious order/congregation');
            $table->string('common_name', 255)->nullable()->comment('Common name (e.g., Jesuits, Franciscans)');
            $table->enum('type', ['order', 'congregation', 'society', 'institute'])->default('order')->comment('Type of religious community');
            $table->enum('branch', ['male', 'female', 'mixed'])->default('male')->comment('Gender branch');
            $table->text('description')->nullable()->comment('Description and charism of the order');
            $table->integer('founded_year')->nullable()->comment('Year the order was founded');
            $table->string('founder', 255)->nullable()->comment('Name of founder(s)');
            $table->string('website', 500)->nullable()->comment('Official website URL');
            $table->integer('display_order')->default(1)->comment('Display order in dropdowns');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->timestamps();
            
            $table->index('abbreviation');
            $table->index('type');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('religious_orders');
    }
};
