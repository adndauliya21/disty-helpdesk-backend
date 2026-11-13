<?php

namespace App\Models;

use App\Enums\StatusAduan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/* Tabel aduan (ERD). Satu aduan tersebar di beberapa tabel: modul, penugasan_teknisi (teknisi), riwayat_aduan, solusi_aduan.
   Foto sebelum dan sesudah perbaikan ada di kolom foto_sebelum dan foto_sesudah. Prioritas adalah ENUM di aduan (boleh kosong
   sampai admin menetapkannya). Pelapor dan instansi diambil dari pengguna (pelapor_id), instansi lewat instansi_id pengguna itu. */
class Aduan extends Model
{
    protected $table = 'aduan';

    protected $fillable = [
        'kode_aduan', 'pelapor_id', 'modul_id', 'kategori', 'judul', 'deskripsi', 'prioritas', 'status',
        'kanal', 'menunggu_verifikasi', 'lampiran', 'foto_sebelum', 'foto_sesudah', 'rating',
    ];

    public const KANAL_WEB = 'web';

    public const KANAL_TELEPON = 'telepon';

    public const RELASI = ['pelapor.instansi', 'modul', 'teknisi', 'riwayat.pengguna', 'solusi'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'menunggu_verifikasi' => 'boolean'];
    }

    // ---- Relasi ----
    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pelapor_id');
    }

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function penugasan(): HasMany
    {
        return $this->hasMany(PenugasanTeknisi::class, 'aduan_id')->orderBy('id');
    }

    /** Teknisi yang ditugaskan, lewat tabel penugasan_teknisi (urut waktu penugasan). */
    public function teknisi(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'penugasan_teknisi', 'aduan_id', 'teknisi_id')
            ->withPivot('peran')
            ->withTimestamps()
            ->orderBy('penugasan_teknisi.id');
    }

    public function riwayat(): HasMany
    {
        return $this->hasMany(RiwayatAduan::class, 'aduan_id')->orderBy('created_at')->orderBy('id');
    }

    public function solusi(): HasOne
    {
        return $this->hasOne(SolusiAduan::class, 'aduan_id');
    }

    /** Bentuk kontrak: { before, after }, nilai null bila belum ada fotonya. */
    public function foto(): array
    {
        return ['before' => $this->foto_sebelum, 'after' => $this->foto_sesudah];
    }

    // ---- Query scope ----
    public function scopeLengkap(Builder $query): Builder
    {
        return $query->with(self::RELASI);
    }

    public function scopeSaring(Builder $query, ?string $status = null, ?string $prioritas = null, ?string $kodeInstansi = null): Builder
    {
        return $query
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($prioritas !== null, fn (Builder $q) => $q->where('prioritas', $prioritas))
            ->when($kodeInstansi !== null, fn (Builder $q) => $q->whereHas('pelapor', fn (Builder $k) => $k->diInstansi($kodeInstansi)));
    }

    /** Nomor terbesar dulu (HD-014 sebelum HD-013, HD-1000 sesudah HD-999 karena lebih panjang). */
    public function scopeTerbaruDulu(Builder $query): Builder
    {
        return $query->orderByRaw('LENGTH(kode_aduan) DESC')->orderByDesc('kode_aduan');
    }

    /** Cari lewat kode (HD-014). Huruf kecil diterima. */
    public static function dariKode(string $kode): ?self
    {
        return self::where('kode_aduan', strtoupper($kode))->first();
    }

    public function lewatTelepon(): bool
    {
        return $this->kanal === self::KANAL_TELEPON;
    }

    public function kodeInstansi(): ?string
    {
        return $this->pelapor?->kode_instansi;
    }

    /** Tambah satu baris riwayat. */
    public function catat(string $tindakan, int $idPengguna): RiwayatAduan
    {
        return $this->riwayat()->create(['pengguna_id' => $idPengguna, 'tindakan' => $tindakan]);
    }

    // ---- Membuat aduan baru ----
    public static function baru(array $data, User $klien): self
    {
        return DB::transaction(function () use ($data, $klien) {
            $aduan = self::create([
                ...$data,
                'kode_aduan' => self::kodeBerikutnya(),
                'pelapor_id' => $klien->id,
                'status' => StatusAduan::Baru->value,
            ]);
            $aduan->catat('Aduan dikirim', $klien->id);

            return $aduan->load(self::RELASI);
        });
    }

    /** Aduan dari telepon: klien menelepon teknisi langsung, teknisi mencatatnya atas nama klien.
     *  Tidak lewat penugasan admin: teknisi pencatat langsung menjadi teknisi utama dan status langsung Diproses. */
    public static function dariTelepon(array $data, User $klien, User $teknisi): self
    {
        return DB::transaction(function () use ($data, $klien, $teknisi) {
            $aduan = self::create([
                ...$data,
                'kode_aduan' => self::kodeBerikutnya(),
                'pelapor_id' => $klien->id,
                'status' => StatusAduan::Diproses->value,
                'kanal' => self::KANAL_TELEPON,
            ]);
            PenugasanTeknisi::create(['aduan_id' => $aduan->id, 'teknisi_id' => $teknisi->id, 'peran' => 'utama']);
            $aduan->catat("Aduan diterima lewat telepon dan dicatat oleh {$teknisi->nama}", $teknisi->id);
            $aduan->catat("Langsung dikerjakan oleh {$teknisi->nama} (tanpa penugasan admin)", $teknisi->id);

            return $aduan->load(self::RELASI);
        });
    }

    /** HD-014 sudah ada -> HD-015. Nomor dihitung dari kode yang ada (dikunci agar dua aduan bersamaan tidak kembar). */
    private static function kodeBerikutnya(): string
    {
        $terbesar = DB::table('aduan')->lockForUpdate()->pluck('kode_aduan')
            ->map(fn ($kode) => (int) substr($kode, 3))->max() ?? 0;

        return sprintf('HD-%03d', $terbesar + 1);
    }
}
