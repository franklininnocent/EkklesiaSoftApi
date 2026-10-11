<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_plan_catalog_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('revision_number')->unique();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->json('payload');
            $table->json('source_refs');
            $table->string('source_fingerprint', 64);
            $table->string('payload_hash', 64);
            $table->json('validation_report')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_plan_catalog_publication', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('published_revision_id')->nullable()->constrained('marketing_plan_catalog_revisions')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revalidate_status', 24)->default('not_required');
            $table->text('revalidate_error')->nullable();
            $table->timestamps();
        });

        DB::table('marketing_plan_catalog_publication')->insert([
            'id' => 1,
            'published_revision_id' => null,
            'published_at' => null,
            'published_by' => null,
            'revalidate_status' => 'not_required',
            'revalidate_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_plan_catalog_publication');
        Schema::dropIfExists('marketing_plan_catalog_revisions');
    }
};
