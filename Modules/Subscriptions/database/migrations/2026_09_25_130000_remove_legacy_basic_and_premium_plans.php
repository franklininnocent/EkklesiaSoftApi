<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Subscriptions\Support\RetiredBasicPremiumPlanPurger;

/**
 * Retire Basic/Premium: reassign churches, then permanently delete every related catalog row.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RetiredBasicPremiumPlanPurger::class)->purge();
    }

    public function down(): void
    {
        // Destructive purge; Basic/Premium are not restored.
    }
};
