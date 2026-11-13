# Disty HelpDesk, Back-End Node.js (Modul 3)

Versi Node.js dari API Disty HelpDesk. Folder ini **menambah** proyek Laravel, tidak menggantinya:

- **Database sama.** Memakai database MySQL `disty_helpdesk` dari `../database/disty_helpdesk.sql`. Tidak ada tabel atau kolom yang diubah.
- **Kontrak API sama.** Semua endpoint di `/api/v1` mengembalikan JSON yang sama persis dengan versi Laravel (status, isi, urutan kunci, kode galat, header `Location`).
- **Tampilan sama.** Node menyajikan folder `../laravel/public` (halaman, CSS, JS, foto unggahan), jadi UI tidak diubah sama sekali.
- **Tanpa framework.** Hanya memakai modul bawaan Node (`node:http`, `node:crypto`, `node:fs`) ditambah tiga paket: `mysql2` (koneksi MySQL), `bcryptjs` (hash kata sandi), dan `busboy` (membaca unggahan foto).

## Cara menjalankan

Syarat: Node.js 22 atau lebih baru, dan database `disty_helpdesk` sudah diimpor.

    cd node-backend
    npm install
    cp .env.example .env        # isi DB_USERNAME dan DB_PASSWORD sesuai MySQL kamu
    npm start                   # http://localhost:3000

Buka `http://localhost:3000/` untuk tampilan, atau `http://localhost:3000/api/v1/ping` untuk memeriksa API.
Mode pengembangan yang otomatis restart saat kode berubah: `npm run dev`.
Jangan menjalankan versi Node dan Laravel di port yang sama. Ubah `PORT` di `.env` bila perlu.

Variabel `.env` memakai nama yang sama dengan `.env` Laravel. Kalau `JWT_SECRET` dibuat sama, **token dari satu versi diterima versi lain**, dan kata sandi di database bisa dipakai kedua versi.

## Menjalankan tes

    npm test               # semua (73 tes)
    npm run test:unit      # 44 tes unit, tanpa database

Tes integrasi (29 tes) menyalakan server sungguhan lalu memanggilnya lewat HTTP. Tes ini membuat database **tersendiri** bernama `disty_helpdesk_test` dari file SQL, jadi data pengembanganmu aman. Nama database tes tidak boleh sama dengan `disty_helpdesk`, dan tes menolak berjalan kalau sama. Atur akun MySQL lewat variabel lingkungan:

    DB_USERNAME=root DB_PASSWORD=rahasia npm test

Bila MySQL tidak bisa dihubungi, tes integrasi dilewati dengan pesan yang jelas (bukan gagal).

## Hubungan dengan silabus Modul 3

| Butir silabus | Di mana dipakai |
|---|---|
| Sintaks JavaScript ES6+ | Modul ES (`import` dan `export`) di semua file. Arrow function, destructuring, spread, template literal, optional chaining (`?.`), nullish (`??` dan `??=`), `class` dengan field privat (`#rute` di `src/core/router.js`), `Map` dan `Set` (`src/controllers/tickets.js`) |
| Callback | `server.listen(port, callback)` di `src/server.js`. Event `req.on('data', callback)` di `src/core/body.js`. Event `connection` di `src/db.js` |
| Promise | `new Promise(...)` di `src/core/body.js` (`bacaMentah`, `bacaBerkas`). `Promise.all` untuk menjalankan beberapa query sekaligus di `src/repositories/aduan.js` |
| async/await | Semua controller dan repositori, dengan `try`, `catch`, dan `finally` di `src/app.js` dan `src/db.js` (`transaksi`) |
| Dasar Node.js | Modul bawaan `node:http` (server), `node:crypto` (JWT dan hash acak), `node:fs/promises` (simpan foto), `node:path`, `node:url`, `process.env` dan `process.loadEnvFile` (`src/config.js`), `node:test` (tes), `package.json` dan `npm` |
| Mekanisme request-response | `src/app.js` (alur satu permintaan), `src/core/router.js` (mencocokkan alamat), `src/middleware/auth.js` (cek login dan peran sebelum handler) |

