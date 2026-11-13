<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel riwayat_aduan (ERD): aduan_id, pengguna_id, tindakan. Waktu = created_at.
   Siapa yang mencatat = pengguna; label "actor" di JSON diturunkan dari perannya. */
class RiwayatAduan extends Model
{
    protected $table = 'riwayat_aduan';

    protected $fillable = ['aduan_id', 'pengguna_id', 'tindakan'];

    public function aduan(): BelongsTo
    {
        return $this->belongsTo(Aduan::class, 'aduan_id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengguna_id');
    }

    /** Klien, Admin, atau nama teknisi (sama dengan aktor() di server tiruan frontend). */
    public function aktor(): string
    {
        return match ($this->pengguna?->role) {
            'klien' => 'Klien',
            'admin' => 'Admin',
            default => (string) $this->pengguna?->nama,
        };
    }
}
