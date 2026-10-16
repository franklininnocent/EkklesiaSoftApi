<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_plan_preview_tokens')) {
            return;
        }

        Schema::table('marketing_plan_preview_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_plan_preview_tokens', 'session_hash')) {
                $table->string('session_hash', 64)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_plan_preview_tokens')
            || ! Schema::hasColumn('marketing_plan_preview_tokens', 'session_hash')) {
            return;
        }

        Schema::table('marketing_plan_preview_tokens', function (Blueprint $table) {
            $table->dropUnique(['session_hash']);
            $table->dropColumn('session_hash');
        });
    }
};
