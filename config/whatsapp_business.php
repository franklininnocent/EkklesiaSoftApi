<?php

return [
    'enabled' => (bool) env('WHATSAPP_BUSINESS_ENABLED', false),
    'api_version' => env('WHATSAPP_BUSINESS_API_VERSION', 'v21.0'),
    'phone_number_id' => env('WHATSAPP_BUSINESS_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_BUSINESS_ACCESS_TOKEN'),
    'timeout' => (int) env('WHATSAPP_BUSINESS_TIMEOUT', 15),
];
