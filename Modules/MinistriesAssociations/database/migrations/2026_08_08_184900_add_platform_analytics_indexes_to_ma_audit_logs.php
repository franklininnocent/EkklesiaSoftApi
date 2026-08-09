<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive platform-analytics indexes for cross-tenant ma_audit_logs scans.
 * Safe for tenant M&A: no behavior change, indexes only on existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ma_audit_logs', function (Blueprint $table): void {
            $table->index(['created_at'], 'ma_audit_logs_created_at_idx');
            $table->index(['event', 'created_at'], 'ma_audit_logs_event_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ma_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('ma_audit_logs_created_at_idx');
            $table->dropIndex('ma_audit_logs_event_created_at_idx');
        });
    }
};
