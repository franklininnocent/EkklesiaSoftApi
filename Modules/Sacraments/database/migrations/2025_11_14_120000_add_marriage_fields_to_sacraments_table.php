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
            $table->string('marriage_bride_full_name')->nullable()->after('mother_name');
            $table->string('marriage_bride_father_name')->nullable()->after('marriage_bride_full_name');
            $table->string('marriage_bride_mother_name')->nullable()->after('marriage_bride_father_name');
            $table->text('marriage_bride_address')->nullable()->after('marriage_bride_mother_name');
            $table->string('marriage_bride_church_type', 20)->nullable()->after('marriage_bride_address');
            $table->string('marriage_bride_church_name')->nullable()->after('marriage_bride_church_type');
            $table->text('marriage_bride_church_address')->nullable()->after('marriage_bride_church_name');

            $table->string('marriage_groom_full_name')->nullable()->after('marriage_bride_church_address');
            $table->string('marriage_groom_father_name')->nullable()->after('marriage_groom_full_name');
            $table->string('marriage_groom_mother_name')->nullable()->after('marriage_groom_father_name');
            $table->text('marriage_groom_address')->nullable()->after('marriage_groom_mother_name');
            $table->string('marriage_groom_church_type', 20)->nullable()->after('marriage_groom_address');
            $table->string('marriage_groom_church_name')->nullable()->after('marriage_groom_church_type');
            $table->text('marriage_groom_church_address')->nullable()->after('marriage_groom_church_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropColumn([
                'marriage_bride_full_name',
                'marriage_bride_father_name',
                'marriage_bride_mother_name',
                'marriage_bride_address',
                'marriage_bride_church_type',
                'marriage_bride_church_name',
                'marriage_bride_church_address',
                'marriage_groom_full_name',
                'marriage_groom_father_name',
                'marriage_groom_mother_name',
                'marriage_groom_address',
                'marriage_groom_church_type',
                'marriage_groom_church_name',
                'marriage_groom_church_address',
            ]);
        });
    }
};










