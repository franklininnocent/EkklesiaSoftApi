<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds geographic ID columns to addresses table for normalized storage.
     * District remains as string field for manual entry.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Add new ID-based columns (nullable for backwards compatibility)
            $table->foreignId('country_id')
                ->nullable()
                ->after('pin_zip_code')
                ->constrained('countries')
                ->onDelete('set null')
                ->comment('Foreign key to countries table');
                
            $table->foreignId('state_id')
                ->nullable()
                ->after('country_id')
                ->constrained('states')
                ->onDelete('set null')
                ->comment('Foreign key to states table');
            
            // Add indexes for better query performance
            $table->index('country_id');
            $table->index('state_id');
            
            // Note: district remains as VARCHAR string field for manual text entry
            // Note: Keep old country and state_province string columns for backwards compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            
            // Drop indexes
            $table->dropIndex(['country_id']);
            $table->dropIndex(['state_id']);
            
            // Drop columns
            $table->dropColumn(['country_id', 'state_id']);
        });
    }
};
