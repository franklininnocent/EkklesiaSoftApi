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
     * Creates a polymorphic addresses table that can be used by
     * multiple entities (Users, Tenants, etc.)
     * 
     * Optimized for handling lakhs of records with proper indexing
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            // Polymorphic relationship fields
            $table->unsignedBigInteger('addressable_id')->comment('Foreign key to related entity');
            $table->string('addressable_type')->comment('Entity type (User, Tenant, etc.)');
            
            // Address type and metadata
            $table->string('address_type', 50)->default('primary')->comment('Type: primary, secondary, billing, shipping, etc.');
            $table->string('label', 100)->nullable()->comment('Custom label for address (e.g., "Home", "Office")');
            
            // Address fields
            $table->string('line1')->comment('Address line 1 (street, building)');
            $table->string('line2')->nullable()->comment('Address line 2 (apartment, suite)');
            $table->string('district', 100)->comment('District/City district');
            $table->string('city', 100)->nullable()->comment('City name');
            $table->string('state_province', 100)->comment('State or Province');
            $table->string('country', 100)->comment('Country name');
            $table->string('pin_zip_code', 20)->comment('PIN or ZIP code');
            
            // Geolocation for mapping features (optional)
            $table->decimal('latitude', 10, 8)->nullable()->comment('GPS latitude');
            $table->decimal('longitude', 11, 8)->nullable()->comment('GPS longitude');
            
            // Status flags
            $table->boolean('is_default')->default(false)->comment('Is this the default address?');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            
            // Audit fields
            $table->timestamps();
            $table->softDeletes();
            
            // ===================================
            // INDEXES FOR PERFORMANCE
            // ===================================
            
            // Primary polymorphic index - most common query pattern
            $table->index(['addressable_type', 'addressable_id', 'deleted_at'], 'idx_addresses_poly');
            
            // Composite index for finding addresses by type and entity
            $table->index(['addressable_type', 'addressable_id', 'address_type', 'deleted_at'], 'idx_addresses_poly_type');
            
            // Index for finding default addresses
            $table->index(['addressable_type', 'addressable_id', 'is_default', 'deleted_at'], 'idx_addresses_default');
            
            // Indexes for geographic queries
            $table->index('country', 'idx_addresses_country');
            $table->index('state_province', 'idx_addresses_state');
            $table->index('pin_zip_code', 'idx_addresses_pin');
            
            // Spatial index for lat/long queries (for future map features)
            // Note: This will be added separately as it requires special syntax
            
            // Index for address type filtering
            $table->index(['address_type', 'deleted_at'], 'idx_addresses_type');
            
            // Index for active records
            $table->index(['active', 'deleted_at'], 'idx_addresses_active');
        });
        
        // Add check constraint for addressable_type (PostgreSQL)
        DB::statement("
            ALTER TABLE addresses 
            ADD CONSTRAINT check_addressable_type 
            CHECK (addressable_type IN ('User', 'Tenant', 'Organization'))
        ");
        
        // Add partial unique constraint for default addresses (only one default per entity/type)
        DB::statement("
            CREATE UNIQUE INDEX idx_addresses_unique_default 
            ON addresses (addressable_id, addressable_type, address_type) 
            WHERE is_default = true AND deleted_at IS NULL
        ");
        
        // Add comment to table
        DB::statement("COMMENT ON TABLE addresses IS 'Polymorphic addresses table for Users, Tenants, and other entities'");
        
        // Add comments to key columns
        DB::statement("COMMENT ON COLUMN addresses.addressable_id IS 'ID of the related entity (user_id, tenant_id, etc.)'");
        DB::statement("COMMENT ON COLUMN addresses.addressable_type IS 'Type of entity this address belongs to'");
        DB::statement("COMMENT ON COLUMN addresses.address_type IS 'Type of address: primary, secondary, billing, shipping, etc.'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
