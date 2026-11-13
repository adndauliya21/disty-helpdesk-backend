<?php

namespace App\Models;

use App\Enums\StatusKonsultasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/* Tabel konsultasi (ERD): pengaju_id, teknisi_id (boleh kosong), topik, catatan, tanggal, jam (teks HH:MM), status, jumlah_ubah_jadwal.
   Pesan ada di pesan_konsultasi, riwayat perubahan di riwayat_konsultasi. Waktu dibuat = created_at.
   Jam disimpan "10:00", sedangkan API dan frontend memakai teks "10.00" (lihat jamDb dan jamTampil). */
class Konsultasi extends Model
{
    protected $table = 'konsultasi';

    protected $fillable = ['pengaju_id', 'teknisi_id', 'topik', 'catatan', 'tanggal', 'jam', 'status', 'jumlah_ubah_jadwal'];

    public const RELASI = ['pengaju.instansi', 'teknisi', 'pesan.pengirim', 'riwayat'];

    protected function casts(): array
    {
        // `tanggal` sengaja tidak di-cast: tetap teks YYYY-MM-DD, persis seperti yang dikirim dan dibaca frontend.
        return ['jumlah_ubah_jadwal' => 'integer'];
    }

    /** "10.00" (API) menjadi "10:00" (kolom jam). */
    public static function jamDb(string $jam): string
    {
        return str_replace('.', ':', $jam);
    }

    /** "10:00" (kolom jam) menjadi "10.00" (API). */
    public function jamTampil(): string
    {
        return str_replace(':', '.', substr((string) $this->jam, 0, 5));
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengaju_id');
    }

    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(PesanKonsultasi::class, 'konsultasi_id')->orderBy('created_at')->orderBy('id');
    }

    public function riwayat(): HasMany
    {
        return $this->hasMany(RiwayatKonsultasi::class, 'konsultasi_id')->orderBy('created_at')->orderBy('id');
    }

    public function scopeLengkap(Builder $query): Builder
    {
        return $query->with(self::RELASI);
    }

    public function scopeTerbaruDulu(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /** Konsultasi yang masih memakai kuota pada tanggal dan jam (teks 10.00) itu: yang bukan Ditolak/Tidak. */
    public function scopeMemakaiKuota(Builder $query, string $tanggal, string $jam): Builder
    {
        return $query->where('tanggal', $tanggal)
            ->where('jam', self::jamDb($jam))
            ->whereNotIn('status', StatusKonsultasi::bebasKuota());
    }

    /** Tambah baris riwayat (kolom aktivitas maksimal 255 karakter). */
    public function catat(string $aktivitas): void
    {
        $this->riwayat()->create(['aktivitas' => mb_substr($aktivitas, 0, 255)]);
    }
}
