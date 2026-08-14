<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_sacrament_settings')) {
            return;
        }

        Schema::create('tenant_sacrament_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sacrament_type_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sacrament_type_id')->references('id')->on('sacrament_types')->restrictOnDelete();
            $table->unique(['tenant_id', 'sacrament_type_id'], 'tenant_sacrament_settings_tenant_type_unique');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sacrament_settings');
    }
};
