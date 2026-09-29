<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('title', 150);
            $table->string('category', 40);
            $table->unsignedSmallInteger('hierarchical_level')->default(4);
            $table->boolean('allows_concurrent')->default(false);
            $table->boolean('is_canonical_mandate')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['category', 'is_active']);
        });

        Schema::create('leadership_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('church_profile_id');
            $table->foreign('church_profile_id')->references('id')->on('church_profiles')->cascadeOnDelete();

            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();

            $table->uuid('role_id');
            $table->foreign('role_id')->references('id')->on('leadership_roles')->restrictOnDelete();

            $table->string('jurisdiction_name', 255)->nullable();
            $table->date('appointment_date')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('appointment_letter_ref', 150)->nullable();
            $table->string('exit_reason_code', 30)->nullable();
            $table->text('exit_reason_note')->nullable();
            $table->unsignedBigInteger('legacy_church_leadership_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'church_profile_id', 'status']);
            $table->index(['tenant_id', 'church_profile_id', 'role_id', 'status']);
            $table->index(['person_id', 'start_date', 'end_date']);
            $table->index(['role_id', 'start_date', 'end_date']);
            $table->index('legacy_church_leadership_id');
        });

        Schema::create('church_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->uuid('support_session_id')->nullable();

            $table->string('event', 120);
            $table->string('target_type', 60);
            $table->string('target_id', 64);
            $table->unsignedBigInteger('church_profile_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'support_session_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE INDEX leadership_assignments_active_role_idx
                ON leadership_assignments (church_profile_id, role_id)
                WHERE status = 'active'
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS leadership_assignments_active_role_idx');
        }

        Schema::dropIfExists('church_audit_logs');
        Schema::dropIfExists('leadership_assignments');
        Schema::dropIfExists('leadership_roles');
    }
};
