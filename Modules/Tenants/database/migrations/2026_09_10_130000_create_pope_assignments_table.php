<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pope_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pope_name');
            $table->string('pope_title', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('appointment_reference')->nullable();
            $table->string('change_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_date']);
        });

        if (Schema::hasTable('pope_details')) {
            $current = DB::table('pope_details')->orderByDesc('updated_at')->first();
            if ($current && ! empty($current->pope_name)) {
                DB::table('pope_assignments')->insert([
                    'id' => (string) Str::uuid(),
                    'pope_name' => $current->pope_name,
                    'pope_title' => $current->pope_title,
                    'photo_path' => $current->pope_image_path,
                    'start_date' => $current->pope_effective_from ?? now()->toDateString(),
                    'end_date' => null,
                    'status' => 'active',
                    'appointment_reference' => 'migrated_from_pope_details',
                    'change_reason' => null,
                    'created_by' => $current->created_by,
                    'updated_by' => $current->updated_by,
                    'created_at' => $current->created_at ?? now(),
                    'updated_at' => $current->updated_at ?? now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pope_assignments');
    }
};
