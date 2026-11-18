# Laporan Akhir Proyek Disty HelpDesk Back-End (Modul 11)

Draf kerangka laporan. Bagian bertanda (isi) harus dilengkapi tim karena berasal dari pengalaman dan bukti langsung (misalnya tangkapan layar dan hasil deploy).

## Halaman judul

Judul: Pengembangan Back-End Disty HelpDesk, PT Disty Teknologi Indonesia
Penyusun: Adinda Auliya (24081010001)
Pembimbing: (isi)
Periode magang: (isi)

## Bab 1. Pendahuluan

1.1 Latar belakang: layanan bantuan teknis klien memerlukan pencatatan aduan, penugasan teknisi, dan pemantauan sampai selesai. Lihat `docs/01-analisis-kebutuhan.md` bagian 1.
1.2 Tujuan: lihat bagian 1 dokumen yang sama.
1.3 Ruang lingkup: API untuk peran Klien, Teknisi, dan Admin, database 13 tabel, dua versi back-end (Laravel dan Node.js), deployment.
1.4 Pembagian kerja: back-end oleh Adinda Auliya, front-end oleh Dwi Selvi Yanti (repo `disty-helpdesk-frontend-`).

## Bab 2. Analisis Kebutuhan (Modul 1)

Rujuk `docs/01-analisis-kebutuhan.md`: aktor, kebutuhan fungsional dan non-fungsional, aturan bisnis, alur sistem, ERD awal.

## Bab 3. Perancangan

3.1 Database: `docs/DATABASE.md` dan `database/ERD_DISTY_1.drawio` (Modul 4).
3.2 API: `docs/API-KONTRAK.md` (Modul 5).
3.3 Arsitektur: Laravel (Controller, Model Eloquent, Enum, Resource) dan Node.js tanpa framework, berbagi database dan kontrak yang sama (Modul 3 dan 6).

## Bab 4. Implementasi per modul

| Modul silabus | Hasil | Letak |
|---|---|---|
| 2. Bahasa pemrograman back-end | Latihan PHP murni dengan OOP dan pengujian | `latihan/php/` |
| 3. Back-end berbasis JavaScript | API Node.js, async/await, struktur request-response | `node-backend/` |
| 4. Database dan SQL | Skema 13 tabel, migrasi, seeder, berkas SQL | `database/` |
| 5. Pemrograman API | REST, JWT, middleware, koleksi Postman | `api/`, `auth/`, `postman/` |
| 6. Framework Laravel | MVC, controller, migration, seeder, Eloquent | `auth/`, `api/`, `admin/`, `client/`, `teknisi/`, `bersama/`, `laravel/` |
| 7. Integrasi cloud | Konfigurasi produksi, skrip deploy, Docker | `deploy/`, `Dockerfile`, `docker-compose.yml` |
| 8. Integrasi dengan front-end | REST JSON, CORS, unggah foto | `config/cors.php`, repo front-end |
| 9. Version control | Branch, pull request, issue, aturan commit | `CONTRIBUTING.md`, riwayat GitHub |

## Bab 5. Pengujian (Modul 10)

Rujuk `docs/PENGUJIAN.md`. Cantumkan hasil tes dan bug yang ditemukan: (isi).

## Bab 6. Deployment (Modul 7)

Panduan: `deploy/00-BACA-DULU.md`. Bukti deploy (alamat server, tangkapan layar `cek-deploy.sh`, tangkapan layar aplikasi): (isi).

## Bab 7. Penutup

Kesimpulan: (isi). Kendala dan solusi: (isi). Saran pengembangan: (isi). Batasan yang diketahui ada di bagian *Batasan yang diketahui* pada `README.md`.

## Lampiran

- Koleksi Postman
- ERD (`database/ERD_DISTY_1.drawio`)
- Tangkapan layar aplikasi: (isi)
