<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Normalize Archdioceses Table with Geographic Foreign Keys
 * 
 * This migration transforms the archdioceses table from denormalized structure
 * (using VARCHAR fields for country and region) to a fully normalized structure
 * with proper foreign key relationships to countries and states tables.
 * 
 * Benefits:
 * - Data integrity through foreign key constraints
 * - Referential integrity (cascading updates/deletes)
 * - Better query performance with indexed foreign keys
 * - Elimination of data redundancy
 * - Consistency with multi-tenant architecture standards
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            // Add new foreign key columns (nullable for migration compatibility)
            $table->foreignId('country_id')
                ->nullable()
                ->after('headquarters_city')
                ->constrained('countries')
                ->onDelete('set null')
                ->onUpdate('cascade')
                ->comment('Foreign key to countries table');
            
            $table->foreignId('state_id')
                ->nullable()
                ->after('country_id')
                ->constrained('states')
                ->onDelete('set null')
                ->onUpdate('cascade')
                ->comment('Foreign key to states/provinces table');
            
            // Add indexes for better query performance
            $table->index('country_id');
            $table->index('state_id');
            $table->index(['country_id', 'state_id', 'active']);
            
            // NOTE: Keeping old VARCHAR columns (country, region) temporarily for data migration
            // They will be removed in a future migration after data is fully migrated
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            
            // Drop indexes
            $table->dropIndex(['country_id']);
            $table->dropIndex(['state_id']);
            $table->dropIndex(['country_id', 'state_id', 'active']);
            
            // Drop columns
            $table->dropColumn(['country_id', 'state_id']);
        });
    }
};
