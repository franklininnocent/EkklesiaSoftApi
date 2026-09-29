<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments are the canonical diocese assignment; archdiocese_id on bishops
     * is legacy compatibility and may be unknown when creating a person record first.
     */
    public function up(): void
    {
        Schema::table('bishops', function (Blueprint $table) {
            $table->unsignedBigInteger('archdiocese_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bishops', function (Blueprint $table) {
            $table->unsignedBigInteger('archdiocese_id')->nullable(false)->change();
        });
    }
};
