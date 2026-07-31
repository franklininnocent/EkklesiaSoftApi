<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Make country and region columns nullable in archdioceses table
 * 
 * These columns are being deprecated in favor of normalized country_id and state_id.
 * Making them nullable allows for smooth migration to the new structure.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->change();
            $table->string('region', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            $table->string('country', 100)->nullable(false)->change();
            $table->string('region', 100)->nullable()->change();
        });
    }
};


