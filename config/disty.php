<?php

/* [MODUL 6] Pengaturan khusus aplikasi. Setara dengan src/config.js di backend Node (jwtRahasia, jwtBerlakuDetik). */
return [
    'jwt' => [
        // Nilai bawaan HANYA untuk pengembangan. Di produksi wajib diganti lewat JWT_SECRET (teks acak panjang).
        'rahasia' => env('JWT_SECRET', 'rahasia-pengembangan-jangan-dipakai-di-produksi'),
        'berlaku_detik' => (int) env('JWT_EXPIRES_SECONDS', 8 * 3600),   // 8 jam
    ],

    /* [MODUL 7] Akun Admin pertama untuk ProductionSeeder (php artisan db:seed --class=ProductionSeeder).
       Dibaca lewat config supaya tetap jalan setelah `php artisan config:cache` di server (env() di luar config/ akan null).
       ADMIN_PASSWORD boleh dikosongkan: seeder akan menanyakannya lewat prompt tersembunyi. Hapus dari .env setelah dipakai. */
    // Domain email untuk akun staf yang dibuat otomatis (teknisi baru dari POST /technicians).
    'domain_staf' => env('DOMAIN_STAF', 'perusahaan.id'),

    'admin_awal' => [
        'email' => env('ADMIN_EMAIL', 'admin@perusahaan.id'),   // [MODUL 8] login lewat email (kontrak)
        'name' => env('ADMIN_NAME', 'Admin Disty'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
