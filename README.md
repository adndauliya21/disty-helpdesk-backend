# Disty HelpDesk Back-End

Back-end untuk aplikasi Disty HelpDesk (PT Disty Teknologi Indonesia). Ada dua versi back-end yang berbagi **database MySQL yang sama** dan **kontrak API yang sama**:

- **Laravel 12** dengan Eloquent ORM (folder utama ini), untuk Modul 6 sampai 8.
- **Node.js** tanpa framework (folder [`node-backend/`](node-backend/README.md)), untuk Modul 3.
Back-end ini dan [front-end](https://github.com/DwiSelviYanti/disty-helpdesk-frontend-) adalah **satu proyek web** yang dikerjakan bersama dalam satu kelompok.
Front-end memanggil API ini lewat REST (JSON). Bentuk datanya dijelaskan di bagian *Endpoint* dan *Integrasi dengan Frontend* di bawah.

Skema database mengikuti ERD dari analis (13 tabel). File ERD ada di `database/ERD_DISTY_1.drawio`, migrasi dan seeder ada di folder `database/`.
Panduan deploy ada di folder `deploy/` (mulai dari `deploy/00-BACA-DULU.md`).

## Status singkat

| Bagian | Status | Bukti |
|---|---|---|
| Modul 2 (Bahasa pemrograman back-end, PHP) | Selesai | Folder `latihan/php/`, jalankan `php uji.php` (11 pemeriksaan) |
| Modul 3 (Back-end JavaScript, Node.js) | Selesai | Folder `node-backend/`, jalankan `npm test` di folder tersebut |
| Modul 6 (Laravel) | Selesai | Kode aplikasi di folder `auth/`, `api/`, `admin/`, `client/`, `teknisi/`, `bersama/`, `config/`, `database/`, `tests/` (sejajar dengan `laravel/`) |
| Modul 7 (Deployment) | Dokumen, skrip, dan Docker selesai; bukti deploy di server berupa tangkapan layar pada laporan | folder `deploy/` (termasuk `cek-deploy.sh`), `Dockerfile`, `docker-compose.yml` |
| Modul 8 (Integrasi dengan front-end) | Selesai, semua endpoint yang dipakai frontend sudah ada (termasuk unggah foto dan ganti kata sandi) | Bagian *Endpoint*, `tests/Feature/UnggahFotoDanSandiTest.php` |
| Modul 9 (Version Control, Git) | Selesai | Riwayat commit, branch, pull request, issue, dan release di GitHub, aturan di bagian *Alur kerja Git* |
| Pengujian otomatis Laravel | Test memakai SQLite di memori | `php artisan test` |
| Pengujian otomatis Node.js | Test unit dan integrasi (integrasi memakai MySQL) | `npm test` di folder `node-backend/` |

## Syarat dan cara menjalankan

Syarat: **PHP 8.4.1 atau lebih baru**, Composer, MySQL 8 atau MariaDB 10.5+, dan ekstensi PHP `pdo_mysql`, `mbstring`, `openssl`.

Kenapa 8.4? `composer.json` menulis `^8.2`, tetapi `composer.lock` sekarang berisi beberapa paket Symfony 8 (`symfony/clock`, `symfony/string`, `symfony/translation`, dan lainnya) yang mewajibkan PHP 8.4.1.
Di PHP 8.3 perintah `composer install` akan gagal. Ada dua jalan keluar:

1. Pakai PHP 8.4 di laptop dan di server (Ubuntu 24.04 bawaannya PHP 8.3, jadi perlu repositori tambahan).
2. Buat ulang `composer.lock` untuk PHP yang lebih rendah. Di laptop jalankan `composer config platform.php 8.3.0` lalu `composer update`, kemudian commit `composer.json` dan `composer.lock` yang baru.

Langkah menjalankan:

    cd laravel                      # semua perintah composer dan artisan dari sini
    composer install
    cp .env.example .env            # isi DB_USERNAME dan DB_PASSWORD sesuai MySQL kamu
    php artisan key:generate
    # buat database kosong dulu (sekali saja):
    # CREATE DATABASE disty_helpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    php artisan migrate:fresh --seed
    php artisan serve --port=3000   # http://localhost:3000/api/v1

Mengulang dari nol: `php artisan migrate:fresh --seed` (**menghapus semua tabel**).
Alternatif tanpa Laravel: impor `database/disty_helpdesk.sql` (di folder induk) lewat phpMyAdmin atau `mysql -u root < ../database/disty_helpdesk.sql` dari dalam `laravel/`.

Folder `database/migrations/` berisi **dua file**: `2026_11_01_000001_buat_skema_erd.php` (membangun seluruh skema ERD) dan `2026_11_09_000001_tambah_kanal_telepon_pada_aduan.php`. Migration bawaan starter kit sudah dihapus.
Kalau masih ada file migration lama berawalan `2026_10_`, hapus semuanya. Kalau tidak, `migrate` gagal dengan pesan `Table 'instansi' already exists`, dan hampir semua test ikut gagal.

Menjalankan pengujian: `php artisan test` (SQLite di memori, butuh ekstensi `pdo_sqlite`).
Beberapa test memakai perintah khusus SQLite (`PRAGMA`), jadi hanya berjalan di SQLite. `migrate:fresh --seed` dan seluruh endpoint juga sudah dicoba di MariaDB 10.11 memakai `database/disty_helpdesk.sql`.

## Akun awal (khusus pengembangan)

Semua kata sandi `rahasia123`. Login memakai **email**. Total 24 akun (data dari `database/data/demo.json`, sama dengan SQL): 1 admin, 3 teknisi, 20 klien.

| Email | Peran | Nama tampil |
|---|---|---|
| `admin@perusahaan.id` | admin | Admin Helpdesk |
| `rafi@perusahaan.id`, `dina@perusahaan.id`, `bayu@perusahaan.id` | teknisi | Rafi Pratama, Dina, Bayu |
| `rina@smkn2.sch.id` | klien (SMKN2-SBY) | Bu Rina |
| `yusuf@sman5sby.sch.id` | klien (SMAN5-SBY) | Pak Yusuf |
| `nur@man1sda.sch.id` | klien (MAN1-SDA) | Bu Nur |

Masih ada 15 klien lain, daftar lengkapnya ada di `database/disty_helpdesk.sql`.
Data contoh lain: 20 instansi, 12 aduan (8 selesai dengan solusi), 5 konsultasi, 6 survei, 11 artikel bantuan.

Contoh dengan curl:

    TOKEN=$(curl -s -X POST localhost:3000/api/v1/auth/login -H 'Content-Type: application/json' \
      -d '{"email":"rina@smkn2.sch.id","password":"rahasia123"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')
    curl -H "Authorization: Bearer $TOKEN" localhost:3000/api/v1/tickets

Koleksi Postman: `postman/Disty-HelpDesk-Laravel.postman_collection.json` (jalankan folder 0 dulu untuk login).

## Endpoint

Semua di bawah `/api/v1`. Sukses: `{ "data": ... }`. Gagal: `{ "error": { "code", "message", "details" } }`.
Kolom **Akses**: *terbuka* berarti tanpa token. Selain itu wajib header `Authorization: Bearer <token>`.

| Metode | Path | Akses | Keterangan |
|---|---|---|---|
| GET | `/ping` | terbuka | cek server hidup |
| POST | `/auth/login` | terbuka | `{ email, password }` -> `{ token, tokenType, expiresIn, user }`, `role` huruf kecil (`klien`, `teknisi`, `admin`) |
| POST | `/auth/activation/request` | terbuka | `{ email }` -> 202. Masih kerangka, lihat *Batasan* |
| POST | `/auth/activation/verify` | terbuka | `{ email, code }` -> `{ activated: true }`. Masih kerangka |
| POST | `/auth/logout` | semua peran | 204. Token tidak dicabut di server |
| GET | `/auth/me` | semua peran | profil terbaru dari database |
| PUT | `/me` | semua peran | `{ name, email, phone, photo }`, 409 bila email dipakai akun lain. Klien harus memakai domain instansi terdaftar. `photo`: alamat dari `/uploads`, `null` = hapus, tidak dikirim = tidak berubah, 422 bila alamat bukan hasil unggah |
| PUT | `/me/password` | semua peran | `{ currentPassword, newPassword }` -> 204. 403 `SANDI_SALAH` bila kata sandi lama keliru. 422 bila lama kosong, baru kurang dari 8 karakter, tanpa huruf atau angka, lebih dari 72 karakter, atau sama dengan yang lama |
| POST | `/uploads` | semua peran | `multipart/form-data`, kolom `file` (JPG, PNG, WEBP, GIF, maksimal 5 MB). 201 `{ url, size, type }`. Jenis berkas diperiksa dari isinya, bukan dari nama |
| GET | `/tickets` | semua peran | opsional `?status=` dan `?priority=`. Klien hanya melihat aduan instansinya |
| GET | `/tickets/{id}` | semua peran | 404 bila tidak ada atau milik instansi lain (untuk Klien) |
| POST | `/tickets` | Klien | buat aduan, balas 201 dan header `Location` |
| POST | `/tickets/phone` | Teknisi | aduan lewat telepon: klien menelepon teknisi langsung, teknisi mencatatnya. Body: isian aduan + `{ reporterEmail }` (email akun klien aktif). Tanpa penugasan admin: teknisi pencatat jadi teknisi utama, status langsung **Diproses**, `channel: "telepon"`. 404 klien tidak ada, 409 `AKUN_BELUM_AKTIF` |
| PATCH | `/tickets/{id}` | Admin, Teknisi | Admin: `{ priority }`. Teknisi: `{ status, note }`, `note` (catatan tahapan) opsional, maksimal 5.000 karakter. **Urutan wajib Baru -> Diproses -> Monitoring -> Selesai**: melompat atau mundur ditolak 409 `URUTAN_STATUS_TIDAK_VALID`. `Selesai` juga ditolak 409 `SOLUSI_BELUM_ADA` bila solusi belum ada |
| POST | `/tickets/{id}/assignments` | Admin | `{ technician }`. Teknisi pertama membuat status `Diproses`. 409 `SUDAH_DITUGASKAN` |
| POST | `/tickets/{id}/logs` | Teknisi | `{ message }`, 201 |
| PUT | `/tickets/{id}/solution` | Teknisi | `{ part, summary, handledBy[] }`, sekaligus `Selesai`. Hanya dari status **Monitoring** (selain itu 409 `URUTAN_STATUS_TIDAK_VALID`). 409 `SUDAH_SELESAI` | **Aduan telepon:** hanya mengirim laporan ke admin (`awaitingVerification: true`), status tetap Monitoring, klien belum melihat solusi; `PATCH` status `Selesai` ditolak 409 `PERLU_VERIFIKASI_ADMIN`; kirim ulang saat menunggu 409 `MENUNGGU_VERIFIKASI`.
| PUT | `/tickets/{id}/verification` | Admin | `{ approve, note? }`, khusus aduan telepon yang `awaitingVerification`. `true` -> **Selesai** dan solusi terlihat klien. `false` -> kembali ke teknisi (`note` wajib). 409 `TIDAK_MENUNGGU_VERIFIKASI` |
| PUT | `/tickets/{id}/rating` | Klien | `{ score }` 1 sampai 5. 409 `BELUM_SELESAI` |
| PUT | `/tickets/{id}/photos` | Teknisi | `{ before?, after? }` alamat dari `/uploads`, `null` = hapus, hanya kunci yang dikirim yang berubah. Disimpan di tabel `dokumentasi`. 403 bila aduan bukan tugas teknisi itu, 422 bila alamat tidak dikenal (semua atau tidak sama sekali) |
| GET | `/technicians`, `/technicians/{name}` | semua peran | daftar dan detail teknisi |
| GET | `/contacts/technicians` | terbuka | `[{ name, specialty, phone }]` teknisi aktif, untuk bagian "Hubungi Teknisi" di beranda |
| POST | `/technicians` | Admin | `{ name, specialty }`, 201. `name` hanya huruf, spasi, titik, apostrof, tanda hubung (angka atau simbol lain ditolak 422). `specialty` **wajib** (kosong 422). 409 bila nama sudah ada. Akun penggunanya dibuat **tidak aktif** |
| PUT | `/technicians/{name}` | Admin | ganti spesialisasi (nama tidak bisa diubah) |
| DELETE | `/technicians/{name}` | Admin | 204. 409 bila masih tercatat di aduan atau akun |
| GET | `/consultations` | semua peran | terbaru di depan. Klien hanya melihat instansinya |
| POST | `/consultations` | Klien | `{ topic, note, date, time }`. 409 `KUOTA_PENUH` bila jam penuh atau bukan salah satu dari 09.00, 10.00, 11.00, 13.00, 14.00, 15.00 |
| PATCH | `/consultations/{id}` | Admin | `{ status }`: `Terjadwal`, `Ditolak`, `Jadi`, `Tidak`. `Terjadwal` tanpa teknisi ditolak 409 `TEKNISI_KOSONG` |
| PUT | `/consultations/{id}/technician` | Admin | `{ technician }` |
| PUT | `/consultations/{id}/schedule` | Admin | `{ date, time, reason }`. `time` dan `reason` wajib (422 "Jam wajib diisi" / "Alasan wajib diisi"). Tanggal harus hari ini (WIB) atau setelahnya dan jam hari ini yang sudah lewat ditolak 422. Jam penuh 409 `KUOTA_PENUH`, status selain Menunggu/Terjadwal 409 `STATUS_TIDAK_VALID`. Klien melihat jadwal baru dan alasannya di konsultasi itu |
| POST | `/consultations/{id}/messages` | Admin | `{ text }`, 201 |
| GET | `/consultation-quota` | semua peran | `{ defaults, overrides }` |
| PUT | `/consultation-quota` | Admin | ganti seluruh kuota, nilai 0 sampai 20 |
| GET | `/surveys/me` | semua peran | `data: null` bila belum mengisi atau bukan Klien |
| PUT | `/surveys/me` | Klien | satu survei per akun, kirim ulang menimpa. 422 bila `overall` bukan 1 sampai 5 |
| GET | `/knowledge-base` | semua peran | judul panduan per kategori |
| GET | `/solutions` | semua peran | selalu kosong: solusi ada di tabel `solusi_aduan` dan sudah ikut di setiap aduan (field `solution`) |
| GET | `/institutions` | semua peran | disusun dari tabel `instansi` dan `pengguna` (klien), survei dari tabel `survei` |

Kode status yang dipakai: 200, 201, 204, 400, 401, 403, 404, 405 (disertai daftar metode yang boleh), 409, 413, 422, 500.

### Foto dan unggahan

`POST /uploads` menyimpan gambar di `laravel/public/uploads/` dengan nama acak 32 karakter hex, lalu membalas alamat absolut (misalnya `https://api.contoh.co.id/uploads/ab12....jpg`).
Alamat itu yang dikirim ke `PUT /me` (`photo`), `POST /tickets` (`attachment`), dan `PUT /tickets/{id}/photos` (`before`, `after`).
Endpoint yang menyimpan foto profil dan foto dokumentasi hanya menerima alamat berbentuk `.../uploads/<32 hex>.<jpg|png|webp|gif>`, jadi alamat sembarang (situs lain, `javascript:`, `data:`) ditolak 422.
Foto sebelum dan sesudah perbaikan disimpan di kolom `aduan.foto_sebelum` dan `aduan.foto_sesudah`, foto profil di kolom `pengguna.foto`.

## Peta materi Modul 6

| Materi silabus | Di mana |
|---|---|
| Arsitektur MVC | Request -> `api/api.php` -> middleware -> **Controller** (`api/`, `auth/`, `admin/`, `client/`, `teknisi/`) -> **Model** (`auth/`, `api/`, `admin/`, `client/`) -> **Resource** (`admin/`, `client/`, `bersama/`, bentuk JSON sebagai "view" API). View sebenarnya adalah front-end |
| Controller | `AuthController`, `TicketController`, `AlurAduanController`, `KonsultasiController`, `KuotaKonsultasiController`, `SurveiController`, `ReferensiController`, `TechnicianController`, `InfoController`, `UploadController`, dan `Controller` (class dasar: baca body, validasi, helper error) |
| Model | `Aduan`, `User` (tabel `pengguna`, termasuk teknisi), `Instansi`, `Modul`, `PenugasanTeknisi`, `RiwayatAduan`, `SolusiAduan`, `ArtikelBantuan`, `Survei`, `Konsultasi`, `PesanKonsultasi`, `RiwayatKonsultasi`, `KuotaKonsultasi` |
| Migration | `database/migrations/2026_11_01_000001_buat_skema_erd.php` (satu file, `up()` membuat dan `down()` membatalkan) |
| Seeder | `DatabaseSeeder` (data contoh dari `database/data/demo.json`, sama dengan SQL) memanggil `DataAcuanSeeder` (instansi, modul, artikel). `ProductionSeeder` khusus server asli |
| ORM Eloquent | Model, relasi, eager loading, query scope, dan transaksi (tabel di bawah) |

### Eloquent di proyek ini

| Konsep | Contoh |
|---|---|
| Model = satu tabel | `Aduan` -> `aduan`, `User` -> `pengguna`, `Instansi` -> `instansi`, dan seterusnya (13 tabel, semua berkunci `id`) |
| Relasi 1:N | `Aduan::riwayat()` (`hasMany`) |
| Relasi 1:1 | `Aduan::solusi()` (`hasOne`) |
| Relasi N:M | `Aduan::teknisi()` lewat tabel `penugasan_teknisi` (`belongsToMany`, kolom `peran`) |
| Relasi N:1 | `Aduan::pelapor()`, `Aduan::modul()`, `User::instansi()` (`belongsTo`) |
| Eager loading | `Aduan::lengkap()` memuat `pelapor.instansi`, `modul`, `teknisi`, `riwayat.pengguna`, `solusi`: jumlah query tetap berapa pun banyaknya aduan (bukan 1 query tambahan per aduan) |
| Query scope | `lengkap()`, `saring()`, `terbaruDulu()` dipakai berantai di controller |
| Transaksi | `Aduan::baru()` membaca nomor terakhir (`lockForUpdate`), menyimpan aduan, dan menyimpan log awal dalam satu `DB::transaction`, jadi dua POST bersamaan tidak mendapat nomor kembar |
| Mass assignment | `$fillable` di tiap model |
| Cast | lewat method `casts()` di model |
| Validasi | `Validator` bawaan Laravel lewat `Controller::validasi()` |

### Padanan dengan back-end Node (Modul 3)

Versi Node ada di folder `node-backend/` (penjelasan lengkap di [node-backend/README.md](node-backend/README.md)).

| Node (`node-backend/`) | Laravel |
|---|---|
| `src/server.js`, `src/app.js` | `laravel/public/index.php` dan `laravel/bootstrap/app.php` |
| `src/routes.js`, `src/core/router.js` | `api/api.php` (prefix `/api/v1` diatur di `laravel/bootstrap/app.php`) |
| `src/middleware/auth.js` | `Autentikasi.php` (alias `jwt`) dan `PeranDiizinkan.php` (alias `peran:Admin`) |
| `src/auth/jwt.js` | `auth/Jwt.php` (HS256, ditulis sendiri tanpa paket tambahan) |
| `src/core/http-error.js` | `ApiException.php` dan `withExceptions` di `laravel/bootstrap/app.php` |
| `src/core/validator.js` | `Validator` bawaan Laravel |
| `src/controllers/*` | `api/`, `auth/`, `admin/`, `client/`, `teknisi/` (controller) |
| `src/repositories/*` | model di `auth/`, `api/`, `admin/`, `client/`, enum dan pendukung di `bersama/`, `auth/`, `admin/support/` |
| `keKontrak()` di repositori | resource di `admin/`, `client/`, `bersama/` |
| `tests/unit`, `tests/integrasi` | `tests/Unit` dan `tests/Feature` |

Perbedaan kecil yang disengaja:

- Hash kata sandi memakai bcrypt di kedua versi (Node memakai `bcryptjs` dan menulis awalan `$2y$`). Akibatnya hash buatan satu versi bisa dipakai versi lain, dan token JWT juga saling diterima selama `JWT_SECRET` sama.
- `GET /ping` memakai kunci `laravel` di versi Laravel dan `node` di versi Node.
- Teknisi adalah baris tabel `pengguna` berperan `teknisi`, dikenali lewat nama (`Rafi Pratama`, `Dina`, `Bayu`).

## Struktur

    laravel/                      semua berkas yang dibutuhkan Laravel agar hidup
      artisan                     perintah php artisan
      public/index.php            pintu masuk (front controller)
      bootstrap/app.php           rute, alias middleware, penanganan error, jalur ke folder setara
      routes/                     console.php dan web.php (rute API ada di api/api.php)
      composer.json, phpunit.xml, .env.example, .env.production.example
      vendor/, storage/, public/uploads/   (isi uploads tidak ikut Git)
    api/                          rute API (api.php), controller dasar, referensi, unggah, ApiException
    auth/                         masuk, kata sandi, token, middleware, model User, GantiKataSandi
    admin/                        teknisi dan kuota konsultasi (admin/support/ berisi KuotaKonsultasi)
    client/                       aduan, konsultasi, survei, model terkait
    teknisi/                      alur aduan
    bersama/                      enum, Waktu, UrlFoto, PemeriksaProduksi, provider, TicketResource
    config/                       app, database, cors, disty (JWT dan admin awal)
    database/migrations/          2 file: skema ERD dan kanal telepon
    database/seeders/             DatabaseSeeder, DataAcuanSeeder, ProductionSeeder
    database/data/demo.json       data contoh (hasil ekspor dari SQL)
    database/disty_helpdesk.sql   13 tabel ERD dan data dummy untuk MySQL langsung
    tests/Feature, tests/Unit     tes PHPUnit
    postman/                      koleksi Postman khusus Laravel
    node-backend/                 versi Node.js dari API yang sama (Modul 3), dengan README sendiri
    deploy/                       skrip dan contoh konfigurasi server
    latihan/                      latihan PHP murni (Modul 2)

Namespace PHP tidak berubah. `laravel/composer.json` memetakan awalan `App\\...` ke folder-folder di atas lewat jalur `../xxx`. Setiap folder punya `00-BACA-DULU.md`.

## Batasan yang diketahui

- **Aktivasi akun masih kerangka.** `POST /auth/activation/request` tidak mengirim email dan tidak menyimpan kode. `verify` hanya memeriksa panjang kode (4 sampai 8 karakter). Aktivasi tidak memengaruhi login.
- **`/institutions` membaca berkas JSON**, bukan tabel `instansi` (yang berisi 20 baris).
- **Logout tidak mencabut token.** Token berlaku 8 jam. Untuk mencabut semua token sekaligus, ganti `JWT_SECRET` lalu `php artisan config:cache`.
- **Belum ada pembatasan percobaan login** (rate limiting).
- **Foto lama tidak dihapus dari disk** saat diganti atau dihapus lewat API. Baris database berubah, berkas di `laravel/public/uploads/` tetap ada.
- **Batas ukuran unggahan** bergantung server: `upload_max_filesize` dan `post_max_size` di PHP minimal `6M`, dan `client_max_body_size 6m` di Nginx. Bila lebih kecil, unggahan 5 MB ditolak server sebelum sampai ke Laravel.
- **Alamat foto** dibuat dari skema dan host permintaan. Di balik proxy atau CDN yang mengakhiri HTTPS, pastikan header `X-Forwarded-Proto` diteruskan dan proxy dipercaya, supaya alamatnya `https` (bukan `http`).
- **Waktu memakai UTC**, termasuk `solved_on`. Di WIB, solusi yang disimpan antara 00.00 dan 06.59 tercatat dengan tanggal hari sebelumnya.
- **Belum ada endpoint untuk membuat akun Klien** dari aplikasi. Akun klien hanya ada dari seeder.
- **Aturan akses klien untuk konsultasi** (klien hanya melihat konsultasi instansinya) adalah keputusan sementara yang masih menunggu konfirmasi analis.
- **CORS `*`** hanya untuk pengembangan.
- Di produksi isi `JWT_SECRET` dengan teks acak panjang dan ganti semua kata sandi awal. Bila `JWT_SECRET` masih bawaan di `APP_ENV=production`, semua permintaan web ditolak dengan 500. Perintah `php artisan disty:periksa-produksi` memeriksa semua syarat produksi.
- Jangan ikutkan file `.env` saat mengirim atau mengunggah proyek. Isinya password database dan `JWT_SECRET`.

## Integrasi dengan Frontend

## Alur kerja Git

- Branch main hanya diubah lewat pull request.
- Nama branch memakai awalan feature/, fix/, docs/, test/, atau ci/.
- Pesan commit memakai awalan feat:, fix:, docs:, test:, atau chore:.
- Satu pull request membahas satu topik, dengan judul dan deskripsi yang jelas, lalu direview sebelum merge.
- Tugas dan bug dicatat di Issues, lalu dihubungkan ke pull request dengan Closes #nomor.
- Versi rilis ditandai dengan tag, misalnya v1.0.0.

Frontend ada di repo terpisah: https://github.com/DwiSelviYanti/disty-helpdesk-frontend-

Cara menghubungkan:

1. Jalankan backend: `php artisan migrate:fresh --seed`, lalu `php artisan serve --port=3000`
2. Isi `CORS_ORIGIN` di `laravel/.env` dengan alamat frontend, misalnya `http://localhost:5500`
3. Di frontend, ubah `baseUrl` pada konfigurasi API menjadi `http://localhost:3000/api/v1`
4. Jalankan frontend sebagai file statis, misalnya `npx serve -l 5500`
5. Login memakai akun demo di bagian *Akun awal* di atas

Frontend memanggil REST API berformat JSON.
Sukses dibalas `{ "data": ... }`, gagal dibalas `{ "error": { "code", "message", "details" } }`.
Saat login, frontend memanggil 8 endpoint sekaligus: `/tickets`, `/consultations`, `/consultation-quota`, `/technicians`, `/surveys/me`, `/knowledge-base`, `/institutions`, dan `/solutions`.
Bila salah satu gagal, login ikut gagal.

Di produksi, `CORS_ORIGIN` harus diisi alamat frontend yang persis. Nilai bawaan `*` hanya untuk pengembangan.
