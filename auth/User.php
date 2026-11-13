<?php

namespace App\Models;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Tabel pengguna (ERD): nama, email, password, telepon, role (klien | admin | teknisi), spesialisasi (hanya teknisi),
   instansi_id (hanya klien), status (aktif | nonaktif), kode_aktivasi, foto, last_login.
   Teknisi bukan tabel sendiri lagi: teknisi = pengguna berperan teknisi (kolom spesialisasi).
   Autentikasi memakai JWT buatan sendiri, jadi model ini tidak perlu extends Authenticatable.
   Peran disimpan huruf kecil (klien, teknisi, admin). Token dan middleware 'peran:Admin' memakai huruf awal kapital (lihat peranToken()).

   Instansi klien: instansi_id diisi dari domain email (tabel instansi). Domain belum terdaftar -> klien ditolak (422).
   Di MySQL pekerjaan yang sama juga dilakukan trigger di database (lihat migration dan database/disty_helpdesk.sql). */
class User extends Model
{
    protected $table = 'pengguna';

    protected $fillable = [
        'nama', 'email', 'password', 'telepon', 'role', 'spesialisasi', 'instansi_id', 'status',
        'kode_aktivasi', 'foto', 'last_login',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['last_login' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (User $akun) {
            $akun->email = mb_strtolower(trim($akun->email));
            $akun->telepon ??= '';
            $akun->instansi_id = $akun->role === 'klien' ? self::instansiWajibAda($akun->email)->id : null;
        });

        static::updating(function (User $akun) {
            $akun->email = mb_strtolower(trim($akun->email));
            if ($akun->role === 'klien' && $akun->isDirty('email')) {
                $akun->instansi_id = self::instansiWajibAda($akun->email)->id;
            }
        });
    }

    /** Instansi dari domain email, atau galat 422 bila domain belum terdaftar. */
    public static function instansiWajibAda(string $email): Instansi
    {
        $instansi = Instansi::dariEmail($email);
        if ($instansi === null) {
            throw new ApiException(422, 'INSTANSI_TIDAK_DIKENAL', 'Domain email belum terdaftar sebagai instansi');
        }

        return $instansi;
    }

    public function instansi(): BelongsTo
    {
        return $this->belongsTo(Instansi::class, 'instansi_id');
    }

    /** $akun->kode_instansi : dari tabel instansi, hanya klien (admin dan teknisi null). */
    protected function kodeInstansi(): Attribute
    {
        return Attribute::get(fn () => $this->role === 'klien' ? $this->instansi?->kode_instansi : null);
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }

    /** Klien milik satu instansi (kode instansi). Kode tidak dikenal = tidak ada hasil. */
    public function scopeDiInstansi(Builder $query, string $kode): Builder
    {
        return $query->where('role', 'klien')
            ->whereHas('instansi', fn (Builder $i) => $i->where('kode_instansi', $kode));
    }

    public function scopeTeknisi(Builder $query): Builder
    {
        return $query->where('role', 'teknisi');
    }

    /** Teknisi berdasarkan nama persis. */
    public function scopeTeknisiBernama(Builder $query, string $nama): Builder
    {
        return $query->where('role', 'teknisi')->where('nama', $nama);
    }

    /** Teknisi berdasarkan daftar nama. */
    public function scopeTeknisiBernamaDi(Builder $query, array $nama): Builder
    {
        return $query->where('role', 'teknisi')->whereIn('nama', $nama);
    }

    /** Peran untuk isi token dan middleware: Klien, Teknisi, atau Admin. */
    public function peranToken(): string
    {
        return ucfirst($this->role);
    }

    /** Peran untuk frontend (kontrak): klien, teknisi, atau admin. */
    public function peranKontrak(): string
    {
        return $this->role;
    }

    /** Nama tanpa keterangan dalam kurung: "Bu Rina (SMK 2 Surabaya)" menjadi "Bu Rina". */
    public function namaPelapor(): string
    {
        return trim(explode(' (', $this->nama)[0]);
    }

    public function profil(): array
    {
        return [
            'role' => $this->peranKontrak(),
            'name' => $this->nama,
            'email' => $this->email,
            'phone' => (string) $this->telepon,
            'photo' => $this->foto === '' ? null : $this->foto,
            'instansiCode' => $this->kode_instansi,
        ];
    }

    /** Teknisi masih dipakai di data lain (foreign key RESTRICT di database melindungi penugasan, riwayat, aduan, pesan). */
    public function masihDipakai(): bool
    {
        return PenugasanTeknisi::where('teknisi_id', $this->id)->exists()
            || RiwayatAduan::where('pengguna_id', $this->id)->exists()
            || PesanKonsultasi::where('pengirim_id', $this->id)->exists()
            || Konsultasi::where('teknisi_id', $this->id)->exists();
    }
}
