<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide subscription access settings (Super Admin configurable).
 * Singleton row: grace_period_days and expiring_warning_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('grace_period_days')->default(7)
                ->comment('Days after subscription_ends_at during which gated features remain allowed with warning');
            $table->unsignedInteger('expiring_warning_days')->default(14)
                ->comment('Days before subscription_ends_at when status becomes EXPIRING');
            $table->timestamps();
        });

        DB::table('subscription_settings')->insert([
            'grace_period_days' => 7,
            'expiring_warning_days' => 14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_settings');
    }
};
