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
        Schema::table('bccs', function (Blueprint $table) {
            $table->string('location', 255)->nullable()->after('description')->comment('Location/Street address of the BCC');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bccs', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
