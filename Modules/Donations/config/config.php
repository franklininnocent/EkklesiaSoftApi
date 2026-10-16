<?php

return [
    'name' => 'Donations',
    'reports' => [
        'export_ttl_days' => (int) env('DONATIONS_REPORT_EXPORT_TTL_DAYS', 7),
    ],
    'webhooks' => [
        'secret' => env('DONATIONS_WEBHOOK_SECRET'),
        'providers' => [
            'generic' => [
                'secret' => env('DONATIONS_WEBHOOK_SECRET'),
            ],
            'stripe' => [
                'secret' => env('DONATIONS_STRIPE_WEBHOOK_SECRET'),
            ],
            'razorpay' => [
                'secret' => env('DONATIONS_RAZORPAY_WEBHOOK_SECRET'),
            ],
        ],
    ],
];
