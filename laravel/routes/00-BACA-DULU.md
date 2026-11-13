# routes/ : daftar alamat aplikasi

`api.php` sudah dipindah ke `api/api.php` (folder setara `laravel/`). Bagian `withRouting(api: ...)` di `bootstrap/app.php` menunjuk ke sana lewat `__DIR__.'/../../api/api.php'`.

| Berkas | Fungsi |
|---|---|
| `web.php` | Hanya berisi rute cadangan. **Saat ini tidak dimuat** karena `bootstrap/app.php` tidak mendaftarkan `web:` (lihat CLAUDE.md bagian 6) |
| `console.php` | Perintah artisan bawaan Laravel (`inspire`) |

Untuk melihat semua rute: `php artisan route:list`
