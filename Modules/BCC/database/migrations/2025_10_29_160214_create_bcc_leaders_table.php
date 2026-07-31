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
        Schema::create('bcc_leaders', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();
            
            // BCC Relationship
            $table->uuid('bcc_id');
            $table->foreign('bcc_id')->references('id')->on('bccs')->onDelete('cascade');
            
            // Leader (from family_members table)
            $table->uuid('family_member_id');
            $table->foreign('family_member_id')->references('id')->on('family_members')->onDelete('cascade');
            
            // Leadership Role
            $table->enum('role', [
                'leader',           // Primary BCC Leader
                'coordinator',      // BCC Coordinator
                'assistant',        // Assistant Leader
                'secretary',        // BCC Secretary
                'treasurer',        // BCC Treasurer
                'animator',         // BCC Animator
                'other'            // Other roles
            ])->default('leader');
            
            $table->string('role_description', 255)->nullable()->comment('Additional role description');
            
            // Appointment Information
            $table->date('appointed_date')->nullable()->comment('Date appointed to this role');
            $table->date('term_start_date')->nullable()->comment('Term start date');
            $table->date('term_end_date')->nullable()->comment('Term end date');
            $table->boolean('is_active')->default(true)->comment('Is currently active in this role');
            
            // Contact Information (optional, can override member contact)
            $table->string('leader_phone', 20)->nullable();
            $table->string('leader_email', 255)->nullable();
            
            // Metadata
            $table->text('responsibilities')->nullable()->comment('Specific responsibilities');
            $table->text('notes')->nullable();
            
            // Audit Fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('bcc_id');
            $table->index('family_member_id');
            $table->index('role');
            $table->index('is_active');
            $table->index(['bcc_id', 'is_active']);
            
            // Unique Constraint: One person can have only one active role per BCC
            $table->unique(['bcc_id', 'family_member_id', 'role'], 'unique_active_bcc_leader');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bcc_leaders');
    }
};
