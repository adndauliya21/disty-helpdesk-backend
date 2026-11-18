# Pengujian (Modul 10)

## Tes otomatis Laravel (`tests/`)

Dijalankan dari folder `laravel/` dengan `php artisan test` (SQLite di memori, butuh ekstensi `pdo_sqlite`). Jumlah metode tes dihitung dari kode: 161.

| Berkas | Metode tes | Cakupan (menurut nama berkas) |
|---|---|---|
| `Feature/AuthTest.php` | 4 | Login dan autentikasi |
| `Feature/ProfilDanAktivasiTest.php` | 7 | Profil dan aktivasi akun |
| `Feature/UnggahFotoDanSandiTest.php` | 10 | Unggah foto dan ganti kata sandi |
| `Feature/AduanTest.php` | 7 | Membuat dan membaca aduan |
| `Feature/AlurAduanTest.php` | 16 | Urutan status aduan, penugasan, solusi |
| `Feature/AduanTeleponTest.php` | 13 | Aduan telepon dan verifikasi Admin |
| `Feature/KodeInstansiTest.php` | 11 | Kode dan domain instansi |
| `Feature/KonsultasiTest.php` | 9 | Konsultasi dan kuota |
| `Feature/SurveiTest.php` | 19 | Survei dan penilaian |
| `Feature/TechnicianTest.php` | 11 | Pengelolaan teknisi |
| `Feature/ReferensiTest.php` | 13 | Data acuan (panduan, instansi, kontak) |
| `Feature/ReferensiSurveiTeknisiTest.php` | 5 | Data acuan survei dan teknisi |
| `Feature/ApiTest.php` | 15 | Perilaku umum API (format, kode status) |
| `Feature/SkemaErdTest.php` | 8 | Kesesuaian skema database dengan ERD |
| `Feature/ProduksiTest.php` | 7 | Pemeriksaan pengaturan produksi |
| `Unit/JwtTest.php` | 6 | Pembuatan dan pemeriksaan token JWT |

## Tes otomatis Node.js (`node-backend/tests/`)

Dari folder `node-backend/`: `npm test` (semua) atau `npm run test:unit` (tanpa database). Tes integrasi (`tests/integrasi/api.test.js`) memakai MySQL. Tes unit: `validator.test.js`, `jwt.test.js`, `support.test.js`.

## PHP murni (Modul 2)

Dari `latihan/php/`: `php uji.php` (11 pemeriksaan).

## Uji manual lewat Postman

Impor `postman/Disty-HelpDesk-Laravel.postman_collection.json`, jalankan folder 0 (login) lebih dulu.

## Hasil pengujian

Isi setelah menjalankan tes di komputer masing-masing.

| Tanggal | Perintah | Lingkungan | Jumlah lulus | Jumlah gagal | Catatan |
|---|---|---|---|---|---|
| (isi) | `php artisan test` | PHP 8.4, SQLite | | | |
| (isi) | `npm test` | Node.js, MySQL | | | |

## Bug yang ditemukan dan perbaikannya

(isi bila ada)
