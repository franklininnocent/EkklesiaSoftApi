<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bishop_appointments', function (Blueprint $table) {
            $table->string('canonical_role', 50)->default('diocesan_bishop')->after('ecclesiastical_title_id');
            $table->date('announced_date')->nullable()->after('appointed_date');
            $table->date('effective_date')->nullable()->after('announced_date');
            $table->string('appointment_status', 30)->default('current')->after('is_current');
            $table->unsignedInteger('version')->default(1)->after('metadata');
            $table->string('source_type', 50)->nullable()->after('version');
            $table->string('source_reference', 500)->nullable()->after('source_type');
            $table->foreignId('verified_by')->nullable()->after('source_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->foreignId('created_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->index('canonical_role');
            $table->index('appointment_status');
            $table->index(['diocese_id', 'canonical_role', 'is_current'], 'bishop_appt_diocese_role_current_idx');
            $table->index(['effective_date', 'ended_date'], 'bishop_appt_effective_ended_idx');
        });

        Schema::table('bishop_appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('ecclesiastical_title_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bishop_appointments', function (Blueprint $table) {
            $table->dropIndex('bishop_appt_diocese_role_current_idx');
            $table->dropIndex('bishop_appt_effective_ended_idx');
            $table->dropIndex(['canonical_role']);
            $table->dropIndex(['appointment_status']);

            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('verified_by');

            $table->dropColumn([
                'canonical_role',
                'announced_date',
                'effective_date',
                'appointment_status',
                'version',
                'source_type',
                'source_reference',
                'verified_at',
            ]);
        });
    }
};
