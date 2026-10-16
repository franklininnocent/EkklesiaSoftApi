<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('archdioceses')) {
            Schema::table('archdioceses', function (Blueprint $table) {
                try {
                    // Composite index for cursor pagination with name sorting
                    // This index supports: ORDER BY name, id (for cursor pagination)
                    if (!$this->hasIndex('archdioceses', 'idx_archdioceses_name_id_cursor')) {
                        $table->index(['name', 'id'], 'idx_archdioceses_name_id_cursor');
                    }
                    
                    // Composite index for cursor pagination with created_at sorting
                    // This index supports: ORDER BY created_at, id (for cursor pagination)
                    if (!$this->hasIndex('archdioceses', 'idx_archdioceses_created_id_cursor')) {
                        $table->index(['created_at', 'id'], 'idx_archdioceses_created_id_cursor');
                    }
                    
                    // Composite index for filtering + sorting (most common query pattern)
                    // Supports: WHERE active = ? AND country_id = ? ORDER BY name, id
                    if (!$this->hasIndex('archdioceses', 'idx_archdioceses_active_country_name')) {
                        $table->index(['active', 'country_id', 'name', 'id'], 'idx_archdioceses_active_country_name');
                    }
                    
                    // Composite index for search + filtering
                    // Supports: WHERE (name ILIKE ? OR code ILIKE ?) AND country_id = ? AND active = ?
                    if (!$this->hasIndex('archdioceses', 'idx_archdioceses_search_filter')) {
                        $table->index(['country_id', 'active', 'name'], 'idx_archdioceses_search_filter');
                    }
                    
                    // Index for denomination filtering + sorting
                    if (!$this->hasIndex('archdioceses', 'idx_archdioceses_denomination_name')) {
                        $table->index(['denomination_id', 'name', 'id'], 'idx_archdioceses_denomination_name');
                    }
                } catch (\Exception $e) {
                    // Index might already exist, ignore
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('archdioceses')) {
            Schema::table('archdioceses', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_archdioceses_name_id_cursor');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_archdioceses_created_id_cursor');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_archdioceses_active_country_name');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_archdioceses_search_filter');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('idx_archdioceses_denomination_name');
                } catch (\Exception $e) {}
            });
        }
    }

    /**
     * Check if an index exists
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $driverName = $connection->getDriverName();
            
            if ($driverName === 'pgsql') {
                $result = $connection->selectOne(
                    "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                    [$table, $indexName]
                );
                return $result !== null;
            } elseif ($driverName === 'mysql') {
                $result = $connection->selectOne(
                    "SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                    [$connection->getDatabaseName(), $table, $indexName]
                );
                return $result !== null;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
};





