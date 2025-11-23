<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Add Performance Indexes to church_leadership table
 * 
 * Optimizes queries for church leadership listing by adding indexes
 * for commonly used ordering and filtering columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('church_leadership', function (Blueprint $table) {
            // Composite index for the ordered() scope query
            // This covers: active DESC, is_primary DESC, display_order ASC, appointed_date DESC
            // The index will significantly speed up the default ordering query
            try {
                DB::statement('
                    CREATE INDEX IF NOT EXISTS idx_church_leadership_ordering 
                    ON church_leadership(tenant_id, active DESC, is_primary DESC, display_order ASC, appointed_date DESC NULLS LAST)
                ');
            } catch (\Exception $e) {
                // Index might already exist, continue
                \Log::warning('Index idx_church_leadership_ordering might already exist: ' . $e->getMessage());
            }
            
            // Index for active status filtering (if not already covered)
            try {
                DB::statement('
                    CREATE INDEX IF NOT EXISTS idx_church_leadership_active 
                    ON church_leadership(tenant_id, active)
                ');
            } catch (\Exception $e) {
                \Log::warning('Index idx_church_leadership_active might already exist: ' . $e->getMessage());
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_leadership', function (Blueprint $table) {
            try {
                DB::statement('DROP INDEX IF EXISTS idx_church_leadership_ordering');
            } catch (\Exception $e) {
                // Ignore if index doesn't exist
            }
            
            try {
                DB::statement('DROP INDEX IF EXISTS idx_church_leadership_active');
            } catch (\Exception $e) {
                // Ignore if index doesn't exist
            }
        });
    }
};

