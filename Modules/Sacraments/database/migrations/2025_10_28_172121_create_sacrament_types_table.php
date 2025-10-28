<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the sacrament_types table - a lookup table for Catholic sacraments
     */
    public function up(): void
    {
        Schema::create('sacrament_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Sacrament name (e.g., Baptism, Confirmation)');
            $table->string('code', 50)->unique()->comment('Unique identifier code (e.g., BAPTISM, CONFIRMATION)');
            $table->string('category', 50)->comment('Sacrament category: initiation, healing, service');
            $table->text('description')->nullable()->comment('Brief description of the sacrament');
            $table->text('theological_significance')->nullable()->comment('Theological meaning and importance');
            $table->integer('display_order')->default(1)->comment('Display order in lists');
            $table->integer('min_age_years')->nullable()->comment('Minimum age requirement in years');
            $table->integer('typical_age_years')->nullable()->comment('Typical age when received');
            $table->boolean('repeatable')->default(false)->comment('Can be received multiple times');
            $table->boolean('requires_minister')->default(true)->comment('Requires ordained minister');
            $table->string('minister_type', 50)->nullable()->comment('Required minister: priest, bishop, deacon');
            $table->boolean('active')->default(true)->comment('Is this sacrament type active');
            $table->timestamps();
            
            $table->index('code');
            $table->index('category');
            $table->index('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacrament_types');
    }
};
