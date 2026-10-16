<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ma_leadership_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('ma_organizations')->restrictOnDelete();

            $table->uuid('membership_id');
            $table->foreign('membership_id')->references('id')->on('ma_memberships')->restrictOnDelete();

            $table->uuid('position_id');
            $table->foreign('position_id')->references('id')->on('ma_positions')->restrictOnDelete();

            $table->date('appointment_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('term_label', 50)->nullable();
            $table->string('appointment_reference', 100)->nullable();
            $table->boolean('is_interim')->default(false);
            $table->string('status', 20)->default('active');
            $table->string('exit_reason', 30)->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'organization_id', 'status']);
            $table->index(['tenant_id', 'organization_id', 'position_id', 'status']);
            $table->index(['membership_id', 'status']);
            $table->index(['organization_id', 'effective_from', 'effective_to']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE INDEX ma_leadership_terms_org_position_active_idx
                ON ma_leadership_terms (organization_id, position_id)
                WHERE status = 'active' AND deleted_at IS NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_idx');
        }

        Schema::dropIfExists('ma_leadership_terms');
    }
};
