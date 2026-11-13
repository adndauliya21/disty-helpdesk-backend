# deploy/ : skrip dan contoh konfigurasi server (Modul 7)

Cara pakai lengkap ada di `docs/DEPLOY.md`. Berkas ini hanya peta singkat.

| Berkas | Fungsi |
|---|---|
| `setup-vps-ubuntu.sh` | Menyiapkan server Ubuntu untuk pertama kali |
| `deploy.sh` | Deploy pertama dan update berikutnya. Jalankan sebagai pemilik folder, bukan root |
| `cek-deploy.sh` | Uji cepat setelah deploy: apakah API hidup dan aman |
| `backup-db.sh` | Backup database MySQL ke `laravel/storage/backups` |
| `restore-db.sh` | Pulihkan database dari berkas backup |
| `_lib.sh` | Fungsi bersama, dipakai skrip lain, tidak dijalankan langsung |
| `nginx.conf.example`, `apache-vhost.conf.example` | Contoh konfigurasi web server |
| `cron.example` | Contoh jadwal backup harian |

## Aturan
- Jangan ubah isi skrip tanpa izin. Skrip ini bukti Modul 7.
- Skrip memakai jalur `laravel/public/uploads`, `laravel/storage/`, dan `laravel/bootstrap/cache` lewat variabel `LARAVEL_DIR` di `_lib.sh`. Kalau folder itu dipindah, skrip ikut berubah.
