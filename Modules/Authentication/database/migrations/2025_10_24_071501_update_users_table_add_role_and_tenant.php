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
        Schema::table('users', function (Blueprint $table) {
            // Add role_id foreign key
            $table->unsignedBigInteger('role_id')->nullable()->after('email_verified_at');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('set null');
            
            // Add tenant_id for multi-tenant support
            $table->unsignedBigInteger('tenant_id')->nullable()->after('role_id');
            
            // Add active status
            $table->integer('active')->default(1)->after('tenant_id')->comment('1=Active, 0=Inactive');
            
            // Add soft deletes
            $table->softDeletes()->after('updated_at'); // deleted_at column (nullable by default)
            
            // Add indexes for performance
            $table->index('role_id');
            $table->index('tenant_id');
            $table->index(['active', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['active', 'deleted_at']);
            $table->dropColumn(['role_id', 'tenant_id', 'active', 'deleted_at']);
        });
    }
};

