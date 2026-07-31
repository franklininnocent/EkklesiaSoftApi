<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the states table for storing geographic state/province data
     * related to countries.
     */
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            
            // Foreign Key
            $table->foreignId('country_id')
                ->constrained('countries')
                ->onDelete('cascade')
                ->index();
            
            // Basic Information
            $table->string('name', 100)->index();
            $table->string('state_code', 10)->nullable()->comment('State/Province code');
            $table->string('type', 50)->nullable()->comment('State, Province, Region, Territory, etc.');
            
            // Geographic Information
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            // Status
            $table->boolean('active')->default(true)->index();
            
            // Timestamps
            $table->timestamps();
            
            // Composite indexes for better performance
            $table->index(['country_id', 'active']);
            $table->index(['country_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
