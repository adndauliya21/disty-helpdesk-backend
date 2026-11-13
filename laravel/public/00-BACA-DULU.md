# public/ : document root Laravel (khusus API dan unggahan)

| Berkas atau folder | Fungsi |
|---|---|
| `index.php`, `.htaccess` | Pintu masuk Laravel (untuk `/api/v1`) |
| `uploads/` | Foto unggahan pengguna. Jalurnya dipakai `UploadController` dan skrip deploy |

## Catatan
- Front-end sudah dipindah ke `../../../disty-helpdesk-frontend`. Untuk hosting satu server, salin isinya ke folder ini.
- Lewat `php artisan serve`, alamat `/` memberi 404 JSON kecuali front-end sudah disalin ke sini.
