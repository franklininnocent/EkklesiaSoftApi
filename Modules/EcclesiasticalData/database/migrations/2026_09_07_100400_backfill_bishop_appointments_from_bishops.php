<?php

use Illuminate\Database\Migrations\Migration;
use Modules\EcclesiasticalData\Services\BishopAppointmentBackfillService;

return new class extends Migration
{
    public function up(): void
    {
        app(BishopAppointmentBackfillService::class)->backfillMissingAppointments();
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('bishop_appointments')) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('bishop_appointments')
            ->where('source_type', 'migration_backfill')
            ->delete();
    }
};
