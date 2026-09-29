<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            $table->string('leadership_state', 30)->default('occupied')->after('active');
            $table->timestamp('last_verified_at')->nullable()->after('leadership_state');
            $table->foreignId('last_verified_by')->nullable()->after('last_verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable()->after('last_verified_by');
            $table->json('metadata')->nullable()->after('verification_notes');

            $table->index('leadership_state');
            $table->index('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('archdioceses', function (Blueprint $table) {
            $table->dropIndex(['leadership_state']);
            $table->dropIndex(['last_verified_at']);
            $table->dropConstrainedForeignId('last_verified_by');
            $table->dropColumn([
                'leadership_state',
                'last_verified_at',
                'verification_notes',
                'metadata',
            ]);
        });
    }
};
