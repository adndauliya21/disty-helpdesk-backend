# bootstrap/ : titik rakit aplikasi Laravel

| Berkas | Fungsi |
|---|---|
| `app.php` | Mendaftarkan rute API (prefix `api/v1`), alias middleware `jwt` dan `peran`, dan penanganan galat terpusat |
| `providers.php` | Daftar service provider. Hanya `AppServiceProvider` |
| `cache/` | Cache buatan Laravel. Isinya dibuat ulang otomatis |

## Aturan
- Jangan ubah `app.php`. Itu bagian kontrak API (format galat dan kode status).
