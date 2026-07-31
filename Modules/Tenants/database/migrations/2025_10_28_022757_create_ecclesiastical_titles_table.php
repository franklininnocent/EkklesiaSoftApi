<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the ecclesiastical_titles table - a normalized lookup table
     * for ecclesiastical titles used by bishops and clergy.
     */
    public function up(): void
    {
        Schema::create('ecclesiastical_titles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->unique()->comment('Ecclesiastical title (e.g., Archbishop, Bishop, Cardinal)');
            $table->string('abbreviation', 20)->nullable()->comment('Standard abbreviation (e.g., Abp., Bp., Card.)');
            $table->text('description')->nullable()->comment('Description of the title and its significance');
            $table->integer('hierarchy_level')->comment('Hierarchical level: 1=Pope, 2=Cardinal, 3=Archbishop, 4=Bishop, etc.');
            $table->integer('display_order')->default(1)->comment('Display order in dropdowns');
            $table->integer('active')->default(1)->comment('1=Active, 0=Inactive');
            $table->timestamps();
            
            $table->index('hierarchy_level');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecclesiastical_titles');
    }
};
