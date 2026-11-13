<?php

namespace App\Http\Controllers;

use App\Enums\Prioritas;
use App\Enums\StatusAduan;
use App\Exceptions\ApiException;
use App\Http\Resources\TicketResource;
use App\Models\Aduan;
use App\Models\PenugasanTeknisi;
use App\Models\SolusiAduan;
use App\Models\User;
use App\Support\UrlFoto;
use App\Support\Waktu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* ALUR ADUAN (tabel aduan, penugasan_teknisi, riwayat_aduan, solusi_aduan). Aturan, kode status, kode error, dan pesan
   mengikuti kontrak frontend (docs/API-KONTRAK.md).

     PATCH /tickets/{id}              Admin (priority) atau Teknisi (status, note opsional; urutan Baru -> Diproses -> Monitoring -> Selesai)
     POST  /tickets/{id}/assignments  Admin     { technician }               teknisi pertama = utama, berikutnya = pendamping
     POST  /tickets/{id}/logs         Teknisi   { message }                -> 201
     PUT   /tickets/{id}/solution     Teknisi   { part, summary, handledBy[] }  tulis solusi_aduan sekaligus menandai Selesai (hanya dari status Monitoring)
     PUT   /tickets/{id}/verification Admin     { approve, note? }           aduan telepon: setujui laporan (Selesai) atau kembalikan ke teknisi
     PUT   /tickets/{id}/rating       Klien     { score 1..5 }               hanya aduan Selesai
     PUT   /tickets/{id}/photos       Teknisi   { before?, after? }          kolom foto_sebelum / foto_sesudah, null = hapus

   Semua membalas aduan lengkap ({ data: Ticket }). Setiap perubahan dikerjakan dalam satu transaksi dengan baris aduan dikunci
   (lockForUpdate), jadi dua permintaan bersamaan tidak saling menimpa dan riwayat tidak setengah tersimpan. */
class AlurAduanController extends Controller
{
    private const RELASI = Aduan::RELASI;

    /** Kunci JSON foto -> kolom di tabel aduan. */
    private const KOLOM_FOTO = ['before' => 'foto_sebelum', 'after' => 'foto_sesudah'];

    /** PATCH /tickets/{id} : { priority } oleh Admin, atau { status } oleh Teknisi. Pemeriksaan dulu semua, baru ubah. */
    public function ubah(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);
            $prioritas = null;
            $status = null;
            $catatan = '';

            if (array_key_exists('priority', $isi)) {
                if ($pengguna['role'] !== 'Admin') {
                    throw new ApiException(403, 'DILARANG', 'Hanya admin yang boleh menetapkan prioritas');
                }
                $prioritas = is_string($isi['priority']) ? trim($isi['priority']) : '';
                if (! in_array($prioritas, Prioritas::nilai(), true)) {
                    throw new ApiException(422, 'VALIDASI', 'Prioritas tidak valid');
                }
            }

            if (array_key_exists('status', $isi)) {
                if ($pengguna['role'] !== 'Teknisi') {
                    throw new ApiException(403, 'DILARANG', 'Hanya teknisi yang boleh mengubah status');
                }
                $status = is_string($isi['status']) ? trim($isi['status']) : '';
                if (! in_array($status, StatusAduan::nilai(), true)) {
                    throw new ApiException(422, 'VALIDASI', 'Status tidak valid');
                }
                $this->pastikanUrutan($aduan, $status);
                if ($status === StatusAduan::Selesai->value && $aduan->lewatTelepon()) {
                    throw new ApiException(409, 'PERLU_VERIFIKASI_ADMIN', 'Aduan telepon baru selesai setelah laporan pengerjaannya disetujui admin');
                }
                if ($status === StatusAduan::Selesai->value && $aduan->solusi === null) {
                    throw new ApiException(409, 'SOLUSI_BELUM_ADA', 'Isi solusi penanganan dulu, lalu klik Tandai selesai');
                }
                $catatan = is_string($isi['note'] ?? null) ? trim($isi['note']) : '';
                if (mb_strlen($catatan) > 5000) {
                    throw new ApiException(422, 'VALIDASI', 'Catatan tahapan maksimal 5.000 karakter');
                }
            }

            if ($prioritas === null && $status === null) {
                throw new ApiException(422, 'VALIDASI', 'Isi prioritas atau status yang ingin diubah');
            }

