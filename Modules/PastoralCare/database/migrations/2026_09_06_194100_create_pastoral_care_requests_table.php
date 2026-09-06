<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pastoral_care_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('family_id');
            $table->uuid('person_id')->nullable();
            $table->string('type', 32);
            $table->string('priority', 16)->default('routine');
            $table->string('status', 16)->default('open');
            $table->string('summary', 255);
            $table->text('notes')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('family_id')->references('id')->on('families')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['tenant_id', 'assigned_to_user_id']);
            $table->index(['tenant_id', 'family_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pastoral_care_requests');
    }
};
