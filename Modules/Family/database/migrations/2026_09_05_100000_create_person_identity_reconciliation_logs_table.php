<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_identity_reconciliation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('person_id');
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source_selected', 64)->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id']);
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_identity_reconciliation_logs');
    }
};
