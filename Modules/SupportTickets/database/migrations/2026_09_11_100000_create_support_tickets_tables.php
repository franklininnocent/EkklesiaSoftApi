<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_request_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('support_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_type_id')->constrained('support_request_types')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['request_type_id', 'parent_id']);
        });

        Schema::create('support_queues', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 128);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('support_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 16)->unique();
            $table->unsignedInteger('first_response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('requester_user_id');
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->foreignId('request_type_id')->constrained('support_request_types');
            $table->foreignId('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->constrained('support_queues')->nullOnDelete();
            $table->string('subject', 255);
            $table->text('description');
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('bug_details')->nullable();
            $table->string('business_impact', 64)->nullable();
            $table->string('affected_module', 128)->nullable();
            $table->string('environment', 32)->nullable();
            $table->timestamp('occurrence_at')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->string('status', 32)->default('new');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('total_paused_seconds')->default(0);
            $table->boolean('first_response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->boolean('sla_warning_sent')->default(false);
            $table->text('resolution_summary')->nullable();
            $table->string('resolution_category', 64)->nullable();
            $table->text('root_cause')->nullable();
            $table->text('workaround')->nullable();
            $table->text('permanent_fix')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('reopen_allowed_until')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('requester_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'requester_user_id']);
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index('first_response_due_at');
            $table->index('resolution_due_at');
        });

        Schema::create('support_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('author_user_id');
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['ticket_id', 'is_internal']);
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('original_name', 255);
            $table->string('storage_path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['ticket_id', 'tenant_id']);
        });

        Schema::create('support_ticket_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('added_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('added_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['ticket_id', 'user_id']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('support_session_id')->nullable();
            $table->string('event_type', 64);
            $table->string('old_value', 255)->nullable();
            $table->string('new_value', 255)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_participants');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_comments');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_sla_policies');
        Schema::dropIfExists('support_queues');
        Schema::dropIfExists('support_categories');
        Schema::dropIfExists('support_request_types');
    }
};
