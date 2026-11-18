# Dokumentasi API (Modul 5 dan 11)

Kontrak REST API Disty HelpDesk. Laravel (`laravel/`) dan Node.js (`node-backend/`) memakai kontrak yang sama.

## Aturan umum

- Alamat dasar: `/api/v1` (contoh lokal: `http://localhost:3000/api/v1`).
- Format JSON. Sukses: `{ "data": ... }`. Gagal: `{ "error": { "code", "message", "details" } }`.
- Autentikasi: `POST /auth/login`, lalu kirim `Authorization: Bearer <token>` di setiap permintaan (JWT, masa berlaku bawaan 8 jam, diatur `JWT_EXPIRES_SECONDS`).
- Peran: `klien`, `teknisi`, `admin`. Klien hanya melihat data instansinya sendiri.
- Koleksi Postman: `postman/Disty-HelpDesk-Laravel.postman_collection.json` (jalankan folder 0 untuk login lebih dulu).

## Kode galat (field `error.code`)

LOGIN_GAGAL, AKUN_BELUM_AKTIF, TOKEN_TIDAK_VALID, TOKEN_KEDALUWARSA, TIDAK_TERAUTENTIKASI, DILARANG, VALIDASI, TIDAK_DITEMUKAN, EMAIL_DIPAKAI, MASIH_DIPAKAI, SANDI_SALAH, URUTAN_STATUS_TIDAK_VALID, SUDAH_DITUGASKAN, SUDAH_SELESAI, BELUM_SELESAI, SOLUSI_BELUM_ADA, PERLU_VERIFIKASI_ADMIN, MENUNGGU_VERIFIKASI, TIDAK_MENUNGGU_VERIFIKASI, TEKNISI_KOSONG, KUOTA_PENUH, INSTANSI_TIDAK_DIKENAL, KODE_SALAH, JSON_RUSAK, BODY_TERLALU_BESAR, SERVER_ERROR

## Contoh login

Permintaan:

    POST /api/v1/auth/login
    { "email": "rina@smkn2.sch.id", "password": "rahasia123" }

Balasan sukses berisi `data.token`, `data.tokenType`, `data.expiresIn`, dan `data.user` (`role` huruf kecil).

## Daftar endpoint

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

## Foto dan unggahan

`POST /uploads` menyimpan gambar di `laravel/public/uploads/` dengan nama acak 32 karakter hex, lalu membalas alamat absolut (misalnya `https://api.contoh.co.id/uploads/ab12....jpg`).
Alamat itu yang dikirim ke `PUT /me` (`photo`), `POST /tickets` (`attachment`), dan `PUT /tickets/{id}/photos` (`before`, `after`).
Endpoint yang menyimpan foto profil dan foto dokumentasi hanya menerima alamat berbentuk `.../uploads/<32 hex>.<jpg|png|webp|gif>`, jadi alamat sembarang (situs lain, `javascript:`, `data:`) ditolak 422.
Foto sebelum dan sesudah perbaikan disimpan di kolom `aduan.foto_sebelum` dan `aduan.foto_sesudah`, foto profil di kolom `pengguna.foto`.

