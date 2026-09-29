<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcc_family_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('bcc_id');
            $table->foreign('bcc_id')->references('id')->on('bccs')->restrictOnDelete();

            $table->uuid('family_id');
            $table->foreign('family_id')->references('id')->on('families')->restrictOnDelete();

            $table->string('status', 20)->default('active');
            $table->date('joined_date');
            $table->date('exit_date')->nullable();
            $table->string('exit_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_current')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'bcc_id', 'is_current']);
            $table->index(['tenant_id', 'family_id', 'is_current']);
            $table->index(['bcc_id', 'status']);
            $table->index(['family_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX bcc_family_memberships_uq_current_family
                ON bcc_family_memberships (tenant_id, family_id)
                WHERE is_current = true
                  AND status = 'active'
                  AND deleted_at IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS bcc_family_memberships_uq_current_family');
        }

        Schema::dropIfExists('bcc_family_memberships');
    }
};
