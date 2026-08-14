<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcc_leaders', function (Blueprint $table) {
            $table->string('term_label', 50)->nullable()->after('term_end_date');
            $table->string('appointment_reference', 100)->nullable()->after('term_label');
            $table->boolean('is_interim')->default(false)->after('appointment_reference');
            $table->string('status', 20)->default('active')->after('is_active');
            $table->string('exit_reason', 30)->nullable()->after('status');
            $table->text('remarks')->nullable()->after('exit_reason');

            $table->index(
                ['tenant_id', 'bcc_id', 'status'],
                'bcc_leaders_tenant_bcc_status_idx'
            );
            $table->index(
                ['bcc_id', 'term_start_date', 'term_end_date'],
                'bcc_leaders_term_dates_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bcc_leaders', function (Blueprint $table) {
            $table->dropIndex('bcc_leaders_tenant_bcc_status_idx');
            $table->dropIndex('bcc_leaders_term_dates_idx');
            $table->dropColumn([
                'term_label',
                'appointment_reference',
                'is_interim',
                'status',
                'exit_reason',
                'remarks',
            ]);
        });
    }
};
