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
     * Update the unique constraint on roles.name to allow the same role name
     * across different tenants. This makes role names unique per tenant.
     */
    public function up(): void
    {
        // Drop the existing unique constraint on name only
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_unique');
        });

        $driver = Schema::getConnection()->getDriverName();

        // Add a composite unique constraint on (name, tenant_id)
        // PostgreSQL uses COALESCE to enforce uniqueness for NULL tenant_id.
        if ($driver === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX roles_name_tenant_id_unique 
                ON roles (name, COALESCE(tenant_id, 0))
            ');
            return;
        }

        // sqlite/mysql fallback.
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['name', 'tenant_id'], 'roles_name_tenant_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the composite unique constraint
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_name_tenant_id_unique');
        });

        // Restore the original unique constraint on name only
        Schema::table('roles', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};

