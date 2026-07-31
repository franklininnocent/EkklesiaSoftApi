<?php

return [
    'name' => 'Donations',
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
