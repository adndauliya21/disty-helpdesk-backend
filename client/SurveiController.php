<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Survei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* SURVEI KEPUASAN (tabel survei) (layar Survei milik Klien).
     GET /surveys/me   semua peran  -> 200 { data: { overall, aspects, resolved, comment, submittedAt } }
                                       atau { data: null } bila belum mengisi, atau bila akun bukan Klien
     PUT /surveys/me   Klien        { overall, aspects, resolved, comment } -> 200 survei tersimpan | 422
   Satu survei per akun: mengirim ulang menimpa survei lama.
   Pesan galat "Pilih penilaian keseluruhan dulu" sama dengan server tiruan (js/29-api-server-tiruan.js), tampil sebagai toast.
   Server tiruan hanya memeriksa `overall`. Di sini isian lain juga diperiksa bentuknya (tiga angka 0 sampai 5, teks) supaya
   kolom database tidak diisi nilai aneh. Frontend asli selalu mengirim bentuk yang benar, jadi tampilan tidak terpengaruh. */
class SurveiController extends Controller
{
    private const MAKS_RESOLVED = 30;

    private const MAKS_KOMENTAR = 2000;

    /** GET /surveys/me */
    public function lihat(Request $request): JsonResponse
    {
        $pengguna = $this->pengguna($request);
        $survei = $pengguna['role'] === 'Klien' ?Survei::terakhirDari((int) $pengguna['id']) : null;

        return response()->json(['data' => $survei?->bentuk()]);
    }

    /** PUT /surveys/me */
    public function simpan(Request $request): JsonResponse
    {
        $isi = $this->isi($request);                       // 400 bila bukan objek JSON
        $akun = $this->akun($request);                     // 401 bila akun sudah dihapus

        if (! $this->bilanganBulat($isi['overall'] ?? null, 1, 5)) {
            throw new ApiException(422, 'VALIDASI', 'Pilih penilaian keseluruhan dulu');
        }

        // aspects tidak diisi (atau kosong) -> [0, 0, 0] (belum dinilai), sama dengan `body.aspects || [0, 0, 0]` di server tiruan.
        $aspek = $isi['aspects'] ?? null;
        if ($aspek === null || $aspek === [] || $aspek === false) {
            $aspek = [0, 0, 0];
        }
        if (! is_array($aspek) || ! array_is_list($aspek) || count($aspek) !== 3) {
            throw new ApiException(422, 'VALIDASI', 'Nilai aspek harus tiga bilangan bulat 0 sampai 5');
        }
        foreach ($aspek as $nilai) {
            if (! $this->bilanganBulat($nilai, 0, 5)) {
                throw new ApiException(422, 'VALIDASI', 'Nilai aspek harus tiga bilangan bulat 0 sampai 5');
            }
        }

        $pilihan = $this->teks($isi['resolved'] ?? null, self::MAKS_RESOLVED, 'Pilihan terselesaikan tidak valid');
        $terselesaikan = Survei::dariPilihan($pilihan);
        if ($terselesaikan === null) {
            throw new ApiException(422, 'VALIDASI', 'Pilihan terselesaikan tidak valid');
        }
        $komentar = $this->teks($isi['comment'] ?? null, self::MAKS_KOMENTAR, 'Komentar maksimal '.self::MAKS_KOMENTAR.' karakter');

        // Satu survei terkini per klien: kirim ulang memperbarui baris terakhir (tabel survei sendiri mengizinkan banyak baris).
        $survei = Survei::terakhirDari($akun->id) ?? new Survei(['pengguna_id' => $akun->id]);
        $survei->fill([
            'nilai_keseluruhan' => (int) $isi['overall'],
            'nilai_respon' => (int) $aspek[0],
            'nilai_kemudahan' => (int) $aspek[1],
            'nilai_kualitas' => (int) $aspek[2],
            'terselesaikan' => $terselesaikan,
            'komentar' => $komentar === '' ? null : $komentar,
        ])->save();
        $survei->touch();   // submittedAt = updated_at, juga bila isinya persis sama dengan kiriman sebelumnya

        return response()->json(['data' => $survei->bentuk()]);
    }

    /** Bilangan bulat sungguhan (bukan teks "4" atau true) dalam rentang. 4.0 dari JSON dianggap 4, sama dengan Number.isInteger di JavaScript. */
    private function bilanganBulat(mixed $nilai, int $min, int $maks): bool
    {
        if (is_float($nilai) && floor($nilai) === $nilai) {
            $nilai = (int) $nilai;
        }

        return is_int($nilai) && $nilai >= $min && $nilai <= $maks;
    }

    /** Teks opsional: tidak diisi atau null -> teks kosong. Bukan teks atau terlalu panjang -> 422. Spasi di tepi dibuang. */
    private function teks(mixed $nilai, int $maks, string $pesan): string
    {
        if ($nilai === null) {
            return '';
        }
        if (! is_string($nilai) || mb_strlen(trim($nilai)) > $maks) {
            throw new ApiException(422, 'VALIDASI', $pesan);
        }

        return trim($nilai);
    }
}
