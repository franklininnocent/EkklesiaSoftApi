<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcc_family_memberships', function (Blueprint $table) {
            $table->string('transfer_reason', 40)->nullable()->after('exit_reason');
            $table->uuid('transition_id')->nullable()->after('transfer_reason');
            $table->unsignedBigInteger('transferred_by_user_id')->nullable()->after('transition_id');
            $table->text('historical_note')->nullable()->after('transferred_by_user_id');

            $table->foreign('transferred_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'transition_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bcc_family_memberships', function (Blueprint $table) {
            $table->dropForeign(['transferred_by_user_id']);
            $table->dropIndex(['tenant_id', 'transition_id']);
            $table->dropColumn([
                'transfer_reason',
                'transition_id',
                'transferred_by_user_id',
                'historical_note',
            ]);
        });
    }
};
