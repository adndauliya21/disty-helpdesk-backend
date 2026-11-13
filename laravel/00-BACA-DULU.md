# laravel/ : berkas yang dibutuhkan Laravel agar hidup

Folder ini adalah akar aplikasi Laravel (`base_path()`). Kode aplikasinya ada di folder setara di sebelahnya, bukan di sini. Semua perintah `php artisan`, `composer`, dan PHPUnit dijalankan dari dalam folder ini.

| Berkas atau folder | Fungsi |
|---|---|
| `artisan` | Pintu masuk perintah `php artisan` |
| `bootstrap/` | `app.php` merakit aplikasi dan memasang jalur ke folder setara, `providers.php` daftar provider |
| `routes/` | `console.php` dan `web.php` (rute API ada di `../api/api.php`) |
| `public/` | Document root: front-end statis, `index.php`, `uploads/`. Punya pengantar sendiri |
| `storage/` | Log, cache, sesi, berkas buatan aplikasi |
| `vendor/` | Paket Composer. Jangan diubah dengan tangan |
| `composer.json`, `composer.lock` | Paket dan pemetaan namespace PSR-4 ke folder setara |
| `phpunit.xml` | Pengaturan tes: suite ke `../tests/Unit` dan `../tests/Feature`, sumber `../bersama` |
| `pint.json` | Pengaturan Pint (tidak ada di daftar awal pemilik, ikut ke sini karena berkas pendukung Laravel) |
| `.env`, `.env.example`, `.env.production.example` | Pengaturan lingkungan. Isi `.env` tidak boleh ditampilkan |
| `.gitignore`, `.gitattributes`, `.editorconfig` | Pengaturan Git dan editor |

## Pemetaan ke folder setara

| Awalan namespace | Folder (relatif terhadap `laravel/`) |
|---|---|
| `App\` (cadangan) dan `App\Providers\`, `App\Enums\` | `../bersama` |
| `App\Http\Controllers\` | `../api`, `../auth`, `../admin`, `../client`, `../teknisi` |
| `App\Http\Middleware\` | `../auth` |
| `App\Http\Resources\` | `../admin`, `../client`, `../bersama` |
| `App\Models\` | `../auth`, `../api`, `../admin`, `../client` |
| `App\Support\` | `../auth`, `../bersama`, `../admin/support` |
| `App\Exceptions\` | `../api`, `../auth` |
| `App\Console\Commands\` | `../auth`, `../bersama` |
| `Database\Seeders\` | `../database/seeders/` |
| `Tests\` | `../tests/` |

`bootstrap/app.php` memakai `useAppPath` ke `../bersama`, `useConfigPath` ke `../config`, dan `useDatabasePath` ke `../database` (semuanya lewat `dirname(__DIR__, 2)`).

## Nama variabel `.env` (nama saja)
`APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `LOG_CHANNEL`, `LOG_LEVEL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `JWT_SECRET`. Daftar lengkap ada di `.env.example` dan `.env.production.example`.

## Aturan
- Setelah mengubah `composer.json`, jalankan `composer dump-autoload` dari folder ini.
- Namespace PHP tidak boleh berubah. Hanya jalur berkas yang dipetakan.
- Jangan menampilkan atau menyalin isi `.env`.
- Syarat PHP: 8.4.1 atau lebih baru (menurut `README.md`).