            if ($prioritas !== null) {
                $aduan->update(['prioritas' => $prioritas]);
                $aduan->catat("Prioritas ditetapkan: {$prioritas}", (int) $pengguna['id']);
            }
            if ($status !== null) {
                if ($catatan !== '') {
                    $aduan->catat($catatan, (int) $pengguna['id']);   // catatan tahapan (opsional) dulu, lalu status
                }
                $aduan->update(['status' => $status]);
                $aduan->catat("Status diubah: {$status}", (int) $pengguna['id']);
            }

            return $aduan->load(self::RELASI);
        }));
    }

    /** POST /tickets/{id}/assignments : tugaskan teknisi. Teknisi pertama (peran utama) otomatis mengubah status jadi Diproses. */
    public function tugaskan(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);
            $nama = is_string($isi['technician'] ?? null) ? trim($isi['technician']) : '';
            if ($nama === '') {
                throw new ApiException(422, 'VALIDASI', 'Pilih teknisi dulu');
            }
            $teknisi = User::teknisiBernama($nama)->first();
            if (! $teknisi) {
                $this->tidakDitemukan('Teknisi');
            }
            if ($aduan->teknisi->contains('id', $teknisi->id)) {
                throw new ApiException(409, 'SUDAH_DITUGASKAN', "{$teknisi->nama} sudah ditugaskan pada aduan ini");
            }

            $pertama = $aduan->teknisi->isEmpty();
            PenugasanTeknisi::create([
                'aduan_id' => $aduan->id,
                'teknisi_id' => $teknisi->id,
                'peran' => $pertama ? 'utama' : 'pendamping',
            ]);
            if ($pertama) {
                $aduan->update(['status' => StatusAduan::Diproses->value]);
            }
            $aduan->catat(
                $pertama ? "Aduan diteruskan ke {$teknisi->nama}" : "{$teknisi->nama} ditambahkan sebagai teknisi pendamping",
                (int) $pengguna['id'],
            );

            return $aduan->load(self::RELASI);
        }));
    }

    /** POST /tickets/{id}/logs : catatan tindakan teknisi. 201 + aduan terbaru. */
    public function catatTindakan(Request $request, string $id): JsonResponse
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        $aduan = DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);

            $this->validasi($isi, [
                'message' => ['bail', 'required', 'string', 'max:5000'],
            ], [
                'message.required' => 'Isi tindakan dulu',
                'message.string' => 'Isi tindakan dulu',
                'message.max' => 'Catatan tindakan maksimal 5.000 karakter',
            ]);

            $aduan->catat(trim($isi['message']), (int) $pengguna['id']);

            return $aduan->load(self::RELASI);
        });

        return (new TicketResource($aduan))->response()->setStatusCode(201);
    }

    /** PUT /tickets/{id}/solution : simpan solusi (tabel solusi_aduan) dan tandai Selesai (dua catatan riwayat: solusi, lalu status).
     *  handledBy: teknisi yang menangani. Tabel solusi_aduan tidak punya kolom penangan, jadi penangan dicatat lewat
     *  penugasan_teknisi: teknisi yang belum ditugaskan ditambahkan sebagai pendamping, dan handledBy di JSON = semua teknisi aduan itu. */
    public function selesaikan(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);

            $this->validasi($isi, [
                'part' => ['bail', 'required', 'string', 'max:150'],
                'summary' => ['bail', 'required', 'string', 'max:5000'],
                'handledBy' => ['bail', 'required', 'array', 'min:1'],
                'handledBy.*' => ['bail', 'required', 'string', 'max:60'],
            ], [
                'part.required' => 'Pilih bagian yang diperbaiki',
                'part.string' => 'Pilih bagian yang diperbaiki',
                'part.max' => 'Bagian yang diperbaiki maksimal 150 karakter',
                'summary.required' => 'Isi solusi penanganan',
                'summary.string' => 'Isi solusi penanganan',
                'summary.max' => 'Solusi penanganan maksimal 5.000 karakter',
                'handledBy.required' => 'Pilih teknisi yang menangani',
                'handledBy.array' => 'Pilih teknisi yang menangani',
                'handledBy.min' => 'Pilih teknisi yang menangani',
                'handledBy.*.required' => 'Pilih teknisi yang menangani',
                'handledBy.*.string' => 'Pilih teknisi yang menangani',
                'handledBy.*.max' => 'Nama teknisi maksimal 60 karakter',
            ]);

            if ($aduan->status === StatusAduan::Selesai->value) {
                throw new ApiException(409, 'SUDAH_SELESAI', 'Aduan ini sudah selesai');
            }
            if ($aduan->menunggu_verifikasi) {
                throw new ApiException(409, 'MENUNGGU_VERIFIKASI', 'Laporan pengerjaan sudah dikirim dan sedang menunggu pemeriksaan admin');
            }
            $this->pastikanUrutan($aduan, StatusAduan::Selesai->value);

            // Cocokkan nama dari body ke teknisi di database (urutan sesuai body, tanpa duplikat).
            $diminta = array_values(array_unique(array_map(fn ($n) => trim($n), $isi['handledBy'])));
            $ada = User::teknisiBernamaDi($diminta)->get();
            $teknisi = [];
            $tidakDikenal = [];
            foreach ($diminta as $nama) {
                $cocok = $ada->first(fn ($t) => mb_strtolower($t->nama) === mb_strtolower($nama));
                if ($cocok) {
                    $teknisi[$cocok->id] = $cocok;
                } else {
                    $tidakDikenal[] = $nama;
                }
            }
            if ($tidakDikenal !== []) {
                throw new ApiException(422, 'VALIDASI', 'Teknisi tidak dikenal: '.implode(', ', $tidakDikenal));
            }

            // Penangan yang belum tercatat di penugasan_teknisi ditambahkan (utama bila aduan belum punya teknisi sama sekali).
            $sudahAda = $aduan->teknisi->pluck('id')->all();
            foreach ($teknisi as $idTeknisi => $_) {
                if (in_array($idTeknisi, $sudahAda, true)) {
                    continue;
                }
                PenugasanTeknisi::create([
                    'aduan_id' => $aduan->id,
                    'teknisi_id' => $idTeknisi,
                    'peran' => $sudahAda === [] ? 'utama' : 'pendamping',
                ]);
                $sudahAda[] = $idTeknisi;
            }
            $aduan->load('teknisi');

            $bagian = trim($isi['part']);
            $ringkasan = trim($isi['summary']);
            SolusiAduan::updateOrCreate(
                ['aduan_id' => $aduan->id],
                ['masalah' => $bagian, 'solusi' => $ringkasan, 'tanggal_selesai' => Waktu::sekarang()],
            );

            $nama = $aduan->teknisi->map(fn ($t) => $t->nama)->implode(', ');

            // Aduan telepon: Teknisi -> Admin -> Klien. Laporan dikirim ke admin dulu; status tetap Monitoring dan teks solusi
            // belum masuk riwayat (riwayat terlihat klien). Keduanya baru terjadi saat admin menyetujui (verifikasi()).
            if ($aduan->lewatTelepon()) {
                $aduan->update(['menunggu_verifikasi' => true]);
                $aduan->catat("Laporan pengerjaan dikirim ke admin untuk diperiksa (ditangani oleh {$nama})", (int) $pengguna['id']);

                return $aduan->load(self::RELASI);
            }

            $aduan->catat("Solusi: {$bagian}. {$ringkasan} (ditangani oleh {$nama})", (int) $pengguna['id']);
            $aduan->update(['status' => StatusAduan::Selesai->value]);
            $aduan->catat('Status diubah: Selesai', (int) $pengguna['id']);

            return $aduan->load(self::RELASI);
        }));
    }

    /** PUT /tickets/{id}/verification (Admin) : periksa laporan pengerjaan aduan telepon. Body { approve: true|false, note? }.
     *  approve = true  -> status Selesai, solusi masuk riwayat dan terlihat klien (Teknisi -> Admin -> Klien).
     *  approve = false -> dikembalikan ke teknisi (note wajib) untuk diperbaiki lalu dikirim ulang. */
    public function verifikasi(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);

            if (! is_bool($isi['approve'] ?? null)) {
                throw new ApiException(422, 'VALIDASI', 'Pilih setujui atau kembalikan');
            }
            $catatan = is_string($isi['note'] ?? null) ? trim($isi['note']) : '';
            if (mb_strlen($catatan) > 5000) {
                throw new ApiException(422, 'VALIDASI', 'Catatan maksimal 5.000 karakter');
            }
            if (! $aduan->lewatTelepon() || ! $aduan->menunggu_verifikasi || $aduan->solusi === null) {
                throw new ApiException(409, 'TIDAK_MENUNGGU_VERIFIKASI', 'Aduan ini tidak sedang menunggu pemeriksaan admin');
            }

            if ($isi['approve'] === false) {
                if ($catatan === '') {
                    throw new ApiException(422, 'VALIDASI', 'Tulis alasan pengembalian untuk teknisi');
                }
                $aduan->update(['menunggu_verifikasi' => false]);
                $aduan->catat("Admin mengembalikan laporan pengerjaan ke teknisi: {$catatan}", (int) $pengguna['id']);

                return $aduan->load(self::RELASI);
            }

            $solusi = $aduan->solusi;
            $nama = $aduan->teknisi->map(fn ($t) => $t->nama)->implode(', ');
            $penulis = (int) ($aduan->teknisi->first()?->id ?? $pengguna['id']);   // solusi ditulis teknisi, bukan admin

            $solusi->update(['tanggal_selesai' => Waktu::sekarang()]);
            $aduan->catat("Solusi: {$solusi->masalah}. {$solusi->solusi} (ditangani oleh {$nama})", $penulis);
            $aduan->catat($catatan === '' ? 'Admin menyetujui laporan pengerjaan dan meneruskannya ke klien' : "Admin menyetujui laporan pengerjaan dan meneruskannya ke klien: {$catatan}", (int) $pengguna['id']);
            $aduan->update(['status' => StatusAduan::Selesai->value, 'menunggu_verifikasi' => false]);
            $aduan->catat('Status diubah: Selesai', (int) $pengguna['id']);

            return $aduan->load(self::RELASI);
        }));
    }

    /** PUT /tickets/{id}/rating : Klien menilai aduan instansinya yang sudah Selesai. Boleh diganti selama masih Selesai. */
    public function beriNilai(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id, $pengguna['instansiCode']);   // aduan instansi lain dijawab 404, sama dengan GET /tickets/{id}

            if ($aduan->status !== StatusAduan::Selesai->value) {
                throw new ApiException(409, 'BELUM_SELESAI', 'Aduan yang belum selesai belum bisa dinilai');
            }

            $this->validasi($isi, [
                'score' => ['bail', 'required', 'integer', 'between:1,5'],
            ], [
                'score.required' => 'Nilai harus 1 sampai 5',
                'score.integer' => 'Nilai harus 1 sampai 5',
                'score.between' => 'Nilai harus 1 sampai 5',
            ]);

            $aduan->update(['rating' => (int) $isi['score']]);

            return $aduan->load(self::RELASI);
        }));
    }

    /** PUT /tickets/{id}/photos : foto dokumentasi perbaikan ke kolom aduan.foto_sebelum dan aduan.foto_sesudah.
     *  Body { before?, after? }: alamat dari POST /uploads, null = hapus. Hanya kunci yang dikirim yang berubah.
     *  Hanya teknisi yang ditugaskan pada aduan itu. Semua alamat diperiksa dulu, baru disimpan (tidak setengah tersimpan). */
    public function foto(Request $request, string $id): TicketResource
    {
        $isi = $this->isi($request);
        $pengguna = $this->pengguna($request);

        return new TicketResource(DB::transaction(function () use ($id, $isi, $pengguna) {
            $aduan = $this->ambil($id);

            if (! $aduan->teknisi->contains('id', (int) $pengguna['id'])) {
                throw new ApiException(403, 'DILARANG', 'Aduan ini bukan tugasmu');
            }

            $kunci = array_values(array_filter(array_keys(self::KOLOM_FOTO), fn ($k) => array_key_exists($k, $isi)));
            if ($kunci === []) {
                throw new ApiException(422, 'VALIDASI', 'Tidak ada foto yang diubah');
            }

            $ubah = [];
            foreach ($kunci as $k) {
                $ubah[self::KOLOM_FOTO[$k]] = UrlFoto::periksa($isi[$k]);
            }
            $aduan->update($ubah);

            return $aduan->load(self::RELASI);
        }));
    }

    // ---- Pembantu ----

    /** Urutan status wajib dipatuhi: Baru -> Diproses -> Monitoring -> Selesai. Melompat (mis. Diproses -> Selesai) atau mundur ditolak 409. */
    private function pastikanUrutan(Aduan $aduan, string $tujuan): void
    {
        $sekarang = StatusAduan::from($aduan->status);
        if ($sekarang->berikutnya()?->value !== $tujuan) {
            throw new ApiException(
                409,
                'URUTAN_STATUS_TIDAK_VALID',
                'Tidak diizinkan: urutan status Diproses -> Monitoring -> Selesai harus dipatuhi (status sekarang '.$sekarang->value.')',
            );
        }
    }

    /** Ambil aduan lewat kode (dikunci selama transaksi). $batasInstansi diisi untuk Klien: aduan instansi lain dianggap tidak ada. */
    private function ambil(string $id, ?string $batasInstansi = null): Aduan
    {
        $aduan = Aduan::lengkap()->lockForUpdate()->where('kode_aduan', strtoupper($id))->first();

        if (! $aduan || ($batasInstansi !== null && $aduan->kodeInstansi() !== $batasInstansi)) {
            $this->tidakDitemukan('Aduan');
        }

        return $aduan;
    }
}
