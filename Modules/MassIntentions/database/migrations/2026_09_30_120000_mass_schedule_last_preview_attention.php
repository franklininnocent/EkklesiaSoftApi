<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mass_schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('last_preview_conflict_count')->nullable()->after('status');
            $table->timestamp('last_preview_at')->nullable()->after('last_preview_conflict_count');
        });
    }

    public function down(): void
    {
        Schema::table('mass_schedules', function (Blueprint $table): void {
            $table->dropColumn(['last_preview_conflict_count', 'last_preview_at']);
        });
    }
};
