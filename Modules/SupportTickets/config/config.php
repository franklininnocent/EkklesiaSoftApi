<?php

return [
    'rate_limits' => [
        'create_per_hour' => (int) env('SUPPORT_TICKETS_CREATE_PER_HOUR', 10),
        'comment_per_hour' => (int) env('SUPPORT_TICKETS_COMMENT_PER_HOUR', 30),
        'upload_per_hour' => (int) env('SUPPORT_TICKETS_UPLOAD_PER_HOUR', 20),
    ],
    'attachments' => [
        'max_per_ticket' => 10,
        'max_size_kb' => 10240,
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/zip',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip', 'log'],
    ],
    'reopen_window_days' => (int) env('SUPPORT_TICKETS_REOPEN_DAYS', 15),
    'sla_warning_minutes_before' => 60,
];
