<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds composite indexes to improve query performance for common filtering and sorting patterns
     */
    public function up(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            // Composite index for tenant + date_administered (most common filter/sort combination)
            try {
                $table->index(['tenant_id', 'date_administered'], 'idx_sacraments_tenant_date');
            } catch (\Exception $e) {
                // Index may already exist, continue
            }

            // Composite index for tenant + status (common filter)
            try {
                $table->index(['tenant_id', 'status'], 'idx_sacraments_tenant_status');
            } catch (\Exception $e) {
                // Index may already exist, continue
            }

            // Composite index for tenant + sacrament_type_id (common filter)
            try {
                $table->index(['tenant_id', 'sacrament_type_id'], 'idx_sacraments_tenant_type');
            } catch (\Exception $e) {
                // Index may already exist, continue
            }

            // Composite index for tenant + date_administered + status (common filter/sort combination)
            try {
                $table->index(['tenant_id', 'date_administered', 'status'], 'idx_sacraments_tenant_date_status');
            } catch (\Exception $e) {
                // Index may already exist, continue
            }

            // Note: family_id and bcc_id indexes already exist from previous migration
            // No need to add them again

            // Index for deleted_at (for soft deletes filtering)
            try {
                $table->index('deleted_at', 'sacraments_deleted_at_index');
            } catch (\Exception $e) {
                // Index may already exist, continue
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            // Drop indexes if they exist
            try {
                $table->dropIndex('idx_sacraments_tenant_date');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('idx_sacraments_tenant_status');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('idx_sacraments_tenant_type');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('idx_sacraments_tenant_date_status');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('sacraments_family_id_index');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('sacraments_bcc_id_index');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
            try {
                $table->dropIndex('sacraments_deleted_at_index');
            } catch (\Exception $e) {
                // Index may not exist, continue
            }
        });
    }
};

