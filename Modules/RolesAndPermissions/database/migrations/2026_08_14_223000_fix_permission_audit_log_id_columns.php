<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * permission_audit_logs originally used UUID columns for entity FKs, but roles,
 * permissions, and users use bigint IDs. Relational audit columns were therefore
 * always null (IDs only survived in metadata JSON). Align column types to bigint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permission_audit_logs')) {
            return;
        }

        // Clear invalid UUID-typed values so the cast to bigint succeeds.
        DB::table('permission_audit_logs')->update([
            'permission_id' => null,
            'role_id' => null,
            'user_id' => null,
            'assigned_by' => null,
        ]);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN permission_id TYPE bigint USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN role_id TYPE bigint USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN user_id TYPE bigint USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN assigned_by TYPE bigint USING NULL');

            return;
        }

        // SQLite / MySQL test and local drivers: recreate typed columns via Laravel schema.
        Schema::table('permission_audit_logs', function ($table) {
            $table->unsignedBigInteger('permission_id')->nullable()->change();
            $table->unsignedBigInteger('role_id')->nullable()->change();
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('assigned_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('permission_audit_logs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN permission_id TYPE uuid USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN role_id TYPE uuid USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN user_id TYPE uuid USING NULL');
            DB::statement('ALTER TABLE permission_audit_logs ALTER COLUMN assigned_by TYPE uuid USING NULL');

            return;
        }

        Schema::table('permission_audit_logs', function ($table) {
            $table->uuid('permission_id')->nullable()->change();
            $table->uuid('role_id')->nullable()->change();
            $table->uuid('user_id')->nullable()->change();
            $table->uuid('assigned_by')->nullable()->change();
        });
    }
};
