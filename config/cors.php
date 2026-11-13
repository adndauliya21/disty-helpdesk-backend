<?php

/* [MODUL 6] CORS. Setara dengan header Access-Control-* di App.handle() pada Modul 3 dan 5.
   '*' hanya untuk pengembangan. Di produksi isi CORS_ORIGIN dengan alamat frontend (Modul 7). */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ORIGIN', '*'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type'],
    'exposed_headers' => ['Location'],
    'max_age' => 0,
    'supports_credentials' => false,
];
