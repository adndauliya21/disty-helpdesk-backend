<?php

namespace App\Console\Commands;

use App\Support\PemeriksaProduksi;
use Illuminate\Console\Command;

/* [MODUL 7] php artisan disty:periksa-produksi
   Menjalankan daftar periksa produksi (lihat App\Support\PemeriksaProduksi). Kode keluar 0 = semua lolos, 1 = ada yang gagal,
   jadi bisa dipakai di skrip deploy (deploy/deploy.sh). Perintah ini hanya membaca, tidak mengubah apa pun. */
class PeriksaProduksi extends Command
{
    protected $signature = 'disty:periksa-produksi';

    protected $description = 'Periksa apakah konfigurasi dan database siap untuk produksi';

    public function handle(): int
    {
        $gagal = 0;

        foreach (PemeriksaProduksi::periksa() as $butir) {
            if ($butir['ok']) {
                $this->line("  <info>[OK]</info>     {$butir['judul']}");

                continue;
            }
            $gagal++;
            $this->line("  <error>[GAGAL]</error>  {$butir['judul']}");
            $this->line("          -> {$butir['saran']}");
        }

        $this->newLine();
        if ($gagal > 0) {
            $this->error("{$gagal} butir belum memenuhi syarat produksi.");

            return self::FAILURE;
        }
        $this->info('Semua butir lolos. Aplikasi siap untuk produksi.');

        return self::SUCCESS;
    }
}
