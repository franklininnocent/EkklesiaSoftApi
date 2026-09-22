<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 120)->unique();
            $table->string('event_type', 120);
            $table->string('category', 60);
            $table->string('module', 60);
            $table->string('title_template', 500);
            $table->text('body_template');
            $table->jsonb('channels')->default('{"in_app":true,"email":false,"push":false}');
            $table->string('priority', 20)->default('normal');
            $table->boolean('mandatory')->default(false);
            $table->boolean('notify_actor')->default(false);
            $table->string('recipient_strategy', 80);
            $table->string('collapse_mode', 40)->default('none');
            $table->boolean('allows_delete')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('definition_id');
            $table->string('event_type', 120);
            $table->string('category', 60);
            $table->string('module', 60);
            $table->string('priority', 20)->default('normal');
            $table->string('inbox_scope', 20);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_display_name', 255)->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->string('title', 500);
            $table->text('body');
            $table->jsonb('data')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->string('collapse_key', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('definition_id')->references('id')->on('notification_definitions');
            $table->index(['subject_type', 'subject_id']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notification_events ADD CONSTRAINT notification_events_scope_tenant_chk CHECK (
                (inbox_scope = 'platform' AND tenant_id IS NULL) OR
                (inbox_scope = 'tenant' AND tenant_id IS NOT NULL)
            )");
        }

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_event_id');
            $table->unsignedBigInteger('user_id');
            $table->string('inbox_scope', 20);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('status', 20)->default('unread');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('action_status', 20)->default('none');
            $table->boolean('is_mention')->default(false);
            $table->string('collapse_key', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('notification_event_id')->references('id')->on('notification_events')->cascadeOnDelete();
            $table->unique(['notification_event_id', 'user_id']);
            $table->index(['user_id', 'inbox_scope', 'tenant_id', 'archived_at', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_scope_tenant_chk CHECK (
                (inbox_scope = 'platform' AND tenant_id IS NULL) OR
                (inbox_scope = 'tenant' AND tenant_id IS NOT NULL)
            )");

            DB::statement('CREATE INDEX user_notifications_unread_idx ON user_notifications (user_id, inbox_scope, tenant_id) WHERE status = \'unread\' AND archived_at IS NULL AND deleted_at IS NULL');
            DB::statement("CREATE INDEX user_notifications_action_required_idx ON user_notifications (user_id, inbox_scope, tenant_id, created_at DESC) WHERE action_status = 'required' AND archived_at IS NULL AND deleted_at IS NULL");
            DB::statement('CREATE INDEX user_notifications_collapse_idx ON user_notifications (user_id, collapse_key) WHERE status = \'unread\' AND collapse_key IS NOT NULL');
        } else {
            Schema::table('user_notifications', function (Blueprint $table) {
                $table->index(['user_id', 'inbox_scope', 'tenant_id', 'status'], 'user_notifications_unread_idx');
                $table->index(['user_id', 'collapse_key', 'status'], 'user_notifications_collapse_idx');
            });
        }

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_notification_id');
            $table->string('channel', 20);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('provider_id', 255)->nullable();
            $table->timestamps();

            $table->foreign('user_notification_id')->references('id')->on('user_notifications')->cascadeOnDelete();
            $table->unique(['user_notification_id', 'channel']);
            $table->index(['status', 'next_retry_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('definition_code', 120)->nullable();
            $table->string('category', 60)->nullable();
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('push')->default(false);
            $table->string('digest', 20)->default('immediate');
            $table->timestamps();

            $table->unique(['user_id', 'definition_code']);
            $table->index(['user_id', 'category']);
        });

        Schema::create('notification_preference_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('definition_code', 120);
            $table->boolean('force_in_app')->default(false);
            $table->boolean('force_email')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'definition_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preference_policies');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_definitions');
    }
};
