<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 16); // test|live
            $table->string('public_key_id', 64)->nullable();
            $table->text('api_secret_encrypted')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->text('webhook_secret_previous_encrypted')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('live_checkout_enabled')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_status', 32)->nullable();
            $table->timestamps();
            $table->unique('environment');
        });

        Schema::create('billing_offline_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label', 120);
            $table->boolean('enabled')->default(false);
            $table->json('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_billing_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('online_checkout', 16)->default('inherit'); // inherit|allow|deny
            $table->string('offline_claims', 16)->default('inherit');
            $table->boolean('purchases_restricted')->default(false);
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_billing_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_interval', 16)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('entitlement_on_partial', 32)->default('when_fully_paid');
            $table->json('installment_schedule')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('billing_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_interval', 16);
            $table->string('idempotency_key', 64);
            $table->string('internal_reference', 40)->unique();
            $table->string('receipt', 40)->unique();
            $table->string('environment', 16)->default('test');
            $table->string('provider', 32)->default('razorpay');
            $table->string('provider_order_id', 64)->nullable()->unique();
            $table->string('status', 32);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default('0.00');
            $table->decimal('gross_amount', 14, 2);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency_code', 3);
            $table->json('tax_snapshot')->nullable();
            $table->json('commercial_snapshot')->nullable();
            $table->string('fulfillment_key', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('billing_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_order_id')->constrained('billing_orders')->cascadeOnDelete();
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->string('status', 32);
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->string('environment', 16);
            $table->timestamp('verified_at')->nullable();
            $table->json('provider_payload_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('billing_order_id')->nullable()->constrained('billing_orders')->nullOnDelete();
            $table->string('invoice_number', 64)->unique();
            $table->string('status', 32);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default('0.00');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default('0.00');
            $table->string('currency_code', 3);
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->json('tax_snapshot')->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('billing_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default('1.00');
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default('0.00');
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('billing_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 16); // online|offline|admin
            $table->foreignId('billing_payment_attempt_id')->nullable()->constrained('billing_payment_attempts')->nullOnDelete();
            $table->foreignId('billing_offline_claim_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->string('status', 32);
            $table->string('external_reference', 120)->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'external_reference']);
        });

        Schema::create('billing_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_receipt_id')->constrained('billing_receipts')->cascadeOnDelete();
            $table->foreignId('billing_invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['billing_receipt_id', 'billing_invoice_id']);
        });

        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('billing_receipt_id')->nullable()->constrained('billing_receipts')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_remaining', 14, 2);
            $table->string('currency_code', 3);
            $table->timestamps();
            $table->index(['tenant_id', 'currency_code']);
        });

        Schema::create('billing_offline_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->foreignId('billing_invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->foreignId('billing_order_id')->nullable()->constrained('billing_orders')->nullOnDelete();
            $table->string('method_code', 32);
            $table->decimal('claimed_amount', 14, 2);
            $table->string('currency_code', 3);
            $table->date('paid_on')->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->string('remitter_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32);
            $table->string('evidence_storage_path', 512)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('second_approved_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index('reference_number');
        });

        Schema::table('billing_receipts', function (Blueprint $table) {
            $table->foreign('billing_offline_claim_id')->references('id')->on('billing_offline_claims')->nullOnDelete();
        });

        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('billing_payment_attempt_id')->nullable()->constrained('billing_payment_attempts')->nullOnDelete();
            $table->foreignId('billing_receipt_id')->nullable()->constrained('billing_receipts')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->string('status', 32);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('provider_refund_id', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 64)->unique();
            $table->string('environment', 16);
            $table->string('event_type', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamps();
            $table->index(['processing_status', 'available_at']);
        });

        Schema::create('billing_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 64);
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });

        Schema::create('billing_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('category', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 64);
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['category', 'created_at']);
            $table->index('tenant_id');
        });

        Schema::create('billing_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32);
            $table->unsignedBigInteger('started_by')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_investigation_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('status', 32);
            $table->json('details')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $table->foreignId('billing_refund_id')->nullable()->constrained('billing_refunds')->nullOnDelete();
            $table->string('credit_note_number', 64)->unique();
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credit_notes');
        Schema::dropIfExists('billing_investigation_items');
        Schema::dropIfExists('billing_reconciliation_runs');
        Schema::dropIfExists('billing_audit_events');
        Schema::dropIfExists('billing_outbox');
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('billing_refunds');
        Schema::table('billing_receipts', function (Blueprint $table) {
            $table->dropForeign(['billing_offline_claim_id']);
        });
        Schema::dropIfExists('billing_offline_claims');
        Schema::dropIfExists('billing_credits');
        Schema::dropIfExists('billing_allocations');
        Schema::dropIfExists('billing_receipts');
        Schema::dropIfExists('billing_invoice_lines');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_payment_attempts');
        Schema::dropIfExists('billing_orders');
        Schema::dropIfExists('tenant_billing_agreements');
        Schema::dropIfExists('tenant_billing_policies');
        Schema::dropIfExists('billing_offline_methods');
        Schema::dropIfExists('billing_gateway_configs');
    }
};
