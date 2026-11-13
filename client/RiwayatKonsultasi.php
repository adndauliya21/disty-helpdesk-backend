<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel riwayat_konsultasi (ERD): konsultasi_id, aktivitas (maks. 255 karakter). Waktu = created_at. */
class RiwayatKonsultasi extends Model
{
    protected $table = 'riwayat_konsultasi';

    protected $fillable = ['konsultasi_id', 'aktivitas'];

    public function konsultasi(): BelongsTo
    {
        return $this->belongsTo(Konsultasi::class, 'konsultasi_id');
    }
}
