# admin/ : urusan peran Admin

| Berkas | Fungsi |
|---|---|
| `TechnicianController.php` | Daftar dan detail teknisi (semua peran yang login). Tambah, ubah, hapus hanya Admin. `kontak` terbuka untuk umum |
| `TechnicianResource.php` | Bentuk JSON teknisi |
| `PenugasanTeknisi.php` | Model penugasan teknisi ke aduan |
| `KuotaKonsultasiController.php` | Lihat kuota (semua peran yang login), simpan kuota (hanya Admin) |
| `KuotaKonsultasi.php` | Model kuota konsultasi |
| `support/KuotaKonsultasi.php` | Aturan jam kuota (09.00, 10.00, 11.00, 13.00, 14.00, 15.00). Subfolder karena namanya sama dengan modelnya |

Halaman Admin di front-end: `laravel/public/helpdesk/admin/halaman-admin.js` (tidak dipindah).

## Catatan
- Tidak ada kode laporan di proyek ini. Folder ini tidak berisi fitur laporan.
- `support/KuotaKonsultasi.php` juga dipakai `KonsultasiController` di `client/`.
