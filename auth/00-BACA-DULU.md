# auth/ : masuk, kata sandi, token

Kode sungguhan (sudah dipindah dari `app/`). Namespace PHP tidak berubah, hanya jalur berkasnya.

| Berkas | Fungsi |
|---|---|
| `AuthController.php` | Login, aktivasi akun, `/auth/me`, logout, ubah profil, ubah kata sandi |
| `Autentikasi.php` | Middleware `jwt`: memeriksa token di setiap rute yang wajib login |
| `PeranDiizinkan.php` | Middleware `peran:...`: membatasi rute per peran |
| `Jwt.php` | Membuat dan memeriksa token |
| `TokenException.php` | Galat token |
| `User.php` | Model akun (tabel `pengguna`) |
| `GantiKataSandi.php` | Perintah `php artisan disty:ganti-kata-sandi` |

## Aturan
- Nama pendek middleware (`jwt`, `peran`) didaftarkan di `laravel/bootstrap/app.php`.
- Perintah artisan didaftarkan satu per satu lewat `withCommands()` di `laravel/bootstrap/app.php`.
- Kata sandi minimal 8 dan maksimal 72 karakter, ada huruf dan angka (dijaga tes).
