<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Sacrament lifecycle audit (correction / void / delete / restore).
 * Full before/after JSON is restricted; access gated by elevated permission in later UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sacrament_audit_logs')) {
            return;
        }

        Schema::create('sacrament_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('event', 64);
            $table->string('target_type', 64)->default('sacrament');
            $table->string('target_id', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'event', 'created_at']);
            $table->index(['tenant_id', 'support_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacrament_audit_logs');
    }
};
