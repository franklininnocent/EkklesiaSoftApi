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
        Schema::table('family_members', function (Blueprint $table) {
            $table->string('baptism_godparent_primary')->nullable()->after('baptism_place');
            $table->string('baptism_godparent_secondary')->nullable()->after('baptism_godparent_primary');
            $table->string('baptism_location_type', 25)->nullable()->after('baptism_godparent_secondary')->comment('home_parish or other');
            $table->string('baptism_church_name')->nullable()->after('baptism_location_type');
            $table->string('baptism_church_address')->nullable()->after('baptism_church_name');
            $table->string('baptism_priest_name')->nullable()->after('baptism_church_address');
            $table->boolean('baptism_priest_is_home')->default(false)->after('baptism_priest_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropColumn([
                'baptism_godparent_primary',
                'baptism_godparent_secondary',
                'baptism_location_type',
                'baptism_church_name',
                'baptism_church_address',
                'baptism_priest_name',
                'baptism_priest_is_home',
            ]);
        });
    }
};

