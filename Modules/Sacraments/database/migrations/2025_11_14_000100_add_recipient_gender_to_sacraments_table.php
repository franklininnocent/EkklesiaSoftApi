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
            $table->string('recipient_gender', 20)
                ->nullable()
                ->after('recipient_birth_place')
                ->comment('Gender of the recipient: male, female, or other');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropColumn('recipient_gender');
        });
    }
};

