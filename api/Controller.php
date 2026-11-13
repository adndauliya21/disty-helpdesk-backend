<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use stdClass;

/* [MODUL 6] CONTROLLER DASAR: helper yang dipakai semua controller (padanan src/controllers/base-controller.js
   dan Validator di src/support/validator.js). */
abstract class Controller
{
    private const BATAS_BODY_BYTE = 1_000_000;   // 1 MB, sama dengan batasBodyByte di Modul 3

    /** Isi body sebagai array. Body kosong -> []. Bukan objek JSON -> 400 JSON_RUSAK. */
    protected function isi(Request $request): array
    {
        $teks = trim($request->getContent());
        if ($teks === '') {
            return [];
        }
        if (strlen($teks) > self::BATAS_BODY_BYTE) {
            throw new ApiException(413, 'BODY_TERLALU_BESAR', 'Data yang dikirim terlalu besar');
        }

        // Objek JSON didekode jadi stdClass, sedangkan array/angka/teks bukan: hanya objek yang diterima.
        if (! (json_decode($teks) instanceof stdClass)) {
            throw new ApiException(400, 'JSON_RUSAK', 'Format JSON yang dikirim tidak valid');
        }

        return json_decode($teks, true);
    }

    /** Validasi ala Laravel. Semua pelanggaran dikumpulkan; pesan pertama jadi message (tampil sebagai toast di frontend),
     *  seluruh daftar (satu pesan per field) masuk ke details. Gagal -> 422 VALIDASI. */
    protected function validasi(array $data, array $aturan, array $pesan): void
    {
        // Teks diperiksa setelah di-trim (spasi di tepi tidak dihitung), sama dengan Validator.maks() di Modul 3.
        $data = array_map(fn ($nilai) => is_string($nilai) ? trim($nilai) : $nilai, $data);

        $validator = Validator::make($data, $aturan, $pesan);
        if (! $validator->fails()) {
            return;
        }

        $galat = array_map(fn (array $daftar) => $daftar[0], $validator->errors()->messages());
        throw new ApiException(422, 'VALIDASI', reset($galat), $galat);
    }

    /** Baca query string berisi nilai enum. Tidak diisi -> null. Diisi tapi salah -> 422. */
    protected function enumDariQuery(Request $request, string $nama, array $daftar, string $pesan): ?string
    {
        $nilai = $request->query($nama);
        $nilai = is_string($nilai) ? trim($nilai) : '';
        if ($nilai === '') {
            return null;
        }
        if (! in_array($nilai, $daftar, true)) {
            throw new ApiException(422, 'VALIDASI', $pesan);
        }

        return $nilai;
    }

    /** Data pengguna dari token (diisi middleware 'jwt'): id, name, role, instansiCode, technician. */
    protected function pengguna(Request $request): array
    {
        return $request->attributes->get('pengguna');
    }

    /** [MODUL 8] Akun lengkap dari database (nama dan email terbaru). Token yang akunnya sudah dihapus -> 401. */
    protected function akun(Request $request): User
    {
        $akun = User::with('instansi')->find($this->pengguna($request)['id']);
        if (! $akun) {
            throw new ApiException(401, 'TOKEN_TIDAK_VALID', 'Token tidak valid');
        }

        return $akun;
    }

    protected function tidakDitemukan(string $apa): never
    {
        throw new ApiException(404, 'TIDAK_DITEMUKAN', "{$apa} tidak ditemukan");
    }
}
