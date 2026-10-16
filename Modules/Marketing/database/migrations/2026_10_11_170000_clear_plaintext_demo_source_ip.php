<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_demo_requests') || ! Schema::hasColumn('marketing_demo_requests', 'source_ip')) {
            return;
        }

        DB::table('marketing_demo_requests')->whereNotNull('source_ip')->update(['source_ip' => null]);
    }

    public function down(): void
    {
        // Plaintext IPs are not restored.
    }
};
