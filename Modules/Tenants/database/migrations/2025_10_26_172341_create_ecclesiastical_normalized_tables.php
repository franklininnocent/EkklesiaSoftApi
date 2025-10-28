<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Normalized Ecclesiastical Tables
 * 
 * This migration creates a fully normalized database structure for ecclesiastical
 * and church profile information following enterprise-level best practices.
 * 
 * Architecture:
 * - Proper normalization with lookup tables
 * - Foreign key constraints with cascade rules
 * - Indexed fields for query performance
 * - Tenant isolation at database level
 * - Follows cursor.rules standards for scalability
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ================================================================
        // 1. DENOMINATIONS TABLE - Lookup table for church denominations
        // ================================================================
        Schema::create('denominations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique()->comment('Denomination name (e.g., Catholic, Protestant, Orthodox)');
            $table->string('code', 20)->unique()->comment('Short code for the denomination');
            $table->text('description')->nullable()->comment('Description of the denomination');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->integer('display_order')->default(0)->comment('Sort order for display');
            $table->timestamps();
            
            // Indexes
            $table->index(['active', 'display_order']);
        });

        // ================================================================
        // 2. ARCHDIOCESES TABLE - Ecclesiastical administrative regions
        // ================================================================
        Schema::create('archdioceses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255)->comment('Name of the Archdiocese or Diocese');
            $table->string('code', 50)->unique()->nullable()->comment('Unique code for the archdiocese');
            $table->string('country', 100)->comment('Country where the archdiocese is located');
            $table->string('region', 100)->nullable()->comment('Region or province');
            $table->string('headquarters_city', 100)->nullable()->comment('City where headquarters is located');
            $table->unsignedBigInteger('denomination_id')->nullable()
                ->comment('Reference to denomination');
            $table->unsignedBigInteger('parent_archdiocese_id')->nullable()
                ->comment('Parent archdiocese for hierarchical structure');
            $table->text('description')->nullable()->comment('Description of the archdiocese');
            $table->string('website', 255)->nullable()->comment('Official website');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('denomination_id')
                ->references('id')->on('denominations')
                ->onDelete('set null')
                ->onUpdate('cascade');
            
            $table->foreign('parent_archdiocese_id')
                ->references('id')->on('archdioceses')
                ->onDelete('set null')
                ->onUpdate('cascade');
            
            // Indexes
            $table->index('country');
            $table->index('denomination_id');
            $table->index(['active', 'country']);
        });

        // ================================================================
        // 3. BISHOPS TABLE - Moved to separate migration for better normalization
        // ================================================================
        // Bishops table now created in: 2025_10_28_022900_create_bishops_table.php
        // with full normalization including:
        // - Foreign keys to ecclesiastical_titles, religious_orders, countries, states
        // - Comprehensive biographical data
        // - Episcopal lineage and history

        // ================================================================
        // 4. CHURCH_LEADERSHIP TABLE - Pastors and associate pastors
        // ================================================================
        Schema::create('church_leadership', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->comment('Reference to the church/tenant');
            $table->string('full_name', 255)->comment('Full name of the leader');
            $table->string('role', 100)->comment('Leadership role (e.g., Pastor, Associate Pastor, Youth Pastor)');
            $table->string('title', 100)->nullable()->comment('Title (e.g., Reverend, Father, Pastor)');
            $table->string('email', 255)->nullable()->comment('Contact email');
            $table->string('phone', 20)->nullable()->comment('Contact phone');
            $table->date('appointed_date')->nullable()->comment('Date appointed to position');
            $table->date('start_date')->nullable()->comment('Start date at this church');
            $table->date('end_date')->nullable()->comment('End date (if no longer serving)');
            $table->text('biography')->nullable()->comment('Brief biography');
            $table->string('photo_url', 255)->nullable()->comment('Path to leader photo');
            $table->integer('is_primary')->default(0)->comment('1=Primary Pastor, 0=Associate');
            $table->integer('display_order')->default(0)->comment('Sort order for display');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            
            // Indexes
            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_primary', 'active']);
            $table->index('role');
        });

        // ================================================================
        // 5. CHURCH_PROFILE TABLE - Extended church information
        // ================================================================
        Schema::create('church_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->unique()->comment('One-to-one with tenant');
            $table->unsignedBigInteger('denomination_id')->nullable()->comment('Reference to denomination');
            $table->unsignedBigInteger('archdiocese_id')->nullable()->comment('Reference to archdiocese');
            $table->unsignedBigInteger('bishop_id')->nullable()->comment('Reference to presiding bishop');
            
            // General Information
            $table->integer('founded_year')->nullable()->comment('Year the church was established');
            $table->string('country', 100)->nullable()->comment('Country of operation');
            $table->string('phone', 20)->nullable()->comment('Primary contact phone');
            $table->string('email', 255)->nullable()->comment('Primary contact email');
            $table->string('website', 255)->nullable()->comment('Church website URL');
            
            // Church Identity
            $table->text('about')->nullable()->comment('About the church');
            $table->text('vision')->nullable()->comment('Vision statement');
            $table->text('mission')->nullable()->comment('Mission statement');
            $table->text('core_values')->nullable()->comment('Core values');
            $table->text('service_times')->nullable()->comment('Service schedule');
            
            // Timestamps
            $table->timestamps();
            
            // Foreign keys with proper cascade
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            
            $table->foreign('denomination_id')
                ->references('id')->on('denominations')
                ->onDelete('set null')
                ->onUpdate('cascade');
            
            $table->foreign('archdiocese_id')
                ->references('id')->on('archdioceses')
                ->onDelete('set null')
                ->onUpdate('cascade');
            
            // Foreign key to bishops table will be added in: 
            // 2025_10_28_022901_add_bishop_foreign_key_to_church_profiles.php
            // after the bishops table is created
            
            // Indexes
            $table->index('denomination_id');
            $table->index('archdiocese_id');
            $table->index('bishop_id');
            $table->index('country');
        });

        // ================================================================
        // 6. CHURCH_STATISTICS TABLE - Time-series data for metrics
        // ================================================================
        Schema::create('church_statistics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->comment('Reference to the church/tenant');
            $table->integer('year')->comment('Statistical year');
            $table->integer('month')->nullable()->comment('Statistical month (1-12, null for annual)');
            $table->integer('membership_count')->default(0)->comment('Total registered members');
            $table->integer('weekly_attendance')->default(0)->comment('Average weekly attendance');
            $table->integer('baptisms')->default(0)->nullable()->comment('Number of baptisms');
            $table->integer('confirmations')->default(0)->nullable()->comment('Number of confirmations');
            $table->integer('marriages')->default(0)->nullable()->comment('Number of marriages');
            $table->integer('funerals')->default(0)->nullable()->comment('Number of funerals');
            $table->decimal('tithes_offerings', 15, 2)->default(0)->nullable()->comment('Total tithes and offerings');
            $table->text('notes')->nullable()->comment('Additional notes');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            
            // Unique constraint: one record per tenant/year/month combination
            $table->unique(['tenant_id', 'year', 'month']);
            
            // Indexes for time-series queries
            $table->index('tenant_id');
            $table->index(['tenant_id', 'year', 'month']);
            $table->index('year');
        });

        // ================================================================
        // 7. CHURCH_SOCIAL_MEDIA TABLE - Normalized social media links
        // ================================================================
        Schema::create('church_social_media', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->comment('Reference to the church/tenant');
            $table->string('platform', 50)->comment('Social media platform (facebook, twitter, instagram, youtube, etc.)');
            $table->string('url', 255)->comment('Profile/Page URL');
            $table->string('username', 100)->nullable()->comment('Username/Handle');
            $table->integer('follower_count')->default(0)->nullable()->comment('Number of followers');
            $table->integer('is_primary')->default(0)->comment('1=Primary account, 0=Secondary');
            $table->integer('display_order')->default(0)->comment('Sort order for display');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade')
                ->onUpdate('cascade');
            
            // Unique constraint: one primary account per platform per tenant
            $table->unique(['tenant_id', 'platform', 'is_primary']);
            
            // Indexes
            $table->index('tenant_id');
            $table->index(['tenant_id', 'active']);
            $table->index('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop tables in reverse order to respect foreign key constraints
        Schema::dropIfExists('church_social_media');
        Schema::dropIfExists('church_statistics');
        Schema::dropIfExists('church_profiles');
        Schema::dropIfExists('church_leadership');
        // bishops table dropped in: 2025_10_28_022900_create_bishops_table.php
        Schema::dropIfExists('archdioceses');
        Schema::dropIfExists('denominations');
    }
};
