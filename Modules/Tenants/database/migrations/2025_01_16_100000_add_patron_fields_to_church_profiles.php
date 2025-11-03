<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add Patron fields to Church Profiles
 * 
 * Adds patron_name and patron_image_path fields to church_profiles table.
 * Patron data is tenant-specific (each church can have its own patron).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('church_profiles', function (Blueprint $table) {
            $table->string('patron_name', 255)->nullable()->after('bishop_id')
                ->comment('Name of the church patron saint');
            $table->string('patron_image_path', 255)->nullable()->after('patron_name')
                ->comment('Path to patron saint image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_profiles', function (Blueprint $table) {
            $table->dropColumn(['patron_name', 'patron_image_path']);
        });
    }
};


