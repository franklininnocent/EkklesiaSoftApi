<?php

return [
    'mail_owner' => env('NOTIFICATIONS_MAIL_OWNER', 'legacy'),

    'recipient_cap' => (int) env('NOTIFICATIONS_RECIPIENT_CAP', 100),

    'storm' => [
        'enabled' => env('NOTIFICATIONS_STORM_THROTTLE', true),
        'max_per_tenant_per_minute' => (int) env('NOTIFICATIONS_STORM_MAX_PER_MINUTE', 60),
    ],

    'sse' => [
        'enabled' => env('NOTIFICATIONS_SSE_ENABLED', false),
        'ttl_seconds' => (int) env('NOTIFICATIONS_SSE_TTL_SECONDS', 120),
        'heartbeat_seconds' => (int) env('NOTIFICATIONS_SSE_HEARTBEAT_SECONDS', 20),
        'max_connections_per_user' => (int) env('NOTIFICATIONS_SSE_MAX_CONNECTIONS', 3),
    ],

    'retention' => [
        'archived_days' => (int) env('NOTIFICATIONS_RETENTION_ARCHIVED_DAYS', 365),
        'delivery_days' => (int) env('NOTIFICATIONS_RETENTION_DELIVERY_DAYS', 90),
    ],

    'forbidden_data_keys' => [
        'password', 'otp', 'token', 'secret', 'temp_password', 'gateway_reference',
        'card_number', 'cvv', 'ssn',
    ],
];
