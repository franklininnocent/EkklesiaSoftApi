<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_tenant_id')->nullable()->after('id');
            $table->string('tenant_tier', 32)->default('parish')->after('parent_tenant_id');
            $table->string('hierarchy_path', 500)->nullable()->after('tenant_tier');
            $table->string('currency_code', 3)->default('INR')->after('hierarchy_path');

            $table->foreign('parent_tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('tenant_tier');
            $table->index('hierarchy_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign(['parent_tenant_id']);
            $table->dropIndex(['tenant_tier']);
            $table->dropIndex(['hierarchy_path']);
            $table->dropColumn(['parent_tenant_id', 'tenant_tier', 'hierarchy_path', 'currency_code']);
        });
    }
};
