<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_projects', function (Blueprint $table): void {
            $table->enum('assignment_mode', ['uniform', 'individual', 'uniform_with_exceptions'])
                ->default('uniform')
                ->after('code');
            $table->decimal('default_family_target', 14, 2)->default(0)->after('target_amount');
            $table->unsignedSmallInteger('installment_count')->default(1)->after('end_date');
            $table->enum('installment_frequency', ['weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly', 'custom'])
                ->nullable()
                ->after('installment_count');
            $table->unsignedSmallInteger('installment_interval_days')->nullable()->after('installment_frequency');
            $table->boolean('auto_generate_installments')->default(true)->after('installment_interval_days');
            $table->timestamp('completed_at')->nullable()->after('status');
        });

        Schema::create('project_family_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('project_id');
            $table->uuid('family_id');
            $table->decimal('target_amount', 14, 2)->nullable();
            $table->decimal('amount_collected', 14, 2)->default(0);
            $table->boolean('is_exempt')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'family_id', 'status']);
            $table->index(['tenant_id', 'project_id', 'family_id', 'effective_from']);
        });

        Schema::create('project_installment_dues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('project_id');
            $table->uuid('family_id');
            $table->unsignedSmallInteger('installment_number');
            $table->string('installment_label', 50);
            $table->date('due_date');
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->enum('status', ['pending', 'partially_paid', 'paid', 'waived', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['tenant_id', 'project_id', 'family_id', 'installment_number'],
                'project_installment_dues_unique'
            );
            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'family_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_installment_dues');
        Schema::dropIfExists('project_family_assignments');

        Schema::table('donation_projects', function (Blueprint $table): void {
            $table->dropColumn([
                'assignment_mode',
                'default_family_target',
                'installment_count',
                'installment_frequency',
                'installment_interval_days',
                'auto_generate_installments',
                'completed_at',
            ]);
        });
    }
};
