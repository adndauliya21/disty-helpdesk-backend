# api/ : sistem API

Semua alamat API diawali `/api/v1` (`apiPrefix` di `laravel/bootstrap/app.php`, tidak boleh diubah).

| Berkas | Fungsi |
|---|---|
| `api.php` | Daftar semua rute API (dulu `routes/api.php`) |
| `Controller.php` | Kelas dasar semua controller |
| `InfoController.php` | `/ping` |
| `ReferensiController.php` | Basis pengetahuan, solusi, instansi |
| `UploadController.php` | Unggah foto ke `laravel/public/uploads` |
| `ApiException.php` | Galat yang diharapkan, diubah ke format `{ "error": { "code", "message", "details" } }` |
| `ArtikelBantuan.php`, `Instansi.php` | Model data referensi |

## Jalur satu permintaan
`api.php` lalu middleware (`auth/`) lalu controller lalu model lalu resource (bentuk JSON).

## Aturan
- Jangan ubah `api.php` tanpa memperbarui `node-backend/src/routes.js`, karena kontraknya sama.
- Daftar endpoint lengkap dan akun uji ada di `README.md`.
