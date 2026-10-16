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
        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->integer('level')->comment('1=SuperAdmin, 2=EkklesiaAdmin, 3=EkklesiaManager, 4=EkklesiaUser');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->softDeletes(); // deleted_at column (nullable by default)
            $table->timestamps();
            
            // Index for performance
            $table->index(['active', 'deleted_at']);
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};

