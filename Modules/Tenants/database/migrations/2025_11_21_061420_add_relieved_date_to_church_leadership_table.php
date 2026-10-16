<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('church_leadership', function (Blueprint $table) {
            $table->date('relieved_date')->nullable()->after('appointed_date')->comment('Date when the leader was relieved from their position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_leadership', function (Blueprint $table) {
            $table->dropColumn('relieved_date');
        });
    }
};
