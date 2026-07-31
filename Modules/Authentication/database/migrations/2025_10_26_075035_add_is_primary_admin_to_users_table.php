<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add is_primary_admin flag to users table to mark the primary admin user
     * created during tenant onboarding. This user cannot be deleted or deactivated
     * by tenant users, maintaining administrative continuity and system integrity.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_primary_admin')
                ->default(false)
                ->after('user_type')
                ->comment('Primary admin created during tenant onboarding - cannot be deleted/deactivated by tenant users');
            
            // Add index for performance when checking primary admin status
            $table->index(['tenant_id', 'is_primary_admin'], 'users_tenant_primary_admin_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_primary_admin_index');
            $table->dropColumn('is_primary_admin');
        });
    }
};
