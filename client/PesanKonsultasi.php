<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel pesan_konsultasi (ERD): konsultasi_id, pengirim_id, pesan. Waktu = created_at.
   Kunci `from` di JSON: k (klien) atau a (admin/staf). */
class PesanKonsultasi extends Model
{
    protected $table = 'pesan_konsultasi';

    protected $fillable = ['konsultasi_id', 'pengirim_id', 'pesan'];

    public function konsultasi(): BelongsTo
    {
        return $this->belongsTo(Konsultasi::class, 'konsultasi_id');
    }

    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengirim_id');
    }

    public function dari(): string
    {
        return $this->pengirim?->role === 'klien' ? 'k' : 'a';
    }
}
