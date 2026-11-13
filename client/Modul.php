<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* Tabel modul (ERD): nama_modul. */
class Modul extends Model
{
    protected $table = 'modul';

    protected $fillable = ['nama_modul'];

    /** Modul bawaan. Nama modul di aduan boleh teks bebas (frontend), jadi modul baru dibuat bila belum ada. */
    public const BAWAAN = ['Database', 'Login / Akun', 'Laporan', 'Pembayaran', 'Tampilan', 'Jaringan', 'Lainnya'];

    public static function idDariNama(?string $nama): int
    {
        $nama = trim((string) $nama);

        return (int) self::firstOrCreate(['nama_modul' => $nama === '' ? 'Lainnya' : $nama])->id;
    }
}
