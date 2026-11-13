<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel artikel_bantuan (ERD): panduan Pusat Bantuan per modul. */
class ArtikelBantuan extends Model
{
    protected $table = 'artikel_bantuan';

    protected $fillable = ['modul_id', 'judul', 'isi'];

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function scopeUrut(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    /** { "Database": [judul, ...], ... } dalam urutan artikel dibuat. Tanpa artikel -> objek kosong {} (bukan []). */
    public static function dikelompokkan(): array|object
    {
        $hasil = [];
        foreach (self::query()->with('modul')->urut()->get() as $artikel) {
            $hasil[$artikel->modul->nama_modul][] = $artikel->judul;
        }

        return $hasil === [] ? (object) [] : $hasil;
    }
}
