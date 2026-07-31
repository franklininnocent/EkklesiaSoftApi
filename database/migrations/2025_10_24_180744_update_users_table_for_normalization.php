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
     * Updates users table to support normalized tenant-user relationship
     * Adds user_type field to distinguish primary/secondary contacts
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add contact_number field if it doesn't exist
            if (!Schema::hasColumn('users', 'contact_number')) {
                $table->string('contact_number', 20)->nullable()->after('email')
                    ->comment('User contact/phone number');
            }
            
            // Add user_type field to distinguish different types of users
            $table->string('user_type', 50)->nullable()->after('contact_number')
                ->comment('User type: primary_contact, secondary_contact, admin, member, etc.');
            
            // Ensure tenant_id exists and is properly indexed
            if (!Schema::hasColumn('users', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id')
                    ->comment('Foreign key to tenant this user belongs to');
            }
            
            // Add active field if it doesn't exist
            if (!Schema::hasColumn('users', 'active')) {
                $table->integer('active')->default(1)->after('password')
                    ->comment('1=Active, 0=Inactive');
            }
        });
        
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Add foreign key constraint for tenant_id with CASCADE delete
            DB::statement('
                ALTER TABLE users 
                DROP CONSTRAINT IF EXISTS fk_users_tenant_id
            ');
            
            DB::statement('
                ALTER TABLE users 
                ADD CONSTRAINT fk_users_tenant_id 
                FOREIGN KEY (tenant_id) 
                REFERENCES tenants(id) 
                ON DELETE CASCADE 
                ON UPDATE CASCADE
            ');
            
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_users_tenant_type 
                ON users(tenant_id, user_type) 
                WHERE deleted_at IS NULL
            ');
            
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_users_type 
                ON users(user_type) 
                WHERE deleted_at IS NULL
            ');
            
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_users_contact 
                ON users(contact_number) 
                WHERE contact_number IS NOT NULL
            ');
            
            DB::statement("
                ALTER TABLE users 
                DROP CONSTRAINT IF EXISTS check_user_type
            ");
            
            DB::statement("
                ALTER TABLE users 
                ADD CONSTRAINT check_user_type 
                CHECK (user_type IS NULL OR user_type IN (
                    'primary_contact', 
                    'secondary_contact', 
                    'admin', 
                    'manager', 
                    'member', 
                    'guest'
                ))
            ");
            
            DB::statement("COMMENT ON COLUMN users.user_type IS 'Type of user: primary_contact, secondary_contact, admin, manager, member, guest'");
            DB::statement("COMMENT ON COLUMN users.tenant_id IS 'Foreign key to tenant. NULL for super admin users.'");
            return;
        }

        // Portable fallback for sqlite/mysql test environments.
        Schema::table('users', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_type'], 'idx_users_tenant_type');
            $table->index(['user_type'], 'idx_users_type');
            $table->index(['contact_number'], 'idx_users_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remove the user_type column
            $table->dropColumn('user_type');
        });
        
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS fk_users_tenant_id');
            DB::statement('DROP INDEX IF EXISTS idx_users_tenant_type');
            DB::statement('DROP INDEX IF EXISTS idx_users_type');
            DB::statement('DROP INDEX IF EXISTS idx_users_contact');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS check_user_type');
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropIndex('idx_users_tenant_type');
            } catch (\Throwable $th) {}
            try {
                $table->dropIndex('idx_users_type');
            } catch (\Throwable $th) {}
            try {
                $table->dropIndex('idx_users_contact');
            } catch (\Throwable $th) {}
        });
    }
};
