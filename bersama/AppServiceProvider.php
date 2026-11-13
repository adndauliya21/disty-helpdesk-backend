<?php

namespace App\Providers;

use App\Support\PemeriksaProduksi;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /* [MODUL 7] PENGAMAN PRODUKSI. Kalau server production lupa mengisi JWT_SECRET (masih nilai bawaan atau terlalu pendek),
           siapa pun yang membaca kode ini bisa membuat token Admin palsu. Jadi permintaan web ditolak (500, dicatat di log)
           sampai rahasianya diisi. Perintah artisan sengaja tidak diblokir supaya `composer install` dan `migrate` tetap jalan. */
        if ($this->app->isProduction() && ! $this->app->runningInConsole() && PemeriksaProduksi::rahasiaJwtLemah()) {
            throw new RuntimeException(
                'JWT_SECRET belum aman untuk produksi. Isi dengan teks acak minimal '.PemeriksaProduksi::RAHASIA_MINIMAL
                .' karakter (openssl rand -base64 48), lalu jalankan: php artisan config:cache'
            );
        }
    }
}
