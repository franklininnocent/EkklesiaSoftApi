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
            $table->string('marriage_bride_full_name')->nullable()->after('marriage_spouse_name');
            $table->string('marriage_bride_address')->nullable()->after('marriage_bride_full_name');
            $table->string('marriage_bride_church_type', 25)->nullable()->after('marriage_bride_address')->comment('home_parish or other');
            $table->string('marriage_bride_church_name')->nullable()->after('marriage_bride_church_type');
            $table->string('marriage_bride_church_address')->nullable()->after('marriage_bride_church_name');

            $table->string('marriage_groom_full_name')->nullable()->after('marriage_bride_church_address');
            $table->string('marriage_groom_address')->nullable()->after('marriage_groom_full_name');
            $table->string('marriage_groom_church_type', 25)->nullable()->after('marriage_groom_address')->comment('home_parish or other');
            $table->string('marriage_groom_church_name')->nullable()->after('marriage_groom_church_type');
            $table->string('marriage_groom_church_address')->nullable()->after('marriage_groom_church_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropColumn([
                'marriage_bride_full_name',
                'marriage_bride_address',
                'marriage_bride_church_type',
                'marriage_bride_church_name',
                'marriage_bride_church_address',
                'marriage_groom_full_name',
                'marriage_groom_address',
                'marriage_groom_church_type',
                'marriage_groom_church_name',
                'marriage_groom_church_address',
            ]);
        });
    }
};

