<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/* [MODUL 7] DAFTAR PERIKSA PRODUKSI. Satu tempat untuk semua syarat "siap tayang" yang dibahas di Modul 7:
   konfigurasi environment, rahasia JWT, CORS, koneksi database, migrasi, dan akun yang masih memakai kata sandi awal.
   Dipakai oleh `php artisan disty:periksa-produksi` (dijalankan di akhir deploy) dan, khusus rahasia JWT,
   oleh AppServiceProvider sebagai pengaman. */
final class PemeriksaProduksi
{
    // Harus sama dengan nilai bawaan di config/disty.php dan kata sandi awal di database/data/demo.json (khusus pengembangan).
    public const RAHASIA_BAWAAN = 'rahasia-pengembangan-jangan-dipakai-di-produksi';

    public const KATA_SANDI_AWAL = 'rahasia123';

    public const RAHASIA_MINIMAL = 32;

    public static function rahasiaJwtLemah(): bool
    {
        $rahasia = (string) config('disty.jwt.rahasia');

        return $rahasia === self::RAHASIA_BAWAAN
            || str_starts_with($rahasia, 'ganti-dengan')
            || strlen($rahasia) < self::RAHASIA_MINIMAL;
    }

    /** @return list<array{ok: bool, judul: string, saran: string}> */
    public static function periksa(): array
    {
        $hasil = [];
        $catat = function (bool $ok, string $judul, string $saran = '') use (&$hasil): void {
            $hasil[] = ['ok' => $ok, 'judul' => $judul, 'saran' => $saran];
        };

        $catat(
            app()->environment('production'),
            'APP_ENV = production',
            'Isi APP_ENV=production di .env',
        );
        $catat(
            config('app.debug') === false,
            'APP_DEBUG = false',
            'Isi APP_DEBUG=false. Mode debug bisa membocorkan detail kesalahan.',
        );
        $catat(
            filled(config('app.key')),
            'APP_KEY terisi',
            'Jalankan: php artisan key:generate --force',
        );
        $catat(
            ! self::rahasiaJwtLemah(),
            'JWT_SECRET kuat (bukan nilai bawaan, minimal '.self::RAHASIA_MINIMAL.' karakter)',
            'Buat dengan: openssl rand -base64 48, lalu isi JWT_SECRET di .env',
        );

        $asal = (array) config('cors.allowed_origins');
        $catat(
            ! in_array('*', $asal, true) && $asal !== [],
            'CORS_ORIGIN hanya alamat frontend (bukan *)',
            'Isi CORS_ORIGIN=https://alamat-frontend-kamu (tanpa garis miring di akhir)',
        );
        $catat(
            str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL memakai https://',
            'Pasang sertifikat (certbot) lalu isi APP_URL=https://...',
        );
        $catat(
            is_writable(storage_path('logs')) && is_writable(base_path('bootstrap/cache')),
            'storage/ dan bootstrap/cache dapat ditulis',
            'Jalankan: chgrp -R www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache',
        );

        try {
            DB::connection()->getPdo();
            $terhubung = true;
        } catch (Throwable $e) {
            $terhubung = false;
        }
        $catat($terhubung, 'Koneksi database berhasil', 'Periksa DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD di .env');

        if ($terhubung) {
            $catat(...self::migrasi());
            $catat(...self::kataSandiAwal());
        }

        return $hasil;
    }

    /** @return array{bool, string, string} */
    private static function migrasi(): array
    {
        try {
            $migrator = app('migrator');
            $repo = $migrator->getRepository();
            $berkas = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $sudah = $repo->repositoryExists() ? $repo->getRan() : [];
            $tertunda = array_diff($berkas, $sudah);
        } catch (Throwable $e) {
            return [false, 'Migrasi database sudah terbaru', 'Jalankan: php artisan migrate --force'];
        }

        return [
            $tertunda === [],
            'Migrasi database sudah terbaru',
            'Ada '.count($tertunda).' migrasi belum dijalankan. Jalankan: php artisan migrate --force',
        ];
    }

    /** @return array{bool, string, string} */
    private static function kataSandiAwal(): array
    {
        $judul = 'Tidak ada akun yang memakai kata sandi awal ('.self::KATA_SANDI_AWAL.')';
        $saran = 'Ganti dengan: php artisan disty:ganti-kata-sandi<email>';

        try {
            $bermasalah =User::query()->pluck('password', 'email')
                ->filter(fn ($hash) => Hash::check(self::KATA_SANDI_AWAL, $hash))
                ->keys()->all();
        } catch (Throwable $e) {
            return [false, $judul,'Tabel pengguna belum bisa dibaca. Jalankan: php artisan migrate --force'];
        }

        return [
            $bermasalah === [],
            $judul,
            $saran.'. Akun: '.implode(', ', $bermasalah),
        ];
    }
}
