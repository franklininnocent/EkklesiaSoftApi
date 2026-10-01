<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('kind', 32);
            $table->string('coverage_mode', 32)->default('full_week');
            $table->json('selected_weekdays')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('default_place', 255)->nullable();
            $table->string('default_celebrant_name', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'kind', 'status']);
        });

        Schema::create('mass_schedule_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('schedule_id');
            $table->unsignedInteger('revision_number');
            $table->string('status', 32)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->char('content_fingerprint', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'schedule_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_schedules')
                ->cascadeOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'schedule_id', 'revision_number']);
            $table->index(['tenant_id', 'schedule_id', 'status']);
        });

        Schema::create('mass_schedule_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('revision_id');
            $table->uuid('slot_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('celebrated_at');
            $table->string('place', 255)->nullable();
            $table->string('celebrant_name', 255)->nullable();
            $table->string('place_source', 16)->default('inherit');
            $table->string('celebrant_source', 16)->default('inherit');
            $table->string('place_key', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'revision_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_schedule_revisions')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'revision_id', 'weekday', 'celebrated_at', 'place_key'], 'mass_schedule_slots_revision_slot_unique');
            $table->index(['tenant_id', 'slot_id']);
        });

        Schema::create('mass_day_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->date('override_on');
            $table->string('mode', 32);
            $table->boolean('closes_regular_masses')->default(false);
            $table->string('label', 120)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'override_on', 'status']);
        });

        Schema::create('mass_day_override_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('day_override_id');
            $table->uuid('slot_id');
            $table->time('celebrated_at');
            $table->string('place', 255)->nullable();
            $table->string('celebrant_name', 255)->nullable();
            $table->string('place_key', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'day_override_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_day_overrides')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'day_override_id', 'celebrated_at', 'place_key'], 'mass_day_override_slots_unique');
        });

        Schema::create('mass_generation_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->date('last_generated_through')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->char('last_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('mass_reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->char('fingerprint', 64);
            $table->date('range_from');
            $table->date('range_to');
            $table->json('counts');
            $table->unsignedInteger('conflict_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('mass_celebrations', function (Blueprint $table) {
            $table->string('origin', 32)->default('one_time')->after('tenant_id');
            $table->uuid('schedule_id')->nullable()->after('origin');
            $table->uuid('revision_id')->nullable()->after('schedule_id');
            $table->uuid('slot_id')->nullable()->after('revision_id');
            $table->uuid('day_override_id')->nullable()->after('slot_id');
            $table->string('generation_status', 32)->default('active')->after('status');
            $table->string('suppression_reason', 32)->nullable()->after('generation_status');
            $table->timestamp('suppressed_at')->nullable()->after('suppression_reason');
            $table->string('source_label', 255)->nullable()->after('suppressed_at');
            $table->boolean('is_exception')->default(false)->after('source_label');
            $table->string('timezone', 64)->nullable()->after('is_exception');
            $table->string('place_source', 16)->nullable()->after('place');
            $table->string('celebrant_source', 16)->nullable()->after('celebrant_name');
            $table->string('occasion', 32)->nullable()->after('celebrant_source');
            $table->text('notes')->nullable()->after('occasion');

            $table->foreign(['tenant_id', 'schedule_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_schedules')
                ->nullOnDelete();
            $table->foreign(['tenant_id', 'revision_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_schedule_revisions')
                ->nullOnDelete();
            $table->foreign(['tenant_id', 'day_override_id'])
                ->references(['tenant_id', 'id'])
                ->on('mass_day_overrides')
                ->nullOnDelete();
            $table->index(['tenant_id', 'schedule_id']);
            $table->index(['tenant_id', 'slot_id', 'celebrated_on']);
            $table->index(['tenant_id', 'origin', 'celebrated_on']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX mass_celebrations_tenant_slot_date_unique ON mass_celebrations (tenant_id, slot_id, celebrated_on) WHERE slot_id IS NOT NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX mass_celebrations_tenant_slot_date_unique ON mass_celebrations (tenant_id, slot_id, celebrated_on) WHERE slot_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('mass_celebrations', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'schedule_id']);
            $table->dropForeign(['tenant_id', 'revision_id']);
            $table->dropForeign(['tenant_id', 'day_override_id']);
            $table->dropColumn([
                'origin',
                'schedule_id',
                'revision_id',
                'slot_id',
                'day_override_id',
                'generation_status',
                'suppression_reason',
                'suppressed_at',
                'source_label',
                'is_exception',
                'timezone',
                'place_source',
                'celebrant_source',
                'occasion',
                'notes',
            ]);
        });

        Schema::dropIfExists('mass_reconciliation_runs');
        Schema::dropIfExists('mass_generation_cursors');
        Schema::dropIfExists('mass_day_override_slots');
        Schema::dropIfExists('mass_day_overrides');
        Schema::dropIfExists('mass_schedule_slots');
        Schema::dropIfExists('mass_schedule_revisions');
        Schema::dropIfExists('mass_schedules');

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS mass_celebrations_tenant_slot_date_unique');
        }
    }
};
