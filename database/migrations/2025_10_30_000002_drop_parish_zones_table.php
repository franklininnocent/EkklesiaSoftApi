<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('parish_zones')) {
            Schema::drop('parish_zones');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: original table definition lived in module migration; restoring here is non-trivial
    }
};


