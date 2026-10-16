<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Subscriptions\Support\RetiredBasicPremiumPlanPurger;

/**
 * Hard-delete any Basic/Premium plan rows left behind by an earlier soft-delete pass.
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
