<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Instansi;
use App\Models\User;
use App\Support\Jwt;
use App\Support\UrlFoto;
use App\Support\Waktu;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/* [MODUL 6] POST /auth/login dan GET /auth/me (padanan src/controllers/auth-controller.js).
   [MODUL 8] Disesuaikan dengan docs/API-KONTRAK.md di frontend:
     - login memakai email (bukan username), user = { role, name, email, instansiCode }, role huruf kecil
     - POST /auth/logout (204), POST /auth/activation/request dan /verify, PUT /me
   [MODUL 8, tahap akhir] PUT /me menerima photo (alamat dari POST /uploads), ditambah PUT /me/password */
class AuthController extends Controller
{
    // Hash palsu (bcrypt yang valid): dipakai agar waktu respons sama baik email ada maupun tidak
    // (mencegah penebakan email lewat lama respons).
    private const HASH_PALSU = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /** Lama berlaku kode aktivasi yang diumumkan ke frontend (detik). Sama dengan server tiruan. */
    private const KODE_AKTIVASI_DETIK = 600;

    /** Body: { "email": "...", "password": "..." }. Berhasil -> { data: { token, tokenType, expiresIn, user } } */
    public function login(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, [
            'email' => ['bail', 'required', 'string', 'email'],
            'password' => ['bail', 'required', 'string'],
        ], [
            'email.required' => 'Isi email dengan benar',
            'email.string' => 'Isi email dengan benar',
            'email.email' => 'Isi email dengan benar',
            'password.required' => 'Isi kata sandi',
            'password.string' => 'Isi kata sandi',
        ]);

        $akun = User::with('instansi')
            ->where('email', mb_strtolower(trim($isi['email'])))
            ->first();
        $cocok = Hash::check($isi['password'], $akun?->password ?? self::HASH_PALSU);

        // Satu pesan untuk "email salah" dan "kata sandi salah", supaya penyerang tidak tahu mana yang benar.
        // Akun belum aktif (status nonaktif, mis. teknisi baru dari POST /technicians) juga ditolak dengan pesan yang sama.
        if (! $akun || ! $cocok || ! $akun->aktif()) {
            throw new ApiException(401, 'LOGIN_GAGAL', 'Email atau kata sandi salah');
        }

        // Isi token memakai peran asli database (Klien/Teknisi/Admin) karena middleware 'peran:...' membandingkannya.
        $klaim = [
            'name' => $akun->nama,
            'role' => $akun->peranToken(),
            'instansiCode' => $akun->kode_instansi,
            'technician' => $akun->role === 'teknisi' ? $akun->nama : null,
        ];

        // Catat login terakhir (kolom pengguna.last_login).
        User::whereKey($akun->id)->update(['last_login' => Waktu::sekarang()]);

        $berlaku = config('disty.jwt.berlaku_detik');
        $token = Jwt::buat(['sub' => $akun->id, ...$klaim], config('disty.jwt.rahasia'), $berlaku);

