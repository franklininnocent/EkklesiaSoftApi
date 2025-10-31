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
        Schema::create('families', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();
            
            // Tenant Relationship
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Family Identification
            $table->string('family_code', 50)->comment('Unique family code within tenant');
            $table->string('family_name', 255)->comment('Family name or surname');
            $table->string('head_of_family', 255)->nullable()->comment('Name of family head');
            
            // Address Information
            $table->string('address_line_1', 500)->nullable();
            $table->string('address_line_2', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('postal_code', 20)->nullable();
            
            // Contact Information
            $table->string('primary_phone', 20)->nullable();
            $table->string('secondary_phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            
            // BCC Relationship (nullable - family can exist without BCC)
            $table->uuid('bcc_id')->nullable();
            
            // Status and Metadata
            $table->enum('status', ['active', 'inactive', 'migrated'])->default('active');
            $table->text('notes')->nullable();
            
            // Audit Fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('state_id')->references('id')->on('states')->onDelete('set null');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            
            // Note: bcc_id foreign key will be added after bccs table is created
            
            // Indexes
            $table->index('tenant_id');
            $table->index('bcc_id');
            $table->index('status');
            $table->unique(['tenant_id', 'family_code']); // Unique family code per tenant
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
