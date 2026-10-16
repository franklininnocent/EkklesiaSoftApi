<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mass intentions are office-registered only; review-queue statuses are normalized to draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mass_intention_requests')
            ->whereIn('status', ['pending_review', 'awaiting_clarification'])
            ->update(['status' => 'draft', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Status normalization is not reversible without audit history.
    }
};
