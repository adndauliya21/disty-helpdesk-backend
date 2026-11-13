# config/ : konfigurasi dan perintah server

Berkas `config/*.php` sekarang ada di folder ini. `laravel/bootstrap/app.php` memakai `useConfigPath`.

## Berkas konfigurasi
| Lokasi | Fungsi |
|---|---|
| `disty.php` | Pengaturan khas Disty: JWT (rahasia dan masa berlaku), domain email staf, admin awal |
| `cors.php` | Izin akses dari front-end |
| `database.php` | Koneksi database |
| `app.php`, `auth.php`, `cache.php`, `filesystems.php`, `logging.php`, `mail.php`, `queue.php`, `services.php`, `session.php` | Pengaturan bawaan Laravel |
| `laravel/.env.example` | Contoh `.env` untuk pengembangan |
| `laravel/.env.production.example` | Contoh `.env` untuk server, dengan penjelasan tiap nilai |

## Nama variabel `.env` (nama saja, isi tidak boleh ditampilkan)
- Di `.env.example`: `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `LOG_CHANNEL`, `LOG_LEVEL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `JWT_SECRET`.
- Tambahan di `.env.production.example`: `LOG_DAILY_DAYS`, `JWT_EXPIRES_SECONDS`, `CORS_ORIGIN`. Baris `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD` ada tetapi dikomentari.
- Dibaca `disty.php` namun tidak tertulis di kedua contoh: `DOMAIN_STAF`. Bila tidak diisi, nilai bawaan di config yang dipakai.

## Perintah artisan buatan sendiri
Berkasnya ada di `auth/GantiKataSandi.php` dan `bersama/PeriksaProduksi.php`, didaftarkan lewat `withCommands` di `laravel/bootstrap/app.php`:
| Perintah | Fungsi (dari deskripsi di kodenya) |
|---|---|
| `php artisan disty:ganti-kata-sandi {akun}` | Ganti kata sandi sebuah akun, untuk pengelola server |
| `php artisan disty:periksa-produksi` | Periksa apakah konfigurasi dan database siap produksi |

Logika pemeriksaannya ada di `bersama/PemeriksaProduksi.php`. Penjelasan deploy: `docs/DEPLOY.md`, skrip: `deploy/`.

## Aturan
- Jangan menulis kata sandi di berkas config dan jangan menampilkan isi `.env`.
- Laravel dibekukan (lihat `CLAUDE.md` bagian 4).
