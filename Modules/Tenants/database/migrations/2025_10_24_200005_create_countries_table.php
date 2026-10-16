<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the countries table for storing geographic country data
     * with ISO codes, phone codes, currency, and coordinates.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            
            // Basic Information
            $table->string('name', 100)->index();
            $table->string('iso3', 3)->unique()->comment('ISO 3166-1 alpha-3 code');
            $table->string('iso2', 2)->unique()->comment('ISO 3166-1 alpha-2 code');
            $table->string('numeric_code', 3)->nullable();
            $table->string('phone_code', 10)->nullable();
            
            // Additional Information
            $table->string('capital', 100)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('currency_name', 100)->nullable();
            $table->string('currency_symbol', 10)->nullable();
            $table->string('tld', 10)->nullable()->comment('Top Level Domain');
            $table->string('native', 100)->nullable()->comment('Native name');
            
            // Geographic Information
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('region', 50)->nullable();
            $table->string('subregion', 50)->nullable();
            
            // Emoji & Unicode
            $table->string('emoji', 10)->nullable();
            $table->string('emoji_u', 50)->nullable();
            
            // Status
            $table->boolean('active')->default(true)->index();
            
            // Timestamps
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['active', 'name']);
            $table->index('region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
