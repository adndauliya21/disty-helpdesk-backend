<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Support\KuotaKonsultasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* KUOTA JADWAL KONSULTASI (tabel kuota_konsultasi) (layar Konsultasi > Kuota Jadwal milik Admin, dan pilihan jam milik Klien).
     GET /consultation-quota   semua peran   -> 200 { data: { defaults, overrides } }
     PUT /consultation-quota   Admin         { defaults, overrides }  mengganti seluruh kuota -> 200 | 422
   Pesan galat sama dengan server tiruan: "Kuota harus bilangan bulat 0 sampai 20". */
class KuotaKonsultasiController extends Controller
{
    private const PESAN_KUOTA = 'Kuota harus bilangan bulat 0 sampai 20';

    /** GET /consultation-quota */
    public function lihat(): JsonResponse
    {
        return response()->json(['data' => KuotaKonsultasi::semua()]);
    }

    /** PUT /consultation-quota : defaults wajib memuat keenam jam, overrides boleh kosong. */
    public function simpan(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $defaults = $isi['defaults'] ?? [];
        $overrides = $isi['overrides'] ?? [];
        if (! is_array($defaults) || ! is_array($overrides)) {
            throw new ApiException(422, 'VALIDASI', self::PESAN_KUOTA);
        }

        foreach (KuotaKonsultasi::JAM as $jam) {
            if (! KuotaKonsultasi::kuotaValid($defaults[$jam] ?? null)) {
                throw new ApiException(422, 'VALIDASI', self::PESAN_KUOTA);
            }
        }
        foreach ($overrides as $kunci => $kuota) {
            if (! KuotaKonsultasi::kuotaValid($kuota)) {
                throw new ApiException(422, 'VALIDASI', self::PESAN_KUOTA);
            }
            if (! KuotaKonsultasi::kunciValid($kunci)) {
                throw new ApiException(422, 'VALIDASI', 'Kuota khusus harus memakai kunci TTTT-BB-HH|JJ.MM, contoh 2026-10-08|10.00');
            }
        }

        KuotaKonsultasi::simpan($defaults, $overrides, (int) $this->pengguna($request)['id']);

        return response()->json(['data' => KuotaKonsultasi::semua()]);
    }
}
