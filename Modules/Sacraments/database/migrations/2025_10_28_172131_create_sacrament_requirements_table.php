<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the sacrament_requirements table - prerequisites for each sacrament
     */
    public function up(): void
    {
        Schema::create('sacrament_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacrament_type_id')->constrained('sacrament_types')->onDelete('cascade')->comment('Sacrament this requirement is for');
            $table->foreignId('prerequisite_sacrament_id')->nullable()->constrained('sacrament_types')->onDelete('cascade')->comment('Required prior sacrament');
            $table->string('requirement_type', 50)->comment('Type: sacrament, age, preparation, permission');
            $table->string('title')->comment('Requirement title');
            $table->text('description')->comment('Detailed description of requirement');
            $table->boolean('is_mandatory')->default(true)->comment('Is this requirement mandatory');
            $table->integer('minimum_age_years')->nullable()->comment('Minimum age in years');
            $table->integer('preparation_hours')->nullable()->comment('Required preparation hours');
            $table->text('documentation_needed')->nullable()->comment('Required documents');
            $table->integer('display_order')->default(1)->comment('Display order');
            $table->boolean('active')->default(true)->comment('Is this requirement active');
            $table->timestamps();
            
            $table->index('sacrament_type_id');
            $table->index('prerequisite_sacrament_id');
            $table->index('requirement_type');
            $table->index('is_mandatory');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacrament_requirements');
    }
};
