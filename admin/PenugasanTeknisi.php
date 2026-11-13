<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel penugasan_teknisi (ERD): aduan_id, teknisi_id (pengguna berperan teknisi), peran (utama | pendamping).
   Teknisi pertama pada sebuah aduan berperan utama, berikutnya pendamping. */
class PenugasanTeknisi extends Model
{
    protected $table = 'penugasan_teknisi';

    protected $fillable = ['aduan_id', 'teknisi_id', 'peran'];

    public function aduan(): BelongsTo
    {
        return $this->belongsTo(Aduan::class, 'aduan_id');
    }

    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }
}
