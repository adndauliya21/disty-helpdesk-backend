<?php

namespace App\Http\Resources;

use App\Support\Waktu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/* API RESOURCE: bentuk JSON konsultasi. Sama dengan objek c di server tiruan frontend:
   { id, instansiCode, reporter, topic, note, date, time, status, technician, createdAt, rescheduled, messages[], logs[] }
   `technician` berupa nama, atau teks kosong bila belum ada (bukan null). Pesan memakai kunci `from` ('k' atau 'a').
   Sumber data: tabel konsultasi, pesan_konsultasi, riwayat_konsultasi, pengguna (pengaju, teknisi). */
class ConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instansiCode' => $this->pengaju?->kode_instansi,
            'reporter' => $this->pengaju?->namaPelapor(),
            'topic' => $this->topik,
            'note' => (string) $this->catatan,
            'date' => $this->tanggal,
            'time' => $this->jamTampil(),
            'status' => $this->status,
            'technician' => $this->teknisi?->nama ?? '',
            'createdAt' => Waktu::iso($this->created_at ?? Waktu::sekarang()),
            'rescheduled' => $this->jumlah_ubah_jadwal,
            'messages' => $this->pesan->map(fn ($pesan) => [
                'from' => $pesan->dari(),
                'text' => $pesan->pesan,
                'at' => Waktu::iso($pesan->created_at),
            ])->values(),
            'logs' => $this->riwayat->map(fn ($log) => [
                'at' => Waktu::iso($log->created_at),
                'message' => $log->aktivitas,
            ])->values(),
        ];
    }
}
