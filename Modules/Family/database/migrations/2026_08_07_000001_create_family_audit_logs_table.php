<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.1: Family domain audit with support session correlation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('family_audit_logs')) {
            return;
        }

        Schema::create('family_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('event', 64);
            $table->string('target_type', 64);
            $table->string('target_id', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'support_session_id']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_audit_logs');
    }
};
