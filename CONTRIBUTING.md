# Panduan Kontribusi

Panduan singkat alur kerja Git untuk proyek Disty HelpDesk Backend.

## Aturan branch

- `main` selalu stabil dan tidak boleh di-commit langsung.
- Semua pekerjaan dilakukan di branch terpisah, lalu digabung lewat Pull Request.

Format nama branch:

| Awalan | Kegunaan | Contoh |
|---|---|---|
| `feature/` | Fitur baru | `feature/endpoint-survei` |
| `fix/` | Perbaikan bug | `fix/validasi-login` |
| `docs/` | Dokumentasi | `docs/tambah-contributing` |
| `test/` | Penambahan tes | `test/tes-jwt` |

## Aturan commit

Pesan commit memakai format `jenis: deskripsi singkat`.

| Jenis | Arti |
|---|---|
| `feat` | Fitur baru |
| `fix` | Perbaikan bug |
| `docs` | Perubahan dokumentasi |
| `test` | Menambah atau mengubah tes |
| `chore` | Pekerjaan pendukung (konfigurasi, deploy) |

Contoh: `fix: perbaiki validasi email saat login`

## Alur kerja

1. Ambil `main` terbaru: `git switch main` lalu `git pull`.
2. Buat branch baru: `git switch -c feature/nama-fitur`.
3. Kerjakan perubahan dan commit dalam potongan kecil.
4. Push branch: `git push -u origin feature/nama-fitur`.
5. Buka Pull Request ke `main` dan isi deskripsinya.
6. Setelah ditinjau dan disetujui, gabungkan lalu hapus branch.

## Yang tidak boleh masuk repository

- File `.env` dan rahasia lain (`APP_KEY`, `DB_PASSWORD`, `JWT_SECRET`)
- Folder `vendor/` dan `node_modules/`
- Database lokal (`*.sqlite`) dan file log