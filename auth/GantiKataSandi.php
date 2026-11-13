<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PemeriksaProduksi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/* [MODUL 7] php artisan disty:ganti-kata-sandi {akun}   (akun = email, atau bagian sebelum @ bila hanya satu akun yang cocok)
   Mengganti kata sandi satu akun langsung di database production, tanpa menaruh kata sandi di .env atau riwayat shell
   (kata sandi diketik di prompt tersembunyi). Dipakai untuk mengganti kata sandi awal rahasia123 dari seeder pengembangan.
   Tidak ada endpoint API baru: ini perintah server untuk pengelola. */
class GantiKataSandi extends Command
{
    public const PANJANG_MINIMAL = 12;

protected $signature = 'disty:ganti-kata-sandi {akun : email akun yang diganti (boleh bagian sebelum @ saja)}';

    protected $description = 'Ganti kata sandi sebuah akun (untuk pengelola server)';

    public function handle(): int
    {
$akun = $this->cariAkun(mb_strtolower(trim($this->argument('akun'))));
        if (! $akun) {
$this->error('Akun tidak ditemukan (atau cocok dengan lebih dari satu akun, pakai email lengkap).');

            return self::FAILURE;
        }

        $baru = (string) $this->secret('Kata sandi baru');
        if (strlen($baru) < self::PANJANG_MINIMAL) {
            $this->error('Kata sandi minimal '.self::PANJANG_MINIMAL.' karakter.');

            return self::FAILURE;
        }
        if ($baru === PemeriksaProduksi::KATA_SANDI_AWAL) {
            $this->error('Kata sandi tidak boleh sama dengan kata sandi awal.');

            return self::FAILURE;
        }
        if ($baru !== (string) $this->secret('Ulangi kata sandi baru')) {
            $this->error('Kedua isian tidak sama. Tidak ada yang diubah.');

            return self::FAILURE;
        }

$akun->password = Hash::make($baru);
        $akun->save();

        $this->info("Kata sandiakun '{$akun->email}' sudah diganti. Token lama tetap berlaku sampai kedaluwarsa (maks. 8 jam).");

        return self::SUCCESS;
    }

    /** Email lengkap, atau bagian sebelum @ (mis. "rina") bila tepat satu akun yang cocok. */
    private function cariAkun(string $kunci): ?User
    {
        if (str_contains($kunci, '@')) {
            return User::where('email', $kunci)->first();
        }
        $cocok = User::where('email', 'like', $kunci.'@%')->get();

        return $cocok->count() === 1 ? $cocok->first() : null;
    }
}
