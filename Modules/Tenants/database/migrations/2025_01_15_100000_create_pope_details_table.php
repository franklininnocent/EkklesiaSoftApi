<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Create Global Pope Details Table
 * 
 * Stores global Pope information (not tenant-specific).
 * There is only one active Pope at any time globally.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pope_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            // Pope Information Fields
            $table->string('pope_name', 255)->nullable()
                ->comment('Full name of the current Pope');
            $table->string('pope_image_path', 255)->nullable()
                ->comment('Path/URL to Pope portrait image');
            $table->string('pope_title', 100)->nullable()
                ->comment('Optional: Pope title (e.g., "Pope Francis")');
            $table->date('pope_effective_from')->nullable()
                ->comment('Optional: Date when this pope became effective');
            
            // Audit fields
            $table->unsignedBigInteger('created_by')->nullable()
                ->comment('User who created this record');
            $table->unsignedBigInteger('updated_by')->nullable()
                ->comment('User who last updated this record');
            
            // Timestamps
            $table->timestamps();
            
            // Only one active pope record should exist
            // This will be enforced at application level
        });

        // Insert a default empty record
        DB::table('pope_details')->insert([
            'pope_name' => null,
            'pope_image_path' => null,
            'pope_title' => null,
            'pope_effective_from' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pope_details');
    }
};

