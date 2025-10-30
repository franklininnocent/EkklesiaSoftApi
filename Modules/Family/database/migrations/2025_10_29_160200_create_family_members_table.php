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
        Schema::create('family_members', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();
            
            // Family Relationship
            $table->uuid('family_id');
            $table->foreign('family_id')->references('id')->on('families')->onDelete('cascade');
            
            // Personal Information
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            // Note: full_name will be handled as an accessor in the model
            
            // Demographics
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->enum('relationship_to_head', [
                'self', 'spouse', 'son', 'daughter', 'father', 'mother',
                'brother', 'sister', 'grandfather', 'grandmother',
                'grandson', 'granddaughter', 'uncle', 'aunt', 
                'nephew', 'niece', 'cousin', 'other'
            ])->default('other');
            
            $table->enum('marital_status', [
                'single', 'married', 'widowed', 'separated', 'divorced'
            ])->default('single');
            
            // Contact Information
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_primary_contact')->default(false)->comment('Primary contact for the family');
            
            // Sacrament Information
            $table->date('baptism_date')->nullable();
            $table->string('baptism_place', 255)->nullable();
            $table->date('first_communion_date')->nullable();
            $table->string('first_communion_place', 255)->nullable();
            $table->date('confirmation_date')->nullable();
            $table->string('confirmation_place', 255)->nullable();
            $table->date('marriage_date')->nullable();
            $table->string('marriage_place', 255)->nullable();
            $table->string('marriage_spouse_name', 255)->nullable();
            
            // Additional Information
            $table->string('occupation', 255)->nullable();
            $table->string('education', 255)->nullable();
            $table->text('skills_talents')->nullable();
            $table->text('notes')->nullable();
            
            // Status
            $table->enum('status', ['active', 'inactive', 'deceased', 'migrated'])->default('active');
            $table->date('deceased_date')->nullable();
            
            // Audit Fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('family_id');
            $table->index('status');
            $table->index('date_of_birth');
            $table->index('relationship_to_head');
            $table->index('is_primary_contact');
            $table->fullText(['first_name', 'middle_name', 'last_name']); // Full-text search
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
