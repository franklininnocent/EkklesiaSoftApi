<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds foreign key constraint from church_profiles to bishops table.
     * This runs after the bishops table has been created.
     */
    public function up(): void
    {
        Schema::table('church_profiles', function (Blueprint $table) {
            $table->foreign('bishop_id')
                ->references('id')->on('bishops')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_profiles', function (Blueprint $table) {
            $table->dropForeign(['bishop_id']);
        });
    }
};
