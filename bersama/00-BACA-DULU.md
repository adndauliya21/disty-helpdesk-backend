# bersama/ : dipakai banyak urusan

| Berkas | Fungsi |
|---|---|
| `StatusAduan.php`, `StatusKonsultasi.php`, `KategoriAduan.php`, `Prioritas.php` | Enum nilai tetap |
| `Waktu.php` | Format waktu |
| `UrlFoto.php` | Memeriksa alamat foto (hanya alamat hasil `/uploads` dengan nama 32 karakter hex) |
| `PemeriksaProduksi.php` | Aturan periksa kesiapan produksi |
| `PeriksaProduksi.php` | Perintah `php artisan disty:periksa-produksi` |
| `AppServiceProvider.php` | Provider aplikasi (didaftarkan di `laravel/bootstrap/providers.php`) |
| `TicketResource.php` | Bentuk JSON aduan, dipakai `TicketController` dan `AlurAduanController` |
