<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the bishops table to store information about Catholic bishops,
     * archbishops, and other ecclesiastical leaders with proper hierarchy.
     */
    public function up(): void
    {
        Schema::create('bishops', function (Blueprint $table) {
            $table->id();
            
            // Basic Information
            $table->unsignedBigInteger('ecclesiastical_title_id')->nullable()->comment('Foreign key to ecclesiastical_titles table');
            $table->foreign('ecclesiastical_title_id')->references('id')->on('ecclesiastical_titles')->onDelete('set null');
            $table->string('full_name', 255)->comment('Full name of the bishop');
            $table->string('given_name', 100)->nullable()->comment('Given/First name');
            $table->string('family_name', 100)->nullable()->comment('Family/Last name');
            $table->string('religious_name', 100)->nullable()->comment('Name taken in religious life (if applicable)');
            
            // Diocese Relationship (NORMALIZED)
            $table->unsignedBigInteger('archdiocese_id')->comment('Foreign key to archdioceses table');
            $table->foreign('archdiocese_id')->references('id')->on('archdioceses')->onDelete('cascade');
            
            // Appointment Details
            $table->date('appointed_date')->nullable()->comment('Date of appointment to current position');
            $table->date('ordained_priest_date')->nullable()->comment('Date of priestly ordination');
            $table->date('ordained_bishop_date')->nullable()->comment('Date of episcopal consecration');
            $table->date('retired_date')->nullable()->comment('Date of retirement (if retired)');
            
            // Biographical Information (NORMALIZED with Geographic Data)
            $table->date('date_of_birth')->nullable()->comment('Date of birth');
            $table->string('birth_place_city', 255)->nullable()->comment('City of birth');
            $table->unsignedBigInteger('birth_country_id')->nullable()->comment('Foreign key to countries table');
            $table->foreign('birth_country_id')->references('id')->on('countries')->onDelete('set null');
            $table->unsignedBigInteger('birth_state_id')->nullable()->comment('Foreign key to states table');
            $table->foreign('birth_state_id')->references('id')->on('states')->onDelete('set null');
            
            // Nationality (NORMALIZED)
            $table->unsignedBigInteger('nationality_country_id')->nullable()->comment('Foreign key to countries table for nationality');
            $table->foreign('nationality_country_id')->references('id')->on('countries')->onDelete('set null');
            
            // Religious Order (NORMALIZED)
            $table->unsignedBigInteger('religious_order_id')->nullable()->comment('Foreign key to religious_orders table');
            $table->foreign('religious_order_id')->references('id')->on('religious_orders')->onDelete('set null');
            
            // Education & Formation
            $table->text('education')->nullable()->comment('Educational background and degrees');
            $table->text('seminary')->nullable()->comment('Seminary or formation house attended');
            
            // Ecclesiastical Status
            $table->enum('status', ['active', 'retired', 'emeritus', 'transferred', 'deceased'])->default('active')->comment('Current status');
            $table->boolean('is_current')->default(true)->comment('Is this the current bishop of the diocese?');
            $table->integer('precedence_order')->default(1)->comment('Order of precedence (1=current ordinary, 2=coadjutor, 3+=auxiliaries)');
            
            // Additional Roles
            $table->string('additional_titles', 500)->nullable()->comment('Additional ecclesiastical titles or roles');
            $table->text('previous_positions')->nullable()->comment('Previous ecclesiastical appointments');
            
            // Contact & Public Information
            $table->string('email', 255)->nullable()->comment('Official email address');
            $table->string('phone', 50)->nullable()->comment('Official phone number');
            $table->text('photo_url')->nullable()->comment('URL to official photograph');
            $table->text('biography')->nullable()->comment('Official biography');
            
            // Coat of Arms
            $table->text('coat_of_arms_url')->nullable()->comment('URL to episcopal coat of arms');
            $table->string('motto', 255)->nullable()->comment('Episcopal motto');
            $table->text('motto_translation')->nullable()->comment('Translation/explanation of motto');
            
            // References & Sources
            $table->string('catholic_hierarchy_url', 500)->nullable()->comment('Link to Catholic-Hierarchy.org profile');
            $table->string('official_website', 500)->nullable()->comment('Official diocesan or personal website');
            $table->text('data_sources')->nullable()->comment('JSON array of data sources for verification');
            
            // Audit Fields
            $table->integer('active')->default(1)->comment('1=Active record, 0=Inactive');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index('ecclesiastical_title_id');
            $table->index('archdiocese_id');
            $table->index('birth_country_id');
            $table->index('birth_state_id');
            $table->index('nationality_country_id');
            $table->index('religious_order_id');
            $table->index('status');
            $table->index('is_current');
            $table->index(['archdiocese_id', 'is_current']);
            $table->index(['archdiocese_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bishops');
    }
};
