<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_intention_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->string('suggested_offering_amount', 32)->nullable();
            $table->unsignedSmallInteger('review_days')->default(3);
            $table->json('categories')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('mass_intention_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('status', 32)->default('draft');
            $table->uuid('beneficiary_person_id')->nullable();
            $table->string('beneficiary_name', 255);
            $table->string('intention_text', 500);
            $table->string('priest_text', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('announce_name')->default(true);
            $table->string('requester_name', 255)->nullable();
            $table->string('requester_phone', 64)->nullable();
            $table->date('requested_date')->nullable();
            $table->boolean('date_must_be_kept')->default(false);
            $table->unsignedSmallInteger('mass_count_requested')->nullable();
            $table->unsignedSmallInteger('mass_count_accepted')->nullable();
            $table->json('offering_policy_snapshot')->nullable();
            $table->timestamp('duplicate_warning_acknowledged_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('beneficiary_person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('accepted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'requested_date']);
            $table->index(['tenant_id', 'beneficiary_name']);
        });

        Schema::create('mass_intention_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('request_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('mass_intention_requests')->cascadeOnDelete();

            $table->unique(['request_id', 'sequence']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('mass_intention_offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('request_id');
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('mass_intention_requests')->cascadeOnDelete();

            $table->unique(['request_id']);
        });

        Schema::create('mass_intention_offering_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('offering_id');
            $table->string('receipt_number', 64);
            $table->string('amount', 32);
            $table->string('payment_method', 32);
            $table->date('received_on');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('offering_id')->references('id')->on('mass_intention_offerings')->cascadeOnDelete();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'offering_id']);
        });

        Schema::create('mass_celebrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->date('celebrated_on');
            $table->time('celebrated_at')->nullable();
            $table->string('place', 255)->nullable();
            $table->string('celebrant_name', 255)->nullable();
            $table->unsignedBigInteger('celebrant_leadership_assignment_id')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['tenant_id', 'celebrated_on', 'status']);
        });

        Schema::create('mass_intention_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('obligation_id');
            $table->uuid('celebration_id')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('obligation_id')->references('id')->on('mass_intention_obligations')->cascadeOnDelete();
            $table->foreign('celebration_id')->references('id')->on('mass_celebrations')->nullOnDelete();
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['tenant_id', 'celebration_id']);
            $table->index(['tenant_id', 'obligation_id']);
        });

        Schema::create('mass_intention_fulfilments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('obligation_id');
            $table->uuid('celebration_id')->nullable();
            $table->timestamp('fulfilled_at');
            $table->unsignedBigInteger('fulfilled_by_user_id');
            $table->string('celebrant_override', 255)->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->text('undo_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('obligation_id')->references('id')->on('mass_intention_obligations')->cascadeOnDelete();
            $table->foreign('celebration_id')->references('id')->on('mass_celebrations')->nullOnDelete();
            $table->foreign('fulfilled_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['tenant_id', 'obligation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_intention_fulfilments');
        Schema::dropIfExists('mass_intention_assignments');
        Schema::dropIfExists('mass_celebrations');
        Schema::dropIfExists('mass_intention_offering_receipts');
        Schema::dropIfExists('mass_intention_offerings');
        Schema::dropIfExists('mass_intention_obligations');
        Schema::dropIfExists('mass_intention_requests');
        Schema::dropIfExists('mass_intention_settings');
    }
};
