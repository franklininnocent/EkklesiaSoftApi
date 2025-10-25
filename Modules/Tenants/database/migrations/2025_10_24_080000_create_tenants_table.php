<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->comment('Organization/Church name');
            $table->string('slogan')->nullable()->comment('Tenant slogan or tagline');
            $table->string('slug')->unique()->comment('URL-friendly identifier');
            $table->string('domain')->nullable()->unique()->comment('Custom domain (optional)');
            
            // NOTE: User information is stored in normalized 'users' table with tenant_id foreign key
            // NOTE: Address information is stored in normalized 'addresses' table with addressable polymorphic relation
            
            // Subscription & limits
            $table->string('plan')->default('free')->comment('Subscription plan');
            $table->integer('max_users')->default(10)->comment('Maximum allowed users');
            $table->integer('max_storage_mb')->default(100)->comment('Storage limit in MB');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            
            // Status & settings
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->json('settings')->nullable()->comment('Tenant-specific settings');
            $table->json('features')->nullable()->comment('Enabled features');
            
            // Branding
            $table->string('logo_url')->nullable()->comment('Path to tenant logo');
            $table->string('primary_color')->nullable()->default('#3B82F6');
            $table->string('secondary_color')->nullable()->default('#10B981');
            
            // Audit fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('slug');
            $table->index('domain');
            $table->index(['active', 'deleted_at']);
            $table->index('plan');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

