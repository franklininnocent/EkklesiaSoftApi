<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create role_user pivot table for many-to-many relationship
 * 
 * This migration enables users to have multiple roles, allowing flexible
 * permission management where users inherit permissions from all assigned roles.
 * 
 * Features:
 * - Many-to-many relationship between users and roles
 * - Composite primary key (user_id, role_id)
 * - Foreign key constraints with cascading deletes
 * - Indexed columns for optimal query performance
 * - Timestamps for audit trail
 * 
 * @author Development Team
 * @date 2025-10-25
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            // Primary Keys
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            
            // Timestamps for audit trail
            $table->timestamps();
            
            // Foreign Key Constraints
            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->onDelete('cascade')  // When role is deleted, remove assignments
                ->onUpdate('cascade');
            
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade')  // When user is deleted, remove assignments
                ->onUpdate('cascade');
            
            // Composite Primary Key
            // Ensures a user can't be assigned the same role twice
            $table->primary(['role_id', 'user_id'], 'role_user_primary');
            
            // Indexes for Performance
            // These indexes optimize queries for:
            // - Getting all users for a role
            // - Getting all roles for a user
            $table->index('role_id', 'role_user_role_id_index');
            $table->index('user_id', 'role_user_user_id_index');
            $table->index(['user_id', 'role_id'], 'role_user_user_role_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};

