# client/ : tiket klien, aduan, konsultasi, survei

| Berkas | Fungsi |
|---|---|
| `TicketController.php` | Aduan (rute tetap `/tickets`, datanya di tabel aduan) |
| `KonsultasiController.php` | Konsultasi. Buat konsultasi oleh Klien, ubah status, jadwal, teknisi, dan balas oleh Admin |
| `SurveiController.php` | Survei saya |
| `ConsultationResource.php` | Bentuk JSON konsultasi |
| `Aduan.php`, `SolusiAduan.php`, `RiwayatAduan.php` | Model aduan |
| `Konsultasi.php`, `PesanKonsultasi.php`, `RiwayatKonsultasi.php` | Model konsultasi |
| `Survei.php`, `Modul.php` | Model survei dan modul aduan |

Halaman Klien di front-end: `laravel/public/helpdesk/client/halaman-klien.js` (tidak dipindah).

## Rute khusus Klien (dari `api/api.php`)
Buat aduan `POST /tickets`, beri nilai `PUT /tickets/{id}/rating`, buat konsultasi `POST /consultations`, isi survei `PUT /surveys/me`.

## Catatan
- `KonsultasiController` ditaruh di sini karena aduan dan konsultasi satu urusan, walau 4 dari 6 rutenya khusus Admin.
