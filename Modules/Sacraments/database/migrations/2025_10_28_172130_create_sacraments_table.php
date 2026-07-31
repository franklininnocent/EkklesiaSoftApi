<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the sacraments table - core table for sacrament administration records
     */
    public function up(): void
    {
        Schema::create('sacraments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade')->comment('Tenant (Church) ID');
            $table->foreignId('sacrament_type_id')->constrained('sacrament_types')->onDelete('restrict')->comment('Type of sacrament');
            $table->string('recipient_name')->comment('Full name of recipient');
            $table->date('date_administered')->comment('Date sacrament was administered');
            $table->string('place_administered')->nullable()->comment('Church/location where administered');
            $table->string('minister_name')->nullable()->comment('Name of minister (priest/bishop)');
            $table->string('minister_title', 50)->nullable()->comment('Title of minister (Fr., Msgr., Bishop, etc.)');
            $table->string('certificate_number')->nullable()->unique()->comment('Certificate/registry number');
            $table->string('book_number')->nullable()->comment('Parish registry book number');
            $table->string('page_number')->nullable()->comment('Page number in registry');
            $table->date('recipient_birth_date')->nullable()->comment('Birth date of recipient');
            $table->string('recipient_birth_place')->nullable()->comment('Birth place of recipient');
            $table->string('father_name')->nullable()->comment('Father\'s full name');
            $table->string('mother_name')->nullable()->comment('Mother\'s full name (including maiden name)');
            $table->string('godparent1_name')->nullable()->comment('First godparent/sponsor name');
            $table->string('godparent2_name')->nullable()->comment('Second godparent/sponsor name');
            $table->text('witnesses')->nullable()->comment('Witnesses present (JSON or comma-separated)');
            $table->text('notes')->nullable()->comment('Additional notes or special circumstances');
            $table->string('document_path')->nullable()->comment('Path to scanned certificate/document');
            $table->string('status', 30)->default('active')->comment('Status: active, cancelled, conditional');
            $table->date('conditional_date')->nullable()->comment('Date if conditional sacrament');
            $table->text('conditional_reason')->nullable()->comment('Reason for conditional administration');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('tenant_id');
            $table->index('sacrament_type_id');
            $table->index('date_administered');
            $table->index('recipient_name');
            $table->index('certificate_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacraments');
    }
};
