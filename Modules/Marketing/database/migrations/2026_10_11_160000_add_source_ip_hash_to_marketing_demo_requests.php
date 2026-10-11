<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_demo_requests')) {
            return;
        }

        if (Schema::hasColumn('marketing_demo_requests', 'source_ip_hash')) {
            return;
        }

        Schema::table('marketing_demo_requests', function (Blueprint $table) {
            $table->string('source_ip_hash', 64)->nullable()->after('correlation_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_demo_requests')
            || ! Schema::hasColumn('marketing_demo_requests', 'source_ip_hash')) {
            return;
        }

        Schema::table('marketing_demo_requests', function (Blueprint $table) {
            $table->dropColumn('source_ip_hash');
        });
    }
};
