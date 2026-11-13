<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel kuota_konsultasi (ERD): diatur_oleh (admin), tanggal (NULL = berlaku umum), jam (HH:MM), kuota.
   Logika kuota ada di App\Support\KuotaKonsultasi. `tanggal` sengaja tidak di-cast: tetap teks YYYY-MM-DD. */
class KuotaKonsultasi extends Model
{
    protected $table = 'kuota_konsultasi';

    protected $fillable = ['diatur_oleh', 'tanggal', 'jam', 'kuota'];

    protected function casts(): array
    {
        return ['kuota' => 'integer'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diatur_oleh');
    }
}
