<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_plans', function (Blueprint $table): void {
            $table->enum('plan_type', ['uniform', 'individual'])->default('uniform')->after('code');
            $table->unsignedSmallInteger('custom_interval_days')->nullable()->after('frequency');
            $table->unsignedSmallInteger('grace_days')->default(0)->after('end_date');
            $table->boolean('auto_generate')->default(true)->after('grace_days');
            $table->text('description')->nullable()->after('auto_generate');
        });

        Schema::create('contribution_plan_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('plan_id');
            $table->uuid('family_id');
            $table->decimal('amount', 14, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_exempt')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'plan_id', 'status']);
            $table->index(['tenant_id', 'family_id', 'status']);
            $table->index(['tenant_id', 'plan_id', 'family_id', 'effective_from']);
        });

        Schema::create('contribution_plan_revision_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('plan_id');
            $table->uuid('family_id')->nullable();
            $table->string('change_type', 60);
            $table->decimal('old_amount', 14, 2)->nullable();
            $table->decimal('new_amount', 14, 2)->nullable();
            $table->date('effective_from')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'plan_id']);
            $table->index(['tenant_id', 'family_id']);
        });

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'plan_id', 'family_id', 'period_label'],
                'contribution_dues_unique_period'
            );
        });
    }

    public function down(): void
    {
        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropUnique('contribution_dues_unique_period');
        });

        Schema::dropIfExists('contribution_plan_revision_history');
        Schema::dropIfExists('contribution_plan_assignments');

        Schema::table('contribution_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_type',
                'custom_interval_days',
                'grace_days',
                'auto_generate',
                'description',
            ]);
        });
    }
};
