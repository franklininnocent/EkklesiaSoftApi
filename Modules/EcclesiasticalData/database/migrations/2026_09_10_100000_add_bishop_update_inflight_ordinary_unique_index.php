<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('bishop_update_requests')) {
            return;
        }

        $types = implode("','", [
            BishopUpdateRequestType::ChangeCurrentBishop->value,
            BishopUpdateRequestType::CreateBishop->value,
        ]);
        $statuses = implode("','", BishopUpdateRequestStatus::inFlightStatuses());
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS bishop_update_one_inflight_ordinary_per_diocese
            ON bishop_update_requests (diocese_id)
            WHERE request_type IN ('{$types}')
              AND status IN ('{$statuses}')
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS bishop_update_one_inflight_ordinary_per_diocese');
        }
    }
};