### Alur satu permintaan

    Permintaan HTTP
      -> src/app.js          alamat /api/v1/...? (bukan: sajikan berkas dari ../laravel/public)
      -> src/core/router.js  cari rute (tidak ada: 404, metode salah: 405)
      -> middleware          autentikasi (401) lalu peran (403)
      -> controller          baca isi JSON (400/413), validasi (422), aturan bisnis (409)
      -> repositori          query MySQL (tanda tanya sebagai placeholder, bukan sambung teks)
      -> JSON                { data: ... } atau { error: { code, message, details } }

## Struktur

    src/
      server.js              pintu masuk, menyalakan server dan berhenti dengan rapi
      app.js                 alur permintaan, CORS, penanganan galat
      config.js              membaca .env
      db.js                  koneksi MySQL dan transaksi
      routes.js              daftar rute (sama dengan api/api.php di folder induk)
      core/                  router, pembaca body JSON dan multipart, validator, galat HTTP, penyaji berkas statis
      auth/                  jwt.js (HS256 buatan sendiri), password.js (bcrypt)
      middleware/auth.js     autentikasi dan peran
      controllers/           auth, tickets, konsultasi, survei, referensi, teknisi, upload
      repositories/          pengguna, aduan, konsultasi, survei (semua SQL ada di sini)
      support/               waktu (UTC dan WIB), teks, pemeriksa alamat foto
    tests/
      unit/                  jwt, validator, waktu, teks, router, password, pengenal gambar
      integrasi/             alur lengkap lewat HTTP ke database tes

## Seberapa mirip dengan Laravel?

Rangkaian 325 permintaan yang sama dikirim ke Laravel dan ke Node di atas database baru yang identik, lalu status dan isi JSON dibandingkan satu per satu, termasuk urutan kunci dan jumlah baris di database sesudahnya. Cakupannya: login, profil, kata sandi, unggah foto, seluruh alur aduan, konsultasi, kuota, survei, referensi, teknisi, aktivasi akun, serta kasus galat (401, 403, 404, 405, 409, 413, 422).

Hasil: **323 identik, 2 berbeda yang disengaja, 0 berbeda tak terduga.** Dua perbedaan itu:

1. Nilai `true` untuk `score` rating. Laravel menerimanya sebagai angka 1 (keanehan PHP). Node menolak dengan 422, dan UI tidak pernah mengirim nilai seperti itu.
2. Alamat dengan `%` yang tidak sah. Keduanya membalas 400 dengan kode `GALAT_HTTP`, hanya kalimat pesannya yang berbeda (Node berbahasa Indonesia).

Satu catatan lain: `GET /ping` memakai kunci `node` (versi Node) menggantikan kunci `laravel`.

## Catatan keamanan

- **JWT_SECRET wajib diganti** di produksi. Server mencetak peringatan bila masih memakai nilai bawaan.
- Semua query memakai placeholder `?`, jadi nilai dari pengguna tidak pernah disambung ke teks SQL.
- Kata sandi disimpan sebagai hash bcrypt. Login memberi pesan yang sama untuk email salah dan kata sandi salah, dan tetap menghitung hash walau email tidak ada supaya lama respons tidak membocorkan akun.
- Unggahan foto: jenis berkas ditentukan dari isi (byte awal), bukan dari nama atau `Content-Type` kiriman. Nama berkas diacak 32 karakter. SVG ditolak karena bisa memuat skrip. Maksimal 5 MB.
- Penyaji berkas statis menolak berkas tersembunyi (`.env`, `.htaccess`), skrip `.php`, dan upaya naik folder (`../`).
- Isian foto di profil dan aduan hanya menerima alamat hasil `POST /uploads`.

## Deploy singkat

Jalankan dengan manajer proses (misalnya `pm2 start src/server.js --name disty-helpdesk`) lalu letakkan di belakang Nginx:

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        client_max_body_size 6m;
    }

Header `X-Forwarded-Proto` dipakai agar alamat foto yang dikembalikan memakai `https`. Pastikan folder `../laravel/public/uploads` bisa ditulis oleh pengguna yang menjalankan Node.
