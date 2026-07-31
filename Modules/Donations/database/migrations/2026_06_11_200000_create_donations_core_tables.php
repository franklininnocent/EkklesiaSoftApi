<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_funds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 150);
            $table->string('code', 60);
            $table->text('description')->nullable();
            $table->boolean('is_tax_deductible')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('contribution_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('fund_id');
            $table->string('name', 150);
            $table->string('code', 60);
            $table->enum('frequency', ['one_time', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly', 'custom'])->default('monthly');
            $table->decimal('default_amount', 14, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index('fund_id');
        });

        Schema::create('donation_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('fund_id')->nullable();
            $table->string('name', 180);
            $table->string('code', 60);
            $table->text('description')->nullable();
            $table->decimal('target_amount', 14, 2)->default(0);
            $table->decimal('raised_amount', 14, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->boolean('is_tax_deductible')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index('fund_id');
        });

        Schema::create('contribution_dues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('family_id');
            $table->uuid('plan_id');
            $table->string('period_label', 50);
            $table->date('due_date');
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->enum('status', ['pending', 'partially_paid', 'paid', 'waived', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'family_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
        });

        Schema::create('donation_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('family_id')->nullable();
            $table->string('payment_number', 80);
            $table->string('payer_name', 180);
            $table->string('payer_email', 180)->nullable();
            $table->string('payer_phone', 60)->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('INR');
            $table->enum('method', ['cash', 'bank_transfer', 'cheque', 'online_placeholder', 'adjustment']);
            $table->string('gateway_reference', 200)->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'reversed', 'refunded'])->default('succeeded');
            $table->string('source_type', 40)->default('general');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'payment_number']);
            $table->index(['tenant_id', 'payment_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'family_id']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('payment_id');
            $table->string('allocatable_type', 40);
            $table->uuid('allocatable_id');
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'allocatable_type', 'allocatable_id']);
        });

        Schema::create('donation_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('payment_id');
            $table->string('receipt_number', 100);
            $table->date('issued_on');
            $table->boolean('is_void')->default(false);
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'issued_on']);
            $table->index(['tenant_id', 'payment_id']);
        });

        Schema::create('donation_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('target_type', 40);
            $table->uuid('target_id');
            $table->enum('action', ['refund', 'reversal', 'writeoff']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('donation_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('payment_id');
            $table->uuid('approval_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('refund_date');
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('donation_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 120);
            $table->string('target_type', 60);
            $table->string('target_id', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_audit_logs');
        Schema::dropIfExists('donation_refunds');
        Schema::dropIfExists('donation_approvals');
        Schema::dropIfExists('donation_receipts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('donation_payments');
        Schema::dropIfExists('contribution_dues');
        Schema::dropIfExists('donation_projects');
        Schema::dropIfExists('contribution_plans');
        Schema::dropIfExists('donation_funds');
    }
};
