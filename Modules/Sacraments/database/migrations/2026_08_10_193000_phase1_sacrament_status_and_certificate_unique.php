<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 safety: remap business status, fix certificate uniqueness per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Remap legacy status values to registered/voided (ADR-07).
        DB::table('sacraments')->where('status', 'active')->update(['status' => 'registered']);
        DB::table('sacraments')->where('status', 'cancelled')->update(['status' => 'voided']);

        // Drop global unique on certificate_number (name may vary by DB driver).
        try {
            Schema::table('sacraments', function (Blueprint $table) {
                $table->dropUnique(['certificate_number']);
            });
        } catch (Throwable $e) {
            // Index name may differ.
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sacraments_certificate_number_unique');
            DB::statement('DROP INDEX IF EXISTS sacraments_certificate_number_index');

            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS sacraments_tenant_certificate_number_unique
                ON sacraments (tenant_id, certificate_number)
                WHERE certificate_number IS NOT NULL AND deleted_at IS NULL
            ');

            DB::statement('
                ALTER TABLE sacraments
                DROP CONSTRAINT IF EXISTS sacraments_status_check
            ');
            DB::statement("
                ALTER TABLE sacraments
                ADD CONSTRAINT sacraments_status_check
                CHECK (status IN ('registered', 'conditional', 'voided'))
            ");

            DB::statement("ALTER TABLE sacraments ALTER COLUMN status SET DEFAULT 'registered'");

            return;
        }

        // SQLite / other drivers used in tests: composite unique; app validates statuses.
        try {
            Schema::table('sacraments', function (Blueprint $table) {
                $table->unique(['tenant_id', 'certificate_number'], 'sacraments_tenant_certificate_number_unique');
            });
        } catch (Throwable $e) {
            // already exists
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        DB::table('sacraments')->where('status', 'registered')->update(['status' => 'active']);
        DB::table('sacraments')->where('status', 'voided')->update(['status' => 'cancelled']);

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sacraments DROP CONSTRAINT IF EXISTS sacraments_status_check');
            DB::statement('DROP INDEX IF EXISTS sacraments_tenant_certificate_number_unique');
            Schema::table('sacraments', function (Blueprint $table) {
                $table->unique('certificate_number');
            });
            DB::statement("ALTER TABLE sacraments ALTER COLUMN status SET DEFAULT 'active'");

            return;
        }

        try {
            Schema::table('sacraments', function (Blueprint $table) {
                $table->dropUnique('sacraments_tenant_certificate_number_unique');
            });
        } catch (Throwable $e) {
            // ignore
        }

        try {
            Schema::table('sacraments', function (Blueprint $table) {
                $table->unique('certificate_number');
            });
        } catch (Throwable $e) {
            // ignore
        }
    }
};
