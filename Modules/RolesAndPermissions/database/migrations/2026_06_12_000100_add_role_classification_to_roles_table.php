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
            if (!Schema::hasColumn('roles', 'role_classification')) {
                $table->string('role_classification', 40)->nullable()->after('role_type');
            }
        });

        // 1) Platform roles remain protected system roles.
        DB::table('roles')
            ->where('role_type', 'platform')
            ->update(['role_classification' => 'protected_system']);

        // 2) Tenant Administrator is always protected.
        DB::table('roles')
            ->where('role_type', 'tenant')
            ->where('name', 'Administrator')
            ->update(['role_classification' => 'protected_system']);

        // 3) Existing custom tenant roles remain custom.
        DB::table('roles')
            ->where('role_type', 'tenant')
            ->where('is_custom', true)
            ->whereNull('role_classification')
            ->update(['role_classification' => 'custom']);

        // 4) Existing seeded tenant roles become editable defaults.
        DB::table('roles')
            ->where('role_type', 'tenant')
            ->where('is_custom', false)
            ->whereNull('role_classification')
            ->update(['role_classification' => 'default_template']);

        // 5) Safety fallback for any role without classification.
        DB::table('roles')
            ->whereNull('role_classification')
            ->update(['role_classification' => 'custom']);

        Schema::table('roles', function (Blueprint $table) {
            $table->index(['role_classification'], 'roles_role_classification_index');
            $table->index(['tenant_id', 'role_classification'], 'roles_tenant_role_classification_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_role_classification_index');
            $table->dropIndex('roles_tenant_role_classification_index');
            $table->dropColumn('role_classification');
        });
    }
};

