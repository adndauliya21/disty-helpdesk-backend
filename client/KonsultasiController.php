<?php

namespace App\Http\Controllers;

use App\Enums\StatusKonsultasi;
use App\Exceptions\ApiException;
use App\Http\Resources\ConsultationResource;
use App\Models\Konsultasi;
use App\Models\User;
use App\Support\KuotaKonsultasi;
use App\Support\Waktu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/* KONSULTASI (tabel konsultasi, pesan_konsultasi, riwayat_konsultasi, kuota_konsultasi): lima endpoint di docs/API-KONTRAK.md.
   Aturan, kode status, kode error, dan pesan mengikuti kontrak frontend.

     GET   /consultations                     semua peran   daftar konsultasi, terbaru di depan
     POST  /consultations                     Klien         { topic, note, date, time }  -> 201, 409 KUOTA_PENUH
     PATCH /consultations/{id}                Admin         { status }                   Terjadwal butuh teknisi (409 TEKNISI_KOSONG)
     PUT   /consultations/{id}/technician     Admin         { technician }
     PUT   /consultations/{id}/schedule       Admin         { date, time, reason }       ubah jadwal; 422 bila jam/alasan kosong atau waktu sudah lewat, 409 KUOTA_PENUH
     POST  /consultations/{id}/messages       Admin         { text }                     -> 201

   Semua mengubah data membalas konsultasi lengkap ({ data: Konsultasi }). Urutan pemeriksaan sama dengan server tiruan:
   body JSON diparse dulu (400), lalu 404 data, lalu validasi isian (422), lalu aturan bisnis (409).
   Setiap perubahan dikerjakan dalam satu transaksi dengan baris konsultasi dikunci (lockForUpdate), jadi pesan dan riwayat
   tidak setengah tersimpan. Kuota jam dijaga dengan mengunci baris konsultasi yang memakai jam itu (lockForUpdate). */
class KonsultasiController extends Controller
{
    /** Keputusan E di CATATAN-HANDOFF-MODUL8.md: Klien hanya melihat konsultasi instansinya (sama dengan aturan aduan).
     *  Ubah ke false bila sistem analis memutuskan Klien melihat semua konsultasi, sesuai tulisan "semua" di kontrak. */
    private const KLIEN_HANYA_INSTANSINYA = true;

    /** GET /consultations */
    public function index(Request $request): AnonymousResourceCollection
    {
        $batas = $this->batasInstansi($request);

        $daftar = Konsultasi::lengkap()
            ->when($batas !== null, fn ($q) => $q->whereHas('pengaju', fn ($k) => $k->diInstansi($batas)))
            ->terbaruDulu()
            ->get();

        return ConsultationResource::collection($daftar);
    }

    /** POST /consultations : klien mengajukan jadwal. Pelapor dan instansi dari akun, bukan dari body. */
    public function store(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, [
            'topic' => ['bail', 'required', 'string', 'max:150'],
            'note' => ['bail', 'nullable', 'string', 'max:5000'],
            'date' => ['bail', 'required', 'string'],
        ], [
            'topic.required' => 'Isi topik dulu',
            'topic.string' => 'Isi topik dulu',
            'topic.max' => 'Topik maksimal 150 karakter',
            'note.string' => 'Catatan harus berupa teks',
            'note.max' => 'Catatan maksimal 5.000 karakter',
            'date.required' => 'Pilih tanggal dulu',
            'date.string' => 'Pilih tanggal dulu',
        ]);

        $tanggal = trim($isi['date']);
        if (! $this->tanggalValid($tanggal)) {
            throw new ApiException(422, 'VALIDASI', 'Tanggal harus berformat TTTT-BB-HH');
        }
        $jam = is_string($isi['time'] ?? null) ? trim($isi['time']) : '';
        $this->pastikanBelumLewat($tanggal, $jam);
        if ($jam === '') {
            throw new ApiException(422, 'VALIDASI', 'Jam wajib diisi');
        }
        $akun = $this->akun($request);

        $konsultasi = DB::transaction(function () use ($isi, $tanggal, $jam, $akun) {
            // Satu jam, satu antrean: baris konsultasi jam itu dikunci, jadi dua permintaan bersamaan tidak bisa sama-sama lolos.
            $kuota = $jam === '' ? null : KuotaKonsultasi::kuotaJam($jam, $tanggal);
            if ($kuota === null || Konsultasi::memakaiKuota($tanggal, $jam)->lockForUpdate()->count() >= $kuota) {
                throw new ApiException(409, 'KUOTA_PENUH', 'Pilih jam yang masih tersedia');
            }

            $topik = trim($isi['topic']);
            $catatan = trim((string) ($isi['note'] ?? ''));

            $konsultasi = Konsultasi::create([
                'pengaju_id' => $akun->id,
                'topik' => $topik,
                'catatan' => $catatan === '' ? null : $catatan,
                'tanggal' => $tanggal,
                'jam' => Konsultasi::jamDb($jam),
                'status' => StatusKonsultasi::Menunggu->value,
                'jumlah_ubah_jadwal' => 0,
            ]);
            $konsultasi->pesan()->create([
                'pengirim_id' => $akun->id,
                'pesan' => $topik.($catatan !== '' ? '. '.$catatan : ''),
            ]);
            $konsultasi->catat('Pengguna mengajukan konsultasi');

            return $konsultasi->load(Konsultasi::RELASI);
        });

        return (new ConsultationResource($konsultasi))->response()->setStatusCode(201);
    }

    /** PATCH /consultations/{id} : Admin mengubah status. Terjadwal hanya boleh bila teknisi sudah ditugaskan. */
    public function ubahStatus(Request $request, string $id): ConsultationResource
    {
        $isi = $this->isi($request);
        return new ConsultationResource(DB::transaction(function () use ($id, $isi) {
            $konsultasi = $this->ambil($id);

            $status = is_string($isi['status'] ?? null) ? trim($isi['status']) : '';
            if (! in_array($status, StatusKonsultasi::dariAdmin(), true)) {
                throw new ApiException(422, 'VALIDASI', 'Status tidak valid');
            }
            if ($status === StatusKonsultasi::Terjadwal->value && $konsultasi->teknisi_id === null) {
                throw new ApiException(409, 'TEKNISI_KOSONG', 'Tugaskan teknisi dulu sebelum menyetujui');
            }

            $konsultasi->update(['status' => $status]);
            $konsultasi->catat("Admin mengubah status menjadi {$status}");

            return $konsultasi->load(Konsultasi::RELASI);
        }));
    }

    /** PUT /consultations/{id}/technician : Admin menugaskan satu teknisi (boleh diganti). */
    public function tugaskan(Request $request, string $id): ConsultationResource
    {
        $isi = $this->isi($request);

        return new ConsultationResource(DB::transaction(function () use ($id, $isi) {
            $konsultasi = $this->ambil($id);

            $nama = is_string($isi['technician'] ?? null) ? trim($isi['technician']) : '';
            if ($nama === '') {
                throw new ApiException(422, 'VALIDASI', 'Pilih teknisi dulu');
            }
            $teknisi = User::teknisiBernama($nama)->first();
            if (! $teknisi) {
                $this->tidakDitemukan('Teknisi');
            }

            $konsultasi->update(['teknisi_id' => $teknisi->id]);
            $konsultasi->catat("Admin menugaskan {$teknisi->nama} sebagai teknisi konsultasi");

            return $konsultasi->load(Konsultasi::RELASI);
        }));
    }

    /** PUT /consultations/{id}/schedule : Admin mengubah jadwal. Alasan wajib; tanggal harus hari ini atau setelahnya (WIB) dan
     *  jam hari ini yang sudah lewat ditolak. Klien melihat jadwal baru lewat konsultasi itu sendiri dan lewat pesan berisi alasan. */
    public function ubahJadwal(Request $request, string $id): ConsultationResource
    {
        $isi = $this->isi($request);
        $pengirim = $this->pengguna($request)['id'];

        return new ConsultationResource(DB::transaction(function () use ($id, $isi, $pengirim) {
            $konsultasi = $this->ambil($id);

            $tanggal = is_string($isi['date'] ?? null) ? trim($isi['date']) : '';
            $jam = is_string($isi['time'] ?? null) ? trim($isi['time']) : '';
            $alasan = is_string($isi['reason'] ?? null) ? trim($isi['reason']) : '';

            if ($tanggal === '') {
                throw new ApiException(422, 'VALIDASI', 'Pilih tanggal dulu');
            }
            if (! $this->tanggalValid($tanggal)) {
                throw new ApiException(422, 'VALIDASI', 'Tanggal harus berformat TTTT-BB-HH');
            }
            if ($jam === '') {
                throw new ApiException(422, 'VALIDASI', 'Jam wajib diisi');
            }
            if ($alasan === '') {
                throw new ApiException(422, 'VALIDASI', 'Alasan wajib diisi');
            }
            if (mb_strlen($alasan) > 1000) {
                throw new ApiException(422, 'VALIDASI', 'Alasan maksimal 1.000 karakter');
            }
            $this->pastikanBelumLewat($tanggal, $jam);

            if (! in_array($konsultasi->status, [StatusKonsultasi::Menunggu->value, StatusKonsultasi::Terjadwal->value], true)) {
                throw new ApiException(409, 'STATUS_TIDAK_VALID', "Konsultasi berstatus {$konsultasi->status} tidak bisa diubah jadwalnya");
            }
            if (substr((string) $konsultasi->tanggal, 0, 10) === $tanggal && $konsultasi->jamTampil() === $jam) {
                throw new ApiException(422, 'VALIDASI', 'Jadwal baru harus berbeda dari jadwal sekarang');
            }

            // Kuota dihitung tanpa konsultasi ini sendiri, dan baris jam itu dikunci supaya dua perubahan bersamaan tidak sama-sama lolos.
            $kuota = KuotaKonsultasi::kuotaJam($jam, $tanggal);
            if ($kuota === null || Konsultasi::memakaiKuota($tanggal, $jam)->where('id', '!=', $konsultasi->id)->lockForUpdate()->count() >= $kuota) {
                throw new ApiException(409, 'KUOTA_PENUH', 'Pilih jam yang masih tersedia');
            }

            $konsultasi->update([
                'tanggal' => $tanggal,
                'jam' => Konsultasi::jamDb($jam),
                'jumlah_ubah_jadwal' => min(127, (int) $konsultasi->jumlah_ubah_jadwal + 1),
            ]);

            $baru = $this->tanggalIndonesia($tanggal).', '.$jam;
            $konsultasi->pesan()->create([
                'pengirim_id' => $pengirim,
                'pesan' => "Jadwal konsultasi diubah ke {$baru}. Alasan: {$alasan}",
            ]);
            $konsultasi->catat("Admin mengubah jadwal ke {$baru}. Alasan: {$alasan}");

            return $konsultasi->load(Konsultasi::RELASI);
        }));
    }

    /** POST /consultations/{id}/messages : Admin membalas. 201 + konsultasi terbaru. */
    public function balas(Request $request, string $id): JsonResponse
    {
        $isi = $this->isi($request);
        $pengirim = $this->pengguna($request)['id'];
        $konsultasi = DB::transaction(function () use ($id, $isi, $pengirim) {
            $konsultasi = $this->ambil($id);

            $teks = is_scalar($isi['text'] ?? null) ? trim((string) $isi['text']) : '';
            if ($teks === '') {
                throw new ApiException(422, 'VALIDASI', 'Tulis balasan dulu');
            }
            if (mb_strlen($teks) > 5000) {
                throw new ApiException(422, 'VALIDASI', 'Balasan maksimal 5.000 karakter');
            }

            $konsultasi->pesan()->create(['pengirim_id' => $pengirim, 'pesan' => $teks]);
            // Riwayat memotong balasan panjang di 28 karakter (sama dengan server tiruan).
            $ringkas = mb_strlen($teks) > 28 ? mb_substr($teks, 0, 28).'…' : $teks;
            $konsultasi->catat("Admin membalas: {$ringkas}");

            return $konsultasi->load(Konsultasi::RELASI);
        });

        return (new ConsultationResource($konsultasi))->response()->setStatusCode(201);
    }

    // ---- Pembantu ----

    /** Ambil konsultasi (dikunci selama transaksi). id yang bukan angka dianggap tidak ada. */
    private function ambil(string $id): Konsultasi
    {
        $konsultasi = ctype_digit($id) ? Konsultasi::lengkap()->lockForUpdate()->find((int) $id) : null;
        if (! $konsultasi) {
            $this->tidakDitemukan('Konsultasi');
        }

        return $konsultasi;
    }

    /** Klien dibatasi ke instansinya sendiri (lihat KLIEN_HANYA_INSTANSINYA), peran lain tidak dibatasi (null = semua). */
    private function batasInstansi(Request $request): ?string
    {
        $pengguna = $this->pengguna($request);

        return self::KLIEN_HANYA_INSTANSINYA && $pengguna['role'] === 'Klien' ? $pengguna['instansiCode'] : null;
    }

    /** Jadwal hanya untuk hari ini (WIB) atau setelahnya. Jam hari ini yang sudah lewat juga ditolak (jam yang bukan jam konsultasi
     *  dibiarkan, karena itu dijawab 409 KUOTA_PENUH oleh pemeriksaan kuota). */
    private function pastikanBelumLewat(string $tanggal, string $jam): void
    {
        $sekarang = Waktu::sekarangWib();

        if ($tanggal < $sekarang->format('Y-m-d')) {
            throw new ApiException(422, 'VALIDASI', 'Tanggal harus hari ini atau setelahnya');
        }
        if ($tanggal === $sekarang->format('Y-m-d') && in_array($jam, KuotaKonsultasi::JAM, true)
            && Konsultasi::jamDb($jam) <= $sekarang->format('H:i')) {
            throw new ApiException(422, 'VALIDASI', 'Jam sudah lewat, pilih jam yang masih tersedia');
        }
    }

    /** 2026-12-05 menjadi "5 Des 2026" (sama dengan format tanggal di frontend). */
    private function tanggalIndonesia(string $tanggal): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        [$th, $bl, $tg] = array_map('intval', explode('-', $tanggal));

        return $tg.' '.$bulan[$bl - 1].' '.$th;
    }

    /** Tanggal harus benar-benar ada di kalender: 2026-02-30 ditolak. */
    private function tanggalValid(string $tanggal): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return false;
        }

        return Carbon::createFromFormat('!Y-m-d', $tanggal)?->format('Y-m-d') === $tanggal;
    }
}
