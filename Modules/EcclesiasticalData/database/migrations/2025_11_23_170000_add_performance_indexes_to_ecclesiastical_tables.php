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
        // Indexes for diocese_management table
        if (Schema::hasTable('diocese_management')) {
            Schema::table('diocese_management', function (Blueprint $table) {
                try {
                    if (!Schema::hasColumn('diocese_management', 'active')) {
                        return;
                    }
                    
                    // Index for active status filtering
                    if (!$this->hasIndex('diocese_management', 'diocese_management_active_index')) {
                        $table->index('active', 'diocese_management_active_index');
                    }
                    
                    // Composite index for country and active status
                    if (!$this->hasIndex('diocese_management', 'diocese_management_country_active_index')) {
                        $table->index(['country_id', 'active'], 'diocese_management_country_active_index');
                    }
                    
                    // Index for parent archdiocese (for hierarchy queries)
                    if (!$this->hasIndex('diocese_management', 'diocese_management_parent_index')) {
                        $table->index('parent_archdiocese_id', 'diocese_management_parent_index');
                    }
                    
                    // Index for denomination filtering
                    if (!$this->hasIndex('diocese_management', 'diocese_management_denomination_index')) {
                        $table->index('denomination_id', 'diocese_management_denomination_index');
                    }
                    
                    // Index for created_at (for recent additions in statistics)
                    if (!$this->hasIndex('diocese_management', 'diocese_management_created_at_index')) {
                        $table->index('created_at', 'diocese_management_created_at_index');
                    }
                } catch (\Exception $e) {
                    // Index might already exist, ignore
                }
            });
        }

        // Indexes for bishop_management table
        if (Schema::hasTable('bishop_management')) {
            Schema::table('bishop_management', function (Blueprint $table) {
                try {
                    // Index for status filtering
                    if (!$this->hasIndex('bishop_management', 'bishop_management_status_index')) {
                        $table->index('status', 'bishop_management_status_index');
                    }
                    
                    // Composite index for archdiocese and status
                    if (!$this->hasIndex('bishop_management', 'bishop_management_archdiocese_status_index')) {
                        $table->index(['archdiocese_id', 'status'], 'bishop_management_archdiocese_status_index');
                    }
                    
                    // Index for ecclesiastical title
                    if (!$this->hasIndex('bishop_management', 'bishop_management_title_index')) {
                        $table->index('ecclesiastical_title_id', 'bishop_management_title_index');
                    }
                    
                    // Index for created_at (for recent additions in statistics)
                    if (!$this->hasIndex('bishop_management', 'bishop_management_created_at_index')) {
                        $table->index('created_at', 'bishop_management_created_at_index');
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
        if (Schema::hasTable('diocese_management')) {
            Schema::table('diocese_management', function (Blueprint $table) {
                try {
                    $table->dropIndex('diocese_management_active_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('diocese_management_country_active_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('diocese_management_parent_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('diocese_management_denomination_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('diocese_management_created_at_index');
                } catch (\Exception $e) {}
            });
        }

        if (Schema::hasTable('bishop_management')) {
            Schema::table('bishop_management', function (Blueprint $table) {
                try {
                    $table->dropIndex('bishop_management_status_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('bishop_management_archdiocese_status_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('bishop_management_title_index');
                } catch (\Exception $e) {}
                try {
                    $table->dropIndex('bishop_management_created_at_index');
                } catch (\Exception $e) {}
            });
        }
    }

    /**
     * Check if an index exists (simplified approach)
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
            
            // For other drivers, assume index doesn't exist and let migration handle it
            return false;
        } catch (\Exception $e) {
            // If check fails, assume index doesn't exist
            return false;
        }
    }
};

