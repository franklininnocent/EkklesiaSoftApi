<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->date('period_start')->nullable()->after('period_label');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
