<?php

return [
    'name' => 'SupportAccess',

    /*
    |--------------------------------------------------------------------------
    | Support session notification recipient
    |--------------------------------------------------------------------------
    |
    | Immediate and digest notifications are sent to this address (log+mail).
    | Leave empty to log only (no Mail::send).
    |
    */
    'notify_to' => env('SUPPORT_NOTIFY_TO'),

    'notify_from_name' => env('SUPPORT_NOTIFY_FROM_NAME', 'EkklesiaSoft Support Access'),

    /*
    |--------------------------------------------------------------------------
    | Ticket reference format (required_format mode)
    |--------------------------------------------------------------------------
    */
    'ticket_ref_pattern' => env(
        'SUPPORT_TICKET_REF_PATTERN',
        '/^#?[A-Za-z0-9][-A-Za-z0-9_\/]{2,64}$/'
    ),
];