        return response()->json(['data' => [
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresIn' => $berlaku,
            // Kontrak: tepat empat kunci ini, peran huruf kecil.
            'user' => [
                'role' => $akun->peranKontrak(),
                'name' => $akun->nama,
                'email' => $akun->email,
                'instansiCode' => $akun->kode_instansi,
            ],
        ]]);
    }

    /** POST /auth/logout : 204. Token JWT tidak disimpan di server, jadi tidak ada yang dicabut (lihat batasan di README);
     *  frontend menghapus tokennya sendiri. Endpoint ini ada karena ada di kontrak. */
    public function logout(): Response
    {
        return response()->noContent();
    }

    /** Siapa saya? Dibaca dari database supaya nama dan email terbaru (setelah PUT /me) ikut tampil. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->akun($request)->profil()]);
    }

    /** PUT /me : simpan profil. Body: { name, email, phone, photo }. Balas profil terbaru.
     *  photo: alamat dari POST /uploads, null = hapus foto, tidak dikirim = foto tidak berubah. */
    public function ubahProfil(Request $request): JsonResponse
    {
        $isi = $this->isi($request);
        $akun = $this->akun($request);

        $this->validasi($isi, [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'email' => ['bail', 'required', 'string', 'email', 'max:150'],
            'phone' => ['bail', 'nullable', 'string', 'max:20'],
        ], [
            'name.required' => 'Nama dan email yang valid wajib diisi',
            'name.string' => 'Nama dan email yang valid wajib diisi',
            'name.max' => 'Nama maksimal 100 karakter',
            'email.required' => 'Nama dan email yang valid wajib diisi',
            'email.string' => 'Nama dan email yang valid wajib diisi',
            'email.email' => 'Nama dan email yang valid wajib diisi',
            'email.max' => 'Email maksimal 150 karakter',
            'phone.string' => 'Nomor telepon harus berupa teks',
            'phone.max' => 'Nomor telepon maksimal 20 karakter',
        ]);

        $adaFoto = array_key_exists('photo', $isi);
        $foto = $adaFoto ? UrlFoto::periksa($isi['photo']) : null;

        $email = mb_strtolower(trim($isi['email']));
        if (User::where('email', $email)->where('id', '!=', $akun->id)->exists()) {
            throw new ApiException(409, 'EMAIL_DIPAKAI', 'Email itu sudah dipakai akun lain');
        }

        // Klien: domain email baru harus terdaftar. Hook User::updating() menolaknya dengan 422 INSTANSI_TIDAK_DIKENAL
        // dan sekaligus mengisi ulang instansi_id, jadi tidak perlu dicek lagi di sini.
        try {
            $perubahan = ['nama' => trim($isi['name']), 'email' => $email];
            if (array_key_exists('phone', $isi)) {
                $perubahan['telepon'] = trim((string) $isi['phone']);   // kolom telepon wajib terisi, kosong disimpan sebagai teks kosong
            }
            if ($adaFoto) {
                $perubahan['foto'] = $foto;                              // null = hapus foto
            }
            $akun->update($perubahan);
        } catch (QueryException $e) {
            // Dua permintaan bersamaan memakai email yang sama: yang kedua ditolak oleh UNIQUE di database.
            if ((string) $e->getCode() === '23000') {
                throw new ApiException(409, 'EMAIL_DIPAKAI', 'Email itu sudah dipakai akun lain');
            }
            throw $e;
        }

        $akun->load('instansi');   // email baru bisa memindahkan klien ke instansi lain, jadi muat ulang relasinya

        return response()->json(['data' => $akun->profil()]);
    }

    /** [MODUL 8] PUT /me/password : { currentPassword, newPassword } -> 204.
     *  422 bila kata sandi lama kosong atau yang baru lemah, 403 SANDI_SALAH bila kata sandi lama keliru.
     *  Kata sandi sengaja TIDAK di-trim (spasi bisa menjadi bagian kata sandi), jadi validasi() bawaan Controller tidak dipakai. */
    public function ubahSandi(Request $request): Response
    {
        $isi = $this->isi($request);
        $akun = $this->akun($request);

        $lama = is_string($isi['currentPassword'] ?? null) ? $isi['currentPassword'] : '';
        $baru = is_string($isi['newPassword'] ?? null) ? $isi['newPassword'] : '';

        if ($lama === '') {
            throw new ApiException(422, 'VALIDASI', 'Isi kata sandi saat ini');
        }
        if (! Hash::check($lama, $akun->password)) {
            throw new ApiException(403, 'SANDI_SALAH', 'Kata sandi saat ini salah');
        }
        if (strlen($baru) < 8 || ! preg_match('/[A-Za-z]/', $baru) || ! preg_match('/\d/', $baru)) {
            throw new ApiException(422, 'VALIDASI', 'Kata sandi baru minimal 8 karakter dan mengandung huruf serta angka');
        }
        if (strlen($baru) > 72) {   // batas bcrypt: karakter setelah ke-72 diabaikan
            throw new ApiException(422, 'VALIDASI', 'Kata sandi terlalu panjang (maksimal 72 karakter)');
        }
        if ($baru === $lama) {
            throw new ApiException(422, 'VALIDASI', 'Kata sandi baru harus berbeda dari yang lama');
        }

        $akun->update(['password' => Hash::make($baru)]);

        return response()->noContent();
    }

    /** POST /auth/activation/request : { email } -> 202 { message, instansiCode, expiresInSeconds }.
     *  Akun yang masih nonaktif mendapat kode aktivasi 8 karakter (kolom pengguna.kode_aktivasi), berlaku KODE_AKTIVASI_DETIK
     *  dihitung dari updated_at. Pengiriman email belum dipasang: kode dicatat di storage/logs/laravel.log (lihat README).
     *  Balasan sama untuk email yang terdaftar maupun tidak, supaya keberadaan akun tidak bocor. */
    public function mintaKodeAktivasi(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, [
            'email' => ['bail', 'required', 'string', 'email'],
        ], [
            'email.required' => 'Isi email yang valid dulu',
            'email.string' => 'Isi email yang valid dulu',
            'email.email' => 'Isi email yang valid dulu',
        ]);

        $email = mb_strtolower(trim($isi['email']));
        $akun = User::where('email', $email)->where('status', 'nonaktif')->first();
        if ($akun) {
            $kode = strtoupper(Str::random(8));
            $akun->update(['kode_aktivasi' => $kode]);
            Log::info("Kode aktivasi untuk {$email}: {$kode}");
        }

        return response()->json(['data' => [
            'message' => 'Kode aktivasi dikirim',
            'instansiCode' => Instansi::kodeDariEmail($email),
            'expiresInSeconds' => self::KODE_AKTIVASI_DETIK,
        ]], 202);
    }

    /** POST /auth/activation/verify : { email, code } -> 200 { activated: true }. Kode salah atau kedaluwarsa -> 422 KODE_SALAH.
     *  Berhasil: status akun menjadi aktif dan kode dihapus (sekali pakai). */
    public function verifikasiKodeAktivasi(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, [
            'email' => ['bail', 'required', 'string', 'email'],
            'code' => ['bail', 'required', 'string', 'min:4', 'max:8'],
        ], [
            'email.required' => 'Isi email yang valid dulu',
            'email.string' => 'Isi email yang valid dulu',
            'email.email' => 'Isi email yang valid dulu',
            'code.required' => 'Isi kode aktivasi dari email',
            'code.string' => 'Isi kode aktivasi dari email',
            'code.min' => 'Isi kode aktivasi dari email',
            'code.max' => 'Kode aktivasi maksimal 8 karakter',
        ]);

        $akun = User::where('email', mb_strtolower(trim($isi['email'])))->where('status', 'nonaktif')->first();
        $kode = strtoupper(trim($isi['code']));
        $berlaku = $akun && $akun->kode_aktivasi !== null
            && hash_equals((string) $akun->kode_aktivasi, $kode)
            && $akun->updated_at !== null
            && $akun->updated_at->gte(Waktu::sekarang()->subSeconds(self::KODE_AKTIVASI_DETIK));

        if (! $berlaku) {
            throw new ApiException(422, 'KODE_SALAH', 'Kode aktivasi salah atau sudah kedaluwarsa');
        }

        $akun->update(['status' => 'aktif', 'kode_aktivasi' => null]);

        return response()->json(['data' => ['activated' => true]]);
    }
}
