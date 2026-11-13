<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\ArtikelBantuan;
use App\Models\Instansi;
use App\Models\Survei;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/* DATA REFERENSI (hanya baca, semua peran yang sudah login).
     GET /knowledge-base   -> 200 { data: { "Database": ["judul", ...], ... } }          dari tabel artikel_bantuan
     GET /solutions        -> 200 { data: [] }                                          lihat solusi() di bawah
     GET /institutions     -> 200 { data: { "SMKN2-SBY": { n, dom, p: [ ...orang ] }, ... } }   dari tabel instansi dan pengguna */
class ReferensiController extends Controller
{
    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** GET /knowledge-base */
    public function basisPengetahuan(): JsonResponse
    {
        return response()->json(['data' => ArtikelBantuan::dikelompokkan()]);
    }

    /** GET /knowledge-base/{judul} : isi lengkap satu artikel dari tabel artikel_bantuan (tanpa tabel baru). */
    public function artikel(string $judul): JsonResponse
    {
        $artikel = ArtikelBantuan::query()->with('modul')->where('judul', $judul)->first();
        if (! $artikel) {
            throw new ApiException(404, 'TIDAK_DITEMUKAN', 'Artikel tidak ditemukan');
        }

        return response()->json(['data' => ['title' => $artikel->judul, 'module' => $artikel->modul->nama_modul, 'content' => $artikel->isi]]);
    }

    /** GET /solutions : arsip solusi lama. ERD tidak punya tabel arsip: solusi penanganan sekarang ada di tabel solusi_aduan
     *  dan sudah ikut di setiap aduan (field solution di GET /tickets). Frontend menggabungkan keduanya, jadi di sini sengaja
     *  kosong supaya solusi tidak tampil dobel. */
    public function solusi(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /** GET /institutions : instansi dan pelapor (klien) terdaftar, disusun dari database.
     *  Bentuk sama dengan INST di frontend. Riwayat aduan dan konsultasi per pelapor tidak ikut dikirim (h dan k kosong),
     *  karena frontend menggabungkannya sendiri dari GET /tickets dan GET /consultations. */
    public function instansi(): JsonResponse
    {
        $klien = User::where('role', 'klien')->orderBy('id')->get()->groupBy('instansi_id');
        $survei = Survei::with('aduan:id,kode_aduan')->whereIn('pengguna_id', $klien->flatten()->pluck('id'))
            ->orderBy('id')->get()->groupBy('pengguna_id');

        $hasil = [];
        foreach (Instansi::orderBy('id')->get() as $instansi) {
            $hasil[$instansi->kode_instansi] = [
                'n' => $instansi->nama_instansi,
                'dom' => $instansi->domain_email,
                'p' => ($klien[$instansi->id] ?? collect())->map(fn (User $k) => [
                    'n' => $k->namaPelapor(),
                    'j' => 'Klien',
                    'e' => $k->email,
                    'tel' => (string) $k->telepon,
                    'g' => $this->tanggal($k->created_at),
                    'login' => $k->last_login ? $this->tanggal($k->last_login).', '.$k->last_login->copy()->setTimezone('Asia/Jakarta')->format('H.i') : '-',
                    'last' => $k->last_login ? $this->tanggal($k->last_login) : '-',
                    'h' => [],
                    'k' => [],
                    'v' => ($survei[$k->id] ?? collect())->map(fn (Survei $s) => [
                        $s->aduan?->kode_aduan ?? '-', $s->nilai_keseluruhan, (string) $s->komentar,
                    ])->values()->all(),
                    'act' => [],
                ])->values()->all(),
            ];
        }

        return response()->json(['data' => (object) $hasil]);
    }

    /** 12 Agu 2026 (waktu Indonesia barat), sama dengan fmtTanggal() di frontend. */
    private function tanggal(?\DateTimeInterface $waktu): string
    {
        if ($waktu === null) {
            return '-';
        }
        $lokal = \Illuminate\Support\Carbon::instance($waktu)->setTimezone('Asia/Jakarta');

        return $lokal->day.' '.self::BULAN[$lokal->month - 1].' '.$lokal->year;
    }
}
