<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/* CRUD teknisi. Teknisi adalah baris tabel pengguna berperan teknisi (kolom nama dan spesialisasi), bukan tabel sendiri.
   Teknisi dikenali lewat namanya di path: /technicians/Rafi
     GET    /contacts/technicians   terbuka          -> 200 { name, specialty, phone } teknisi aktif (untuk beranda)
     GET    /technicians            semua peran      -> 200 daftar
     GET    /technicians/{name}     semua peran      -> 200 | 404
     POST   /technicians            Admin            -> 201 + Location | 409 bila nama sudah ada | 422 (nama hanya huruf, spesialisasi wajib)
     PUT    /technicians/{name}     Admin            -> 200 | 404 | 422   (nama tidak bisa diubah)
     DELETE /technicians/{name}     Admin            -> 204 | 404 | 409 bila masih dipakai aduan, konsultasi, atau riwayat */
class TechnicianController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TechnicianResource::collection(User::teknisi()->orderBy('id')->get());
    }

    /** GET /contacts/technicians (terbuka, tanpa login): kontak teknisi untuk beranda. Hanya teknisi aktif yang nomornya terisi. */
    public function kontak(): JsonResponse
    {
        $daftar = User::teknisi()->where('status', 'aktif')->where('telepon', '!=', '')->orderBy('id')->get()
            ->map(fn (User $t) => ['name' => $t->nama, 'specialty' => (string) $t->spesialisasi, 'phone' => (string) $t->telepon])
            ->values();

        return response()->json(['data' => $daftar]);
    }

    public function show(string $name): TechnicianResource
    {
        return new TechnicianResource($this->ambil($name));
    }

    /** Akun teknisi baru dibuat nonaktif dengan kata sandi acak (diaktifkan kemudian lewat aktivasi akun). */
    public function store(Request $request): JsonResponse
    {
        $isi = $this->isi($request);

        $this->validasi($isi, [
            // Nama: huruf (termasuk berakson), spasi, titik, apostrof, dan tanda hubung; harus diawali huruf. Angka dan simbol ditolak.
            'name' => ['bail', 'required', 'string', 'max:60', "regex:/^\\p{L}[\\p{L} .'\\-]*$/u"],
            'specialty' => ['bail', 'required', 'string', 'max:50'],
        ], [
            'name.required' => 'Nama teknisi wajib diisi',
            'name.string' => 'Nama teknisi wajib diisi',
            'name.max' => 'Nama teknisi maksimal 60 karakter',
            'name.regex' => 'Format nama tidak valid: hanya boleh huruf dan spasi (titik, apostrof, dan tanda hubung boleh), tanpa angka atau simbol lain',
            'specialty.required' => 'Spesialisasi wajib diisi',
            'specialty.string' => 'Spesialisasi wajib diisi',
            'specialty.max' => 'Spesialisasi maksimal 50 karakter',
        ]);

        $nama = trim($isi['name']);
        if (User::teknisiBernama($nama)->exists()) {
            throw new ApiException(409, 'SUDAH_ADA', "Teknisi bernama {$nama} sudah ada");
        }

        $baru = $this->buat($nama, trim($isi['specialty']));

        return (new TechnicianResource($baru))
            ->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/technicians/'.rawurlencode($nama));
    }

    public function update(Request $request, string $name): TechnicianResource
    {
        $teknisi = $this->ambil($name);   // 404 dulu bila tidak ada
        $isi = $this->isi($request);

        $this->validasi($isi, [
            'specialty' => ['bail', 'required', 'string', 'max:50'],
        ], [
            'specialty.required' => 'Spesialisasi wajib diisi',
            'specialty.string' => 'Spesialisasi wajib diisi',
            'specialty.max' => 'Spesialisasi maksimal 50 karakter',
        ]);

        // Nama dipakai sebagai penanda di API, jadi tidak boleh berubah lewat PUT.
        if (array_key_exists('name', $isi) && $isi['name'] !== $name) {
            throw new ApiException(422, 'VALIDASI', 'Nama teknisi tidak dapat diubah', ['name' => 'Nama teknisi tidak dapat diubah']);
        }

        $teknisi->update(['spesialisasi' => trim($isi['specialty'])]);

        return new TechnicianResource($teknisi);
    }

    public function destroy(string $name): Response
    {
        $teknisi = $this->ambil($name);

        if ($teknisi->masihDipakai()) {
            throw new ApiException(409, 'MASIH_DIPAKAI', "Teknisi {$name} masih tercatat di aduan atau konsultasi, tidak bisa dihapus");
        }

        try {
            $teknisi->delete();
        } catch (QueryException $e) {
            // SQLSTATE 23000 = pelanggaran constraint (FK RESTRICT dari penugasan_teknisi, riwayat_aduan, pesan_konsultasi): masih dipakai.
            if ((string) $e->getCode() === '23000') {
                throw new ApiException(409, 'MASIH_DIPAKAI', "Teknisi {$name} masih tercatat di aduan atau konsultasi, tidak bisa dihapus");
            }
            throw $e;
        }

        return response()->noContent();
    }

    private function ambil(string $nama): User
    {
        $teknisi = User::teknisiBernama($nama)->first();
        if (! $teknisi) {
            $this->tidakDitemukan('Teknisi');
        }

        return $teknisi;
    }

    /** Buat akun pengguna berperan teknisi (nonaktif). Email dibuat dari nama: "Budi Santoso" -> budi.santoso@{domain_staf}. */
    private function buat(string $nama, string $spesialisasi): User
    {
        $domain = config('disty.domain_staf');
        $dasar = Str::slug($nama, '.') ?: 'teknisi';
        $email = "{$dasar}@{$domain}";
        for ($i = 2; User::where('email', $email)->exists(); $i++) {
            $email = "{$dasar}{$i}@{$domain}";
        }

        return User::create([
            'nama' => $nama,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'telepon' => '',
            'role' => 'teknisi',
            'spesialisasi' => $spesialisasi,
            'status' => 'nonaktif',
        ]);
    }
}
