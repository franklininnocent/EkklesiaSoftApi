<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_schedule_slots', function (Blueprint $table): void {
            $table->json('weeks_of_month')->nullable()->after('weekday');
        });
    }

    public function down(): void
    {
        Schema::table('mass_schedule_slots', function (Blueprint $table): void {
            $table->dropColumn('weeks_of_month');
        });
    }
};
