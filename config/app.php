<?php

return [
    'name' => env('APP_NAME', 'Disty HelpDesk'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:3000'),

    // Tanggal dan jam disimpan serta dikirim dalam UTC (kontrak API: 2026-09-30T02:10:00.000Z). Jangan diubah.
    'timezone' => 'UTC',

    'locale' => 'id',
    'fallback_locale' => 'en',
    'faker_locale' => 'id_ID',

    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
