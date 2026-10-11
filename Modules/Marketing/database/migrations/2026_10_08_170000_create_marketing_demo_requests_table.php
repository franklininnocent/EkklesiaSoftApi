<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('church_parish', 200);
            $table->string('diocese_organization', 200)->nullable();
            $table->string('country', 120);
            $table->string('email', 255);
            $table->string('phone', 40)->nullable();
            $table->string('role', 120);
            $table->text('message');
            $table->string('correlation_id', 64)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_demo_requests');
    }
};
