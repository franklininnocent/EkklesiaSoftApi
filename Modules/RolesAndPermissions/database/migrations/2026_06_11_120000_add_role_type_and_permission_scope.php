<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'role_type')) {
                $table->string('role_type', 20)->nullable()->after('is_custom');
            }
        });

        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'scope')) {
                $table->string('scope', 20)->nullable()->after('module');
            }
        });

        // Backfill role_type from current tenant design.
        DB::table('roles')
            ->whereNull('tenant_id')
            ->update(['role_type' => 'platform']);

        DB::table('roles')
            ->whereNotNull('tenant_id')
            ->update(['role_type' => 'tenant']);

        // Backfill permission scope using module and tenant ownership.
        DB::table('permissions')
            ->whereIn('module', ['Tenants', 'Pope'])
            ->update(['scope' => 'platform']);

        DB::table('permissions')
            ->whereNull('scope')
            ->whereNotNull('tenant_id')
            ->update(['scope' => 'tenant']);

        DB::table('permissions')
            ->whereNull('scope')
            ->whereNull('tenant_id')
            ->update(['scope' => 'tenant']);

        Schema::table('roles', function (Blueprint $table) {
            $table->index(['role_type'], 'roles_role_type_index');
            $table->index(['tenant_id', 'role_type'], 'roles_tenant_id_role_type_index');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->index(['scope'], 'permissions_scope_index');
            $table->index(['scope', 'module'], 'permissions_scope_module_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_role_type_index');
            $table->dropIndex('roles_tenant_id_role_type_index');
            $table->dropColumn('role_type');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('permissions_scope_index');
            $table->dropIndex('permissions_scope_module_index');
            $table->dropColumn('scope');
        });
    }
};
