<?php

return [
    'name' => 'Marketing',

    'bff' => [
        'shared_secret' => env('MARKETING_BFF_SECRET'),
    ],

    'plan_catalog' => [
        'site_url' => env('MARKETING_SITE_URL'),
        'revalidate_secret' => env('MARKETING_REVALIDATE_SECRET'),
        'public_cache_seconds' => (int) env('MARKETING_PLAN_CATALOG_CACHE_SECONDS', 300),
        'preview_token_ttl_seconds' => (int) env('MARKETING_PLAN_PREVIEW_TOKEN_TTL', 900),
    ],
];
