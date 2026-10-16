<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ma_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('ma_organizations')->restrictOnDelete();

            $table->string('member_source', 10);
            $table->uuid('family_member_id')->nullable();
            $table->uuid('guest_member_id')->nullable();

            $table->foreign('family_member_id')->references('id')->on('family_members')->restrictOnDelete();
            $table->foreign('guest_member_id')->references('id')->on('ma_guest_members')->restrictOnDelete();

            $table->string('member_type', 20)->default('regular');
            $table->string('status', 20)->default('active');
            $table->date('joined_date');
            $table->date('exit_date')->nullable();
            $table->string('exit_reason', 500)->nullable();
            $table->text('remarks')->nullable();
            $table->string('emergency_contact', 100)->nullable();
            $table->boolean('is_current')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'organization_id', 'status']);
            $table->index(['tenant_id', 'organization_id', 'is_current']);
            $table->index(['tenant_id', 'family_member_id', 'is_current']);
            $table->index(['tenant_id', 'guest_member_id']);
            $table->index(['organization_id', 'joined_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX ma_memberships_uq_active_parish
                ON ma_memberships (tenant_id, organization_id, family_member_id)
                WHERE member_source = 'parish'
                  AND is_current = true
                  AND status = 'active'
                  AND deleted_at IS NULL
            ");

            DB::statement("
                CREATE UNIQUE INDEX ma_memberships_uq_active_guest
                ON ma_memberships (tenant_id, organization_id, guest_member_id)
                WHERE member_source = 'guest'
                  AND is_current = true
                  AND status = 'active'
                  AND deleted_at IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_active_parish');
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_active_guest');
        }

        Schema::dropIfExists('ma_memberships');
    }
};
