<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marriage witness (and other external participants) may store a sacrament-local contact number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->string('external_contact_number', 20)->nullable()->after('external_address');
        });
    }

    public function down(): void
    {
        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->dropColumn('external_contact_number');
        });
    }
};
