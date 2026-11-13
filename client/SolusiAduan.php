<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel solusi_aduan (ERD, Aduan 1 : 0..1 Solusi): masalah (bagian yang diperbaiki), solusi (ringkasan), tanggal_selesai.
   Di JSON aduan: solution { part = masalah, summary = solusi, handledBy = teknisi yang ditugaskan, date = tanggal_selesai }. */
class SolusiAduan extends Model
{
    protected $table = 'solusi_aduan';

    protected $fillable = ['aduan_id', 'masalah', 'solusi', 'tanggal_selesai'];

    protected function casts(): array
    {
        return ['tanggal_selesai' => 'datetime'];
    }

    public function aduan(): BelongsTo
    {
        return $this->belongsTo(Aduan::class, 'aduan_id');
    }
}
