<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds father and mother name fields for bride and groom in marriage records.
     * These fields follow the same pattern as other marriage fields and are properly
     * normalized within the family_members table.
     */
    public function up(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            // Add bride father and mother name fields
            // Using explicit length (255) for consistency with other name fields
            $table->string('marriage_bride_father_name', 255)->nullable()->after('marriage_bride_full_name')
                ->comment('Bride\'s father name in marriage record');
            $table->string('marriage_bride_mother_name', 255)->nullable()->after('marriage_bride_father_name')
                ->comment('Bride\'s mother name in marriage record');
            
            // Add groom father and mother name fields
            $table->string('marriage_groom_father_name', 255)->nullable()->after('marriage_groom_full_name')
                ->comment('Groom\'s father name in marriage record');
            $table->string('marriage_groom_mother_name', 255)->nullable()->after('marriage_groom_father_name')
                ->comment('Groom\'s mother name in marriage record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropColumn([
                'marriage_bride_father_name',
                'marriage_bride_mother_name',
                'marriage_groom_father_name',
                'marriage_groom_mother_name',
            ]);
        });
    }
};

