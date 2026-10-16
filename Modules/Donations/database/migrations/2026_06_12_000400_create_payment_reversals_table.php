<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('payment_id');
            $table->uuid('approval_id')->nullable();
            $table->text('reason');
            $table->decimal('amount', 14, 2);
            $table->date('reversed_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
