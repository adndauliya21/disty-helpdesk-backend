# database/ : skema, data contoh, ERD (Modul 4)

Folder ini dulu bernama `database/` di akar proyek Laravel. `laravel/bootstrap/app.php` memakai `useDatabasePath`, jadi `database_path()` menunjuk ke sini.

| Berkas | Fungsi |
|---|---|
| `migrations/2026_11_01_000001_buat_skema_erd.php` | Membangun seluruh 13 tabel ERD |
| `migrations/2026_11_09_000001_tambah_kanal_telepon_pada_aduan.php` | Menambah kanal telepon pada aduan |
| `seeders/` | `DatabaseSeeder` (data contoh), `DataAcuanSeeder` (instansi, modul, artikel), `ProductionSeeder` (server asli) |
| `data/demo.json` | Data contoh yang dibaca seeder |
| `disty_helpdesk.sql` | Versi SQL langsung untuk MySQL, isinya harus sama dengan `demo.json` |
| `ERD_DISTY_1.drawio` | Diagram ERD |
| `database.sqlite` | Berkas SQLite lokal, tidak masuk Git |

Penjelasan 13 tabel ada di `docs/DATABASE-ERD.md`.

## Aturan
- `disty_helpdesk.sql` dan `demo.json` harus tetap sama isinya (hash sebelum dan sesudah pemindahan sudah dicocokkan). Backend Node memakai SQL itu untuk tesnya lewat `node-backend/tests/helper-db.js`.
- `php artisan migrate:fresh --seed` menghapus semua tabel, hanya untuk pengembangan.
- Lima migration starter kit dan `UserFactory.php` sudah dihapus dengan bukti `grep` (lihat `CLAUDE.md` bagian 10.8).
- Namespace seeder `Database\Seeders\` dipetakan ke `../database/seeders/` di `laravel/composer.json`.
