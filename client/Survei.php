<?php

namespace App\Models;

use App\Support\Waktu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel survei (ERD, Pengguna 1 : N Survei). API menyimpan satu survei terkini per klien: kirim ulang memperbarui baris terakhir.
   Bentuk JSON sama dengan server tiruan frontend: { overall, aspects: [respon, kemudahan, kualitas], resolved, comment, submittedAt }.
   terselesaikan di database: Ya | Sebagian | Belum. Frontend memakai teks pilihan "Ya, sudah" (lihat dariPilihan dan keDaftarPilihan). */
class Survei extends Model
{
    protected $table = 'survei';

    protected $fillable = [
        'pengguna_id', 'aduan_id', 'nilai_keseluruhan', 'nilai_respon', 'nilai_kemudahan', 'nilai_kualitas',
        'terselesaikan', 'komentar',
    ];

    protected function casts(): array
    {
        return [
            'nilai_keseluruhan' => 'integer',
            'nilai_respon' => 'integer',
            'nilai_kemudahan' => 'integer',
            'nilai_kualitas' => 'integer',
        ];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengguna_id');
    }

    public function aduan(): BelongsTo
    {
        return $this->belongsTo(Aduan::class, 'aduan_id');
    }

    /** Survei terakhir milik seorang klien, atau null. */
    public static function terakhirDari(int $idPengguna): ?self
    {
        return self::where('pengguna_id', $idPengguna)->orderByDesc('id')->first();
    }

    /** Pilihan dari frontend ("Ya, sudah", "Sebagian", "Belum", atau kosong) menjadi nilai ENUM. Kosong dihitung Belum. */
    public static function dariPilihan(string $pilihan): ?string
    {
        return match (mb_strtolower(trim($pilihan))) {
            '', 'belum' => 'Belum',
            'ya', 'ya, sudah' => 'Ya',
            'sebagian' => 'Sebagian',
            default => null,
        };
    }

    /** Nilai ENUM menjadi teks pilihan di frontend. */
    public function pilihan(): string
    {
        return $this->terselesaikan === 'Ya' ? 'Ya, sudah' : (string) $this->terselesaikan;
    }

    public function bentuk(): array
    {
        return [
            'overall' => $this->nilai_keseluruhan,
            'aspects' => [$this->nilai_respon, $this->nilai_kemudahan, $this->nilai_kualitas],
            'resolved' => $this->pilihan(),
            'comment' => (string) $this->komentar,
            'submittedAt' => Waktu::iso($this->updated_at ?? $this->created_at ?? Waktu::sekarang()),
        ];
    }
}
