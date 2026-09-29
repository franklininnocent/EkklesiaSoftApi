<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: correlate domain audits with active support sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('donation_audit_logs') && ! Schema::hasColumn('donation_audit_logs', 'support_session_id')) {
            Schema::table('donation_audit_logs', function (Blueprint $table) {
                $table->uuid('support_session_id')->nullable()->after('actor_user_id');
                $table->index(['tenant_id', 'support_session_id']);
            });
        }

        if (Schema::hasTable('ma_audit_logs') && ! Schema::hasColumn('ma_audit_logs', 'support_session_id')) {
            Schema::table('ma_audit_logs', function (Blueprint $table) {
                $table->uuid('support_session_id')->nullable()->after('actor_user_id');
                $table->index(['tenant_id', 'support_session_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('donation_audit_logs') && Schema::hasColumn('donation_audit_logs', 'support_session_id')) {
            Schema::table('donation_audit_logs', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'support_session_id']);
                $table->dropColumn('support_session_id');
            });
        }

        if (Schema::hasTable('ma_audit_logs') && Schema::hasColumn('ma_audit_logs', 'support_session_id')) {
            Schema::table('ma_audit_logs', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'support_session_id']);
                $table->dropColumn('support_session_id');
            });
        }
    }
};
