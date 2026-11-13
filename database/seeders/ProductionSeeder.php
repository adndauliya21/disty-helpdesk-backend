<?php

namespace Database\Seeders;

use App\Console\Commands\GantiKataSandi;
use App\Models\User;
use App\Support\PemeriksaProduksi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/* [MODUL 7] SEEDER PRODUKSI. Jalankan:  php artisan db:seed --class=ProductionSeeder --force
   Beda dengan DatabaseSeeder (data contoh untuk pengembangan), seeder ini TIDAK membuat aduan contoh dan TIDAK membuat
   akun dengan kata sandi awal rahasia123. Isinya hanya:
     1. data acuan: instansi (domain email klien), modul, dan panduan Pusat Bantuan, dan
     2. satu akun Admin pertama, kata sandinya dari ADMIN_PASSWORD di .env atau diketik di prompt (tersembunyi).
   Aman dijalankan ulang: akun yang sudah ada tidak ditimpa.
   Setelah berhasil, hapus ADMIN_PASSWORD dari .env. */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DataAcuanSeeder::class);   // instansi, modul, dan panduan Pusat Bantuan (data acuan)

$email = mb_strtolower(trim((string) config('disty.admin_awal.email')));
        if (User::where('email', $email)->exists()) {
            $this->command?->info("Akun '{$email}' sudah ada, tidak diubah.");

            return;
        }

        $sandi = (string) config('disty.admin_awal.password');
        if ($sandi === '' && $this->command && ! $this->command->option('no-interaction')) {
            $sandi = (string) $this->command->secret("Kata sandi untuk akun Admin '{$email}'");
        }
        if (strlen($sandi) < GantiKataSandi::PANJANG_MINIMAL || $sandi === PemeriksaProduksi::KATA_SANDI_AWAL) {
            throw new RuntimeException(
                'Kata sandi Admin minimal '.GantiKataSandi::PANJANG_MINIMAL.' karakter dan bukan kata sandi awal. '
                .'Isi ADMIN_PASSWORD di .env atau jalankan seeder secara interaktif.'
            );
        }

User::create([
            'email' => $email,
            'password' => Hash::make($sandi),
            'nama' => (string) config('disty.admin_awal.name'),
            'telepon' => '',
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        $this->command?->info("Akun Admin '{$email}' dibuat. Hapus ADMIN_PASSWORD dari .env.");
    }
}
