<?php

namespace App\Http\Resources;

use App\Support\Waktu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* API RESOURCE: bentuk JSON aduan. Urutan kunci sama dengan Ticket di docs/API-KONTRAK.md (frontend); channel dan awaitingVerification ditambahkan untuk aduan telepon.
   Sumber data: tabel aduan, modul, pengguna (pelapor, teknisi), riwayat_aduan, solusi_aduan.
   Resource membungkus hasilnya dalam { "data": ... }. */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pelapor = $this->pelapor;
        $menunggu = (bool) $this->menunggu_verifikasi;
        // Alur selesai aduan telepon: Teknisi -> Admin -> Klien. Selama admin belum menyetujui, klien belum melihat solusinya.
        $peran = $request->attributes->get('pengguna')['role'] ?? null;
        $solusi = $menunggu && $peran === 'Klien' ? null : $this->solusi;

        return [
            'id' => $this->kode_aduan,
            'title' => $this->judul,
            'category' => $this->kategori,
            'module' => $this->modul?->nama_modul,
            'description' => $this->deskripsi,
            'status' => $this->status,
            'priority' => $this->prioritas,
            'channel' => $this->kanal,
            'awaitingVerification' => $menunggu,
            'technicians' => $this->teknisi->map(fn ($t) => $t->nama)->values(),
            'attachment' => $this->lampiran,
            'photos' => $this->foto(),
            'instansiCode' => $pelapor?->kode_instansi,
            'reporter' => $pelapor?->namaPelapor(),
            'rating' => $this->rating,
            'solution' => $solusi === null ? null : [
                'part' => $solusi->masalah,
                'summary' => $solusi->solusi,
                'handledBy' => $this->teknisi->map(fn ($t) => $t->nama)->values(),
                'date' => $solusi->tanggal_selesai?->toDateString(),
            ],
            'logs' => $this->riwayat->map(fn ($log) => [
                'at' => Waktu::iso($log->created_at),
                'actor' => $log->aktor(),
                'message' => $log->tindakan,
            ])->values(),
            'createdAt' => Waktu::iso($this->created_at),
        ];
    }
}
