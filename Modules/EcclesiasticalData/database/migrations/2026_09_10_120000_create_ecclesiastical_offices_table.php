<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecclesiastical_offices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('office_code', 64)->unique();
            $table->string('title', 150);
            $table->string('scope_type', 32);
            $table->string('adapter_key', 64);
            $table->boolean('allows_concurrent')->default(false);
            $table->boolean('requires_platform_approval')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecclesiastical_offices');
    }
};
