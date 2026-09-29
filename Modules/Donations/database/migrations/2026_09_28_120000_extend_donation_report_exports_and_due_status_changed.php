<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_report_exports', function (Blueprint $table): void {
            $table->string('filter_hash', 64)->nullable()->after('filters');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
            $table->unsignedInteger('row_count')->nullable()->after('file_size');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->timestamp('expires_at')->nullable()->after('completed_at');

            $table->index(['tenant_id', 'filter_hash', 'status']);
        });

        if (Schema::hasTable('contribution_dues') && ! Schema::hasColumn('contribution_dues', 'status_changed_at')) {
            Schema::table('contribution_dues', function (Blueprint $table): void {
                $table->timestamp('status_changed_at')->nullable()->after('status');
            });
        }

        if (Schema::hasTable('project_installment_dues') && ! Schema::hasColumn('project_installment_dues', 'status_changed_at')) {
            Schema::table('project_installment_dues', function (Blueprint $table): void {
                $table->timestamp('status_changed_at')->nullable()->after('status');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE donation_report_exports DROP CONSTRAINT IF EXISTS donation_report_exports_status_check');
            DB::statement("ALTER TABLE donation_report_exports ADD CONSTRAINT donation_report_exports_status_check CHECK (status::text = ANY (ARRAY['queued'::text, 'processing'::text, 'completed'::text, 'failed'::text, 'expired'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE donation_report_exports MODIFY COLUMN status ENUM('queued', 'processing', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'queued'");
        }
    }

    public function down(): void
    {
        Schema::table('donation_report_exports', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'filter_hash', 'status']);
            $table->dropColumn([
                'filter_hash',
                'file_size',
                'row_count',
                'started_at',
                'completed_at',
                'expires_at',
            ]);
        });

        if (Schema::hasColumn('contribution_dues', 'status_changed_at')) {
            Schema::table('contribution_dues', function (Blueprint $table): void {
                $table->dropColumn('status_changed_at');
            });
        }

        if (Schema::hasColumn('project_installment_dues', 'status_changed_at')) {
            Schema::table('project_installment_dues', function (Blueprint $table): void {
                $table->dropColumn('status_changed_at');
            });
        }
    }
};
