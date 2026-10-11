<?php

namespace Modules\Marketing\Support;

use Illuminate\Support\Facades\Cache;

final class MarketingPlanCatalogCache
{
    public const KEY_PUBLISHED = 'marketing:published_plan_catalog';

    public static function forgetPublished(): void
    {
        Cache::forget(self::KEY_PUBLISHED);
    }
}
