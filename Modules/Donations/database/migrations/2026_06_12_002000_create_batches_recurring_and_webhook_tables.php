<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('batch_number', 80);
            $table->date('batch_date');
            $table->string('source', 40)->default('manual');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'batch_number']);
            $table->index(['tenant_id', 'batch_date']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('donation_payments', function (Blueprint $table): void {
            if (!Schema::hasColumn('donation_payments', 'payment_batch_id')) {
                $table->uuid('payment_batch_id')->nullable()->after('family_id');
                $table->index(['tenant_id', 'payment_batch_id']);
            }
        });

        Schema::create('recurring_donation_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('donor_id')->nullable();
            $table->uuid('family_id')->nullable();
            $table->uuid('donation_category_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('INR');
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->date('next_run_on');
            $table->date('end_on')->nullable();
            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'next_run_on']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payment_gateway_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('provider', 40);
            $table->string('event_type', 120)->nullable();
            $table->string('event_id', 160)->nullable();
            $table->string('signature', 255)->nullable();
            $table->enum('status', ['received', 'processed', 'failed', 'ignored'])->default('received');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_webhook_events');
        Schema::dropIfExists('recurring_donation_schedules');

        Schema::table('donation_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('donation_payments', 'payment_batch_id')) {
                $table->dropIndex(['tenant_id', 'payment_batch_id']);
                $table->dropColumn('payment_batch_id');
            }
        });

        Schema::dropIfExists('payment_batches');
    }
};
