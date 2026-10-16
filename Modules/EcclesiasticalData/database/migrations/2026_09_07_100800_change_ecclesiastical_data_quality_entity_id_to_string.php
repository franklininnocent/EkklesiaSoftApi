<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ecclesiastical_data_quality')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE ecclesiastical_data_quality ALTER COLUMN entity_id TYPE VARCHAR(64) USING entity_id::text'
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite stores flexible types; no-op for tests using in-memory sqlite.
            return;
        }

        Schema::table('ecclesiastical_data_quality', function (Blueprint $table) {
            $table->string('entity_id', 64)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecclesiastical_data_quality') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE ecclesiastical_data_quality ALTER COLUMN entity_id TYPE UUID USING entity_id::uuid'
        );
    }
};
