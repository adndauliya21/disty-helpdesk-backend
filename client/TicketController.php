<?php

namespace App\Http\Controllers;

use App\Enums\KategoriAduan;
use App\Enums\Prioritas;
use App\Enums\StatusAduan;
use App\Http\Resources\TicketResource;
use App\Models\Aduan;
use App\Exceptions\ApiException;
use App\Models\Modul;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/* ENDPOINT ADUAN (path tetap /tickets sesuai kontrak frontend; datanya di tabel aduan). Aturan akses (peran ada di api/api.php):
   - Klien hanya melihat aduan milik instansinya. Aduan instansi lain dijawab 404 (bukan 403), supaya keberadaannya tidak bocor.
   - Admin dan Teknisi melihat semua aduan.
   Pelapor dan instansi aduan baru diambil dari token, bukan dari body.
   Pengecualian: aduan telepon (POST /tickets/phone, Teknisi) dicatat atas nama klien yang dipilih teknisi. */
class TicketController extends Controller
{
    /** GET /tickets : semua aduan. Boleh disaring dengan ?status= dan ?priority= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $this->enumDariQuery($request, 'status', StatusAduan::nilai(), 'Status tidak valid');
        $priority = $this->enumDariQuery($request, 'priority', Prioritas::nilai(), 'Prioritas tidak valid');

        $aduan = Aduan::lengkap()
            ->saring($status, $priority, $this->batasInstansi($request))
            ->terbaruDulu()
            ->get();

        return TicketResource::collection($aduan);
    }

    /** GET /tickets/{id} : id = kode aduan, mis. HD-014 */
    public function show(Request $request, string $id): TicketResource
    {
        $aduan = Aduan::lengkap()->where('kode_aduan', strtoupper($id))->first();
        $batas = $this->batasInstansi($request);

        if (! $aduan || ($batas !== null && $aduan->kodeInstansi() !== $batas)) {
            $this->tidakDitemukan('Aduan');
        }

        return new TicketResource($aduan);
    }

    /** POST /tickets : validasi isian, buat aduan baru, balas 201 + header Location.
     *  Prioritas boleh kosong: kolom aduan.prioritas boleh NULL sampai admin menetapkannya. */
    public function store(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, $this->aturanIsian(), $this->pesanIsian());

        // Pelapor dibaca dari database (bukan dari isi token) supaya nama hasil PUT /me langsung terpakai.
        $akun = $this->akun($request);

        $aduan = Aduan::baru([
            'judul' => trim($isi['title']),
            'kategori' => $isi['category'],
            'modul_id' => Modul::idDariNama($isi['module'] ?? null),
            'deskripsi' => trim($isi['description']),
            'prioritas' => $isi['priority'] ?? null,
            'lampiran' => $isi['attachment'] ?? null,
        ], $akun);

        return (new TicketResource($aduan))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/tickets/{$aduan->kode_aduan}");
    }

    /** POST /tickets/phone (Teknisi): klien menelepon teknisi langsung, teknisi mencatat aduannya atas nama klien.
     *  Body: isian aduan biasa + { reporterEmail } (email akun klien yang menelepon).
     *  Tidak lewat admin: teknisi pencatat otomatis menjadi teknisi utama dan status langsung Diproses.
     *  Teknisi hanya memilih klien yang sudah punya akun aktif; ia tidak masuk ke akun klien maupun admin. */
    public function storeTelepon(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi(
            $isi,
            ['reporterEmail' => ['bail', 'required', 'string', 'max:255'], ...$this->aturanIsian()],
            ['reporterEmail.required' => 'Pilih klien yang menelepon', 'reporterEmail.string' => 'Pilih klien yang menelepon', 'reporterEmail.max' => 'Email klien terlalu panjang', ...$this->pesanIsian()],
        );

        $klien = User::where('role', 'klien')->where('email', mb_strtolower(trim($isi['reporterEmail'])))->first();
        if (! $klien) {
            $this->tidakDitemukan('Klien');
        }
        if (! $klien->aktif()) {
            throw new ApiException(409, 'AKUN_BELUM_AKTIF', "Akun {$klien->namaPelapor()} belum aktif, aduan belum bisa dicatat atas namanya");
        }

        $aduan = Aduan::dariTelepon([
            'judul' => trim($isi['title']),
            'kategori' => $isi['category'],
            'modul_id' => Modul::idDariNama($isi['module'] ?? null),
            'deskripsi' => trim($isi['description']),
            'prioritas' => $isi['priority'] ?? null,
            'lampiran' => $isi['attachment'] ?? null,
        ], $klien, $this->akun($request));

        return (new TicketResource($aduan))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/tickets/{$aduan->kode_aduan}");
    }

    /** Aturan isian aduan (dipakai POST /tickets dan POST /tickets/phone). */
    private function aturanIsian(): array
    {
        return [
            'description' => ['bail', 'required', 'string', 'max:5000'],
            'title' => ['bail', 'required', 'string', 'max:100'],
            'category' => ['bail', 'required', Rule::in(KategoriAduan::nilai())],
            'priority' => ['bail', 'nullable', Rule::in(Prioritas::nilai())],
            'module' => ['bail', 'required', 'string', 'max:50'],
            'attachment' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }

    private function pesanIsian(): array
    {
        return [
            'description.required' => 'Isi cerita singkat dulu',
            'description.string' => 'Isi cerita singkat dulu',
            'description.max' => 'Cerita singkat maksimal 5.000 karakter',
            'title.required' => 'Judul aduan wajib diisi',
            'title.string' => 'Judul aduan wajib diisi',
            'title.max' => 'Judul aduan maksimal 100 karakter',
            'category.required' => 'Jenis bantuan wajib dipilih',
            'category.in' => 'Jenis bantuan tidak dikenal',
            'priority.in' => 'Prioritas tidak valid',
            'module.required' => 'Bagian yang bermasalah wajib dipilih',
            'module.string' => 'Bagian yang bermasalah wajib dipilih',
            'module.max' => 'Nama modul maksimal 50 karakter',
            'attachment.string' => 'Lampiran harus berupa teks',
            'attachment.max' => 'Alamat lampiran terlalu panjang',
        ];
    }

    /** Klien dibatasi ke instansinya sendiri, peran lain tidak dibatasi (null = semua). */
    private function batasInstansi(Request $request): ?string
    {
        $pengguna = $this->pengguna($request);

        return $pengguna['role'] === 'Klien' ? $pengguna['instansiCode'] : null;
    }
}
