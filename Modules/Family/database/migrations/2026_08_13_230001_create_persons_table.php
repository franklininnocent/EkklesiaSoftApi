<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical parish Person (ADR-24). May exist with zero FamilyMembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth', 255)->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('father_name', 255)->nullable();
            $table->string('mother_name', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address_line_1', 500)->nullable();
            $table->string('address_line_2', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'deceased'])->default('active');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['tenant_id', 'last_name', 'first_name'], 'persons_tenant_name_idx');
            $table->index(['tenant_id', 'date_of_birth'], 'persons_tenant_dob_idx');
            $table->index(['tenant_id', 'email'], 'persons_tenant_email_idx');
            $table->index(['tenant_id', 'phone'], 'persons_tenant_phone_idx');
            $table->index(['tenant_id', 'status'], 'persons_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
