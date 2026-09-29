<?php

return [
    'name' => 'Sacraments',
    // Phase 9 cutover: participants_v1 defaults on (set SACRAMENTS_PARTICIPANTS_V1=false to roll back).
    'participants_v1' => env('SACRAMENTS_PARTICIPANTS_V1', true),
    'certificates' => [
        'disk' => env('SACRAMENT_CERT_DISK', 'local'),
        'chromium_path' => env('CHROMIUM_PATH'),
        'verify_url_base' => env('SACRAMENT_CERT_VERIFY_URL'),
    ],
];
