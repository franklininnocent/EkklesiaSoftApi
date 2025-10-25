<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Changes user_type column from VARCHAR to INTEGER:
     * - 1 = primary_contact
     * - 2 = secondary_contact
     */
    public function up(): void
    {
        // Step 1: Drop the existing check constraint
        DB::statement("
            ALTER TABLE users DROP CONSTRAINT IF EXISTS check_user_type
        ");

        // Step 2: Update existing string values to integers
        DB::statement("
            UPDATE users 
            SET user_type = CASE 
                WHEN user_type = 'primary_contact' THEN '1'
                WHEN user_type = 'secondary_contact' THEN '2'
                ELSE user_type
            END
            WHERE user_type IS NOT NULL
        ");

        // Step 3: Change column type to INTEGER
        DB::statement("
            ALTER TABLE users 
            ALTER COLUMN user_type TYPE INTEGER USING user_type::integer
        ");

        // Step 4: Add new check constraint for integer values
        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT check_user_type 
            CHECK (user_type IN (1, 2) OR user_type IS NULL)
        ");

        // Step 5: Add comment to column
        DB::statement("
            COMMENT ON COLUMN users.user_type IS '1=primary_contact, 2=secondary_contact'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Drop the integer check constraint
        DB::statement("
            ALTER TABLE users DROP CONSTRAINT IF EXISTS check_user_type
        ");

        // Step 2: Remove comment
        DB::statement("
            COMMENT ON COLUMN users.user_type IS NULL
        ");

        // Step 3: Change column type back to VARCHAR
        DB::statement("
            ALTER TABLE users 
            ALTER COLUMN user_type TYPE VARCHAR(255) USING user_type::varchar
        ");

        // Step 4: Convert integers back to strings
        DB::statement("
            UPDATE users 
            SET user_type = CASE 
                WHEN user_type = '1' THEN 'primary_contact'
                WHEN user_type = '2' THEN 'secondary_contact'
                ELSE user_type
            END
            WHERE user_type IS NOT NULL
        ");

        // Step 5: Restore the original string check constraint
        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT check_user_type 
            CHECK (user_type IN ('primary_contact', 'secondary_contact', 'admin', 'user') OR user_type IS NULL)
        ");
    }
};
