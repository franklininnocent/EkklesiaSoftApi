<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->index(['tenant_id', 'payment_date', 'status'], 'donation_payments_tenant_date_status_idx');
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->index(['tenant_id', 'allocatable_type', 'allocatable_id'], 'payment_alloc_tenant_target_idx');
        });

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->index(['tenant_id', 'due_date', 'status'], 'contribution_dues_tenant_due_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->dropIndex('donation_payments_tenant_date_status_idx');
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropIndex('payment_alloc_tenant_target_idx');
        });

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropIndex('contribution_dues_tenant_due_status_idx');
        });
    }
};
