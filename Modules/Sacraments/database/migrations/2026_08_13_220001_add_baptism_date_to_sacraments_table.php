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
        Schema::table('sacraments', function (Blueprint $table) {
            $table->date('baptism_date')
                ->nullable()
                ->after('recipient_gender')
                ->comment('Prior baptism date of the recipient (required for Eucharist)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropColumn('baptism_date');
        });
    }
};
