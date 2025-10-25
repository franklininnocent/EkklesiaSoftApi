<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration removes the denormalized user and address fields from the tenants table
     * since the system now uses normalized tables (users and addresses) for this data.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Drop denormalized user and address columns
            $columns = [
                'primary_user_name',
                'primary_user_email',
                'primary_contact_number',
                'primary_user_address',
                'secondary_user_name',
                'secondary_user_email',
                'secondary_contact_number',
                'secondary_user_address',
            ];
            
            // Also drop official_address columns if they exist (from intermediate migration)
            if (Schema::hasColumn('tenants', 'official_address')) {
                $columns[] = 'official_address';
            }
            if (Schema::hasColumn('tenants', 'official_address2')) {
                $columns[] = 'official_address2';
            }
            
            // Check and drop index if it exists
            $existingColumns = [];
            foreach ($columns as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $existingColumns[] = $column;
                }
            }
            
            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Restore the columns as nullable for rollback purposes
            $table->string('primary_user_name')->nullable()->comment('Primary contact name');
            $table->string('primary_user_email')->nullable()->comment('Primary contact email');
            $table->string('primary_contact_number')->nullable()->comment('Primary contact phone');
            $table->json('primary_user_address')->nullable()->comment('Primary user address details');
            
            $table->string('secondary_user_name')->nullable()->comment('Secondary contact name');
            $table->string('secondary_user_email')->nullable()->comment('Secondary contact email');
            $table->string('secondary_contact_number')->nullable()->comment('Secondary contact phone');
            $table->json('secondary_user_address')->nullable()->comment('Secondary user address details');
            
            // Add index back
            $table->index('primary_user_email');
        });
    }
};

