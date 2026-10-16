<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ma_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('event', 120);
            $table->string('target_type', 60);
            $table->string('target_id', 64);
            $table->uuid('organization_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE INDEX ma_audit_logs_tenant_org_created_idx
                ON ma_audit_logs (tenant_id, organization_id, created_at DESC)
                WHERE organization_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ma_audit_logs_tenant_org_created_idx');
        }

        Schema::dropIfExists('ma_audit_logs');
    }
};
