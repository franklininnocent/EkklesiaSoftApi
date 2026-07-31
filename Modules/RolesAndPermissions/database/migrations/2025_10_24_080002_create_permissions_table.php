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
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique()->comment('Permission identifier (e.g., users.create, tenants.delete)');
            $table->string('display_name')->comment('Human-readable name');
            $table->text('description')->nullable();
            $table->string('module')->nullable()->comment('Module this permission belongs to');
            $table->string('category')->nullable()->comment('Permission category (e.g., users, tenants, roles)');
            
            // Tenant support for permissions
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            $table->boolean('is_custom')->default(0)->comment('1=Custom permission (tenant-specific), 0=System permission');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            
            $table->softDeletes();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('name');
            $table->index('module');
            $table->index('category');
            $table->index('tenant_id');
            $table->index(['active', 'deleted_at']);
            $table->index(['tenant_id', 'active', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};

