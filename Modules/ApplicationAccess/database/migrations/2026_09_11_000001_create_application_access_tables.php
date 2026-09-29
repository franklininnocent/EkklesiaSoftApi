<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_access_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_reference', 32)->unique();
            $table->string('oauth_access_token_id', 100)->nullable();
            $table->uuid('previous_session_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('identity_type', 64);
            $table->string('access_context', 32);
            $table->string('authentication_status', 32)->default('authenticated');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampTz('started_at');
            $table->timestampTz('last_activity_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedTinyInteger('ip_version')->nullable();
            $table->string('ip_class', 32)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('city', 128)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('geo_source', 64)->nullable();
            $table->string('geo_status', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('browser_version', 32)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('risk_level', 16)->default('LOW');
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->timestampsTz();

            $table->unique('oauth_access_token_id');
            $table->index(['status', 'last_activity_at']);
            $table->index(['user_id', 'started_at']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['support_session_id']);
            $table->index(['ip_address', 'started_at']);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('support_session_id')->references('id')->on('support_sessions')->nullOnDelete();
        });

        Schema::table('application_access_sessions', function (Blueprint $table) {
            $table->foreign('previous_session_id')->references('id')->on('application_access_sessions')->nullOnDelete();
        });

        Schema::create('application_access_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('event_schema_version')->default(1);
            $table->uuid('access_session_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('event_type', 64);
            $table->string('module_code', 64)->nullable();
            $table->string('feature_code', 64)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('action', 32)->nullable();
            $table->string('route_name', 128)->nullable();
            $table->string('normalized_route', 500)->nullable();
            $table->string('http_method', 16)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('authorization_result', 32)->nullable();
            $table->string('permission_code', 128)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedBigInteger('source_sequence')->default(0);
            $table->string('telemetry_source', 32)->default('APPLICATION_TELEMETRY');
            $table->timestampTz('occurred_at');
            $table->string('risk_level', 16)->default('LOW');
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['occurred_at', 'id']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['access_session_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['ip_address', 'occurred_at']);
            $table->index('request_id');
            $table->index('support_session_id');
            $table->index(['authorization_result', 'occurred_at']);

            $table->foreign('access_session_id')->references('id')->on('application_access_sessions')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('support_session_id')->references('id')->on('support_sessions')->nullOnDelete();
        });

        Schema::create('application_security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('event_schema_version')->default(1);
            $table->string('event_type', 64);
            $table->string('severity', 16)->default('MEDIUM');
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->uuid('access_session_id')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('action', 32)->nullable();
            $table->string('authorization_result', 32)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('detected_at');
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['event_type', 'detected_at']);
            $table->index(['actor_user_id', 'detected_at']);
            $table->index(['tenant_id', 'detected_at']);
            $table->index(['source_ip', 'detected_at']);
            $table->index('request_id');

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('access_session_id')->references('id')->on('application_access_sessions')->nullOnDelete();
            $table->foreign('support_session_id')->references('id')->on('support_sessions')->nullOnDelete();
        });

        Schema::create('application_security_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('signal_type', 64);
            $table->string('source_ip', 45)->nullable();
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->unsignedInteger('event_count')->default(0);
            $table->json('unique_routes')->nullable();
            $table->json('status_counts')->nullable();
            $table->timestampTz('first_seen');
            $table->timestampTz('last_seen');
            $table->string('risk_level', 16)->default('MEDIUM');
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->timestampsTz();

            $table->unique(['signal_type', 'source_ip', 'window_start']);
            $table->index(['signal_type', 'last_seen']);
        });

        Schema::create('application_ip_block_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ip_address', 45);
            $table->string('cidr', 64)->nullable();
            $table->string('scope', 32)->default('API');
            $table->text('reason');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['ip_address', 'revoked_at', 'expires_at']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('application_access_sessions')) {
            Schema::table('application_access_sessions', function (Blueprint $table) {
                $table->dropForeign(['previous_session_id']);
            });
        }

        Schema::dropIfExists('application_ip_block_rules');
        Schema::dropIfExists('application_security_signals');
        Schema::dropIfExists('application_security_events');
        Schema::dropIfExists('application_access_events');
        Schema::dropIfExists('application_access_sessions');
    }
};
