<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform support session store (Support Access Phase 0 foundation).
 * Populated by SupportAccess APIs in Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('support_user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('mode', 32); // readonly|standard|emergency
            $table->string('reason_code', 64);
            $table->text('reason_description')->nullable();
            $table->string('ticket_ref', 128)->nullable();
            $table->string('status', 32)->default('active'); // active|ended|expired
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_reason', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();

            $table->index(['support_user_id', 'status']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['status', 'expires_at']);

            $table->foreign('support_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('support_session_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('support_session_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('effective_tenant_id');
            $table->string('event_type', 64);
            $table->string('module', 64)->nullable();
            $table->string('page', 128)->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('action', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['support_session_id', 'created_at']);
            $table->index(['effective_tenant_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);

            $table->foreign('support_session_id')->references('id')->on('support_sessions')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('effective_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('support_session_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->jsonb('value');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_session_events');
        Schema::dropIfExists('support_sessions');
        Schema::dropIfExists('support_session_settings');
    }
};
