<?php

return [
    'name' => 'Subscriptions',

    /*
    |--------------------------------------------------------------------------
    | Entitlement engine rollout mode
    |--------------------------------------------------------------------------
    | legacy  - existing tenants.features / plan-key decisions only.
    | shadow  - legacy decisions are returned; plan-driven decisions are computed
    |           and mismatches are logged (verify with subscriptions:verify-entitlement-parity).
    | enforce - plan-driven decisions are authoritative.
    */
    'entitlement_engine' => env('SUBSCRIPTIONS_ENTITLEMENT_ENGINE', 'shadow'),

    'cache' => [
        'ttl_seconds' => (int) env('SUBSCRIPTIONS_ENTITLEMENT_CACHE_TTL', 900),
    ],

    /*
    | Usage threshold percentages used for warnings when no policy override exists.
    */
    'usage_thresholds' => [70, 85, 95, 100],

    'usage' => [
        'snapshot_scheduler_enabled' => (bool) env('SUBSCRIPTIONS_USAGE_SNAPSHOT_ENABLED', true),
        'snapshot_time' => env('SUBSCRIPTIONS_USAGE_SNAPSHOT_TIME', '02:10'),
        'alerts_scheduler_enabled' => (bool) env('SUBSCRIPTIONS_USAGE_ALERTS_ENABLED', true),
        'alerts_time' => env('SUBSCRIPTIONS_USAGE_ALERTS_TIME', '02:40'),
    ],

    'lifecycle' => [
        'scheduler_enabled' => (bool) env('SUBSCRIPTIONS_LIFECYCLE_SCHEDULER_ENABLED', true),
    ],

    'public_api' => [
        'cache_seconds' => (int) env('SUBSCRIPTIONS_PUBLIC_PLANS_CACHE', 300),
    ],
];
