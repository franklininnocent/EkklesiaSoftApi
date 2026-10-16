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
        Schema::table('pope_details', function (Blueprint $table) {
            // Add index on updated_at for faster getCurrent() queries
            // Using try-catch to handle case where index might already exist
            try {
                $table->index('updated_at', 'pope_details_updated_at_index');
            } catch (\Exception $e) {
                // Index might already exist, ignore
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pope_details', function (Blueprint $table) {
            try {
                $table->dropIndex('pope_details_updated_at_index');
            } catch (\Exception $e) {
                // Index might not exist, ignore
            }
        });
    }
};

