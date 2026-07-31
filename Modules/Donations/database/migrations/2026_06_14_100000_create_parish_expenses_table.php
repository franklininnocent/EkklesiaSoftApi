<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parish_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('category', 120);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('INR');
            $table->date('expense_date');
            $table->string('payee', 180)->nullable();
            $table->string('method', 60)->default('cash');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('recorded');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'expense_date']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parish_expenses');
    }
};
