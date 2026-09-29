<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bishops', function (Blueprint $table) {
            $table->string('normalized_name', 255)->nullable()->after('full_name');
            $table->timestamp('last_verified_at')->nullable()->after('data_sources');
            $table->foreignId('last_verified_by')->nullable()->after('last_verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable()->after('last_verified_by');
            $table->string('photo_path', 500)->nullable()->after('photo_url');
            $table->string('coat_of_arms_path', 500)->nullable()->after('coat_of_arms_url');
            $table->json('metadata')->nullable()->after('verification_notes');

            $table->index('normalized_name');
            $table->index('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('bishops', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropIndex(['last_verified_at']);
            $table->dropConstrainedForeignId('last_verified_by');
            $table->dropColumn([
                'normalized_name',
                'last_verified_at',
                'verification_notes',
                'photo_path',
                'coat_of_arms_path',
                'metadata',
            ]);
        });
    }
};
