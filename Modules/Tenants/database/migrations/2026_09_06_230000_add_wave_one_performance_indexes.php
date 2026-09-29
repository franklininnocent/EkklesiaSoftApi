<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status'], 'families_tenant_status_idx');
            $table->index(['tenant_id', 'bcc_id'], 'families_tenant_bcc_idx');
        });

        Schema::table('family_members', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status'], 'family_members_tenant_status_idx');
        });

        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'payment_date'], 'donation_payments_tenant_status_date_idx');
        });

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'due_date'], 'contribution_dues_tenant_status_due_idx');
        });

        Schema::table('sacraments', function (Blueprint $table): void {
            $table->index(['tenant_id', 'bcc_id'], 'sacraments_tenant_bcc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table): void {
            $table->dropIndex('sacraments_tenant_bcc_idx');
        });

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropIndex('contribution_dues_tenant_status_due_idx');
        });

        Schema::table('donation_payments', function (Blueprint $table): void {
            $table->dropIndex('donation_payments_tenant_status_date_idx');
        });

        Schema::table('family_members', function (Blueprint $table): void {
            $table->dropIndex('family_members_tenant_status_idx');
        });

        Schema::table('families', function (Blueprint $table): void {
            $table->dropIndex('families_tenant_status_idx');
            $table->dropIndex('families_tenant_bcc_idx');
        });
    }
};
