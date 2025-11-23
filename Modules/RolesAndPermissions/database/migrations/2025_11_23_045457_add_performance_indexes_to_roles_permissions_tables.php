<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * PERFORMANCE: Add composite indexes for common query patterns in roles/permissions tables
     * These indexes optimize:
     * - Permission lookups by role with active status
     * - User role lookups with active status
     * - Permission lookups by user with active status
     * - Role queries filtered by tenant and active status
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        
        // Helper function to check if index exists
        $indexExists = function($table, $indexName) use ($connection, $driver) {
            if ($driver === 'pgsql') {
                $result = $connection->select(
                    "SELECT COUNT(*) as count 
                     FROM pg_indexes 
                     WHERE schemaname = current_schema()
                     AND tablename = ? 
                     AND indexname = ?",
                    [$table, $indexName]
                );
            } else {
                // MySQL
                $databaseName = $connection->getDatabaseName();
                $result = $connection->select(
                    "SELECT COUNT(*) as count 
                     FROM information_schema.statistics 
                     WHERE table_schema = ? 
                     AND table_name = ? 
                     AND index_name = ?",
                    [$databaseName, $table, $indexName]
                );
            }
            return $result[0]->count > 0;
        };

        // Index for permission_role pivot table
        if (!$indexExists('permission_role', 'permission_role_role_id_permission_id_index')) {
            Schema::table('permission_role', function (Blueprint $table) {
                $table->index(['role_id', 'permission_id'], 'permission_role_role_id_permission_id_index');
            });
        }

        // Index for permission_user pivot table
        if (!$indexExists('permission_user', 'permission_user_user_id_permission_id_index')) {
            Schema::table('permission_user', function (Blueprint $table) {
                $table->index(['user_id', 'permission_id'], 'permission_user_user_id_permission_id_index');
            });
        }

        // Index for role_user pivot table
        if (!$indexExists('role_user', 'role_user_user_id_index')) {
            Schema::table('role_user', function (Blueprint $table) {
                $table->index(['user_id'], 'role_user_user_id_index');
            });
        }

        // Index for roles table
        if (!$indexExists('roles', 'roles_tenant_id_active_index')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->index(['tenant_id', 'active'], 'roles_tenant_id_active_index');
            });
        }
        
        if (!$indexExists('roles', 'roles_active_index')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->index(['active'], 'roles_active_index');
            });
        }
        
        if (!$indexExists('roles', 'roles_deleted_at_index')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->index(['deleted_at'], 'roles_deleted_at_index');
            });
        }

        // Index for permissions table
        if (!$indexExists('permissions', 'permissions_tenant_id_active_index')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->index(['tenant_id', 'active'], 'permissions_tenant_id_active_index');
            });
        }
        
        if (!$indexExists('permissions', 'permissions_module_category_index')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->index(['module', 'category'], 'permissions_module_category_index');
            });
        }
        
        if (!$indexExists('permissions', 'permissions_active_index')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->index(['active'], 'permissions_active_index');
            });
        }
        
        if (!$indexExists('permissions', 'permissions_deleted_at_index')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->index(['deleted_at'], 'permissions_deleted_at_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropIndex('permission_role_role_id_permission_id_index');
        });

        Schema::table('permission_user', function (Blueprint $table) {
            $table->dropIndex('permission_user_user_id_permission_id_index');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex('role_user_user_id_index');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_tenant_id_active_index');
            $table->dropIndex('roles_active_index');
            $table->dropIndex('roles_deleted_at_index');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('permissions_tenant_id_active_index');
            $table->dropIndex('permissions_module_category_index');
            $table->dropIndex('permissions_active_index');
            $table->dropIndex('permissions_deleted_at_index');
        });
    }
};
