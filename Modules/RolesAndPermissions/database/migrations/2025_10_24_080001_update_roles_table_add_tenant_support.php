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
        Schema::table('roles', function (Blueprint $table) {
            // Add tenant_id for tenant-specific roles
            // NULL tenant_id means global role (managed by SuperAdmin only)
            $table->unsignedBigInteger('tenant_id')->nullable()->after('level');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Add is_custom flag to distinguish between system roles and custom roles
            $table->boolean('is_custom')->default(0)->after('tenant_id')
                ->comment('1=Custom role (created by tenant), 0=System role (pre-defined)');
            
            // Update index
            $table->index('tenant_id');
            $table->index(['tenant_id', 'active', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['tenant_id', 'active', 'deleted_at']);
            $table->dropColumn(['tenant_id', 'is_custom']);
        });
    }
};

