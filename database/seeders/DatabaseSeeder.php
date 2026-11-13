<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/* DATA CONTOH UNTUK PENGEMBANGAN, sama dengan bagian DATA DUMMY di database/disty_helpdesk.sql (dibaca dari database/data/demo.json):
   1 admin, 3 teknisi, 20 klien, 12 aduan beserta penugasan, riwayat, dan solusi, 5 konsultasi, kuota jam, dan 6 survei.
   Semua kata sandi: rahasia123 (HANYA untuk pengembangan). Jalankan:  php artisan migrate:fresh --seed
   Dimasukkan langsung ke tabel (id dan waktu dipertahankan seperti di SQL). Aman dijalankan ulang: bila tabel pengguna sudah berisi, data contoh dilewati. */
class DatabaseSeeder extends Seeder
{
    /** Urutan mengikuti foreign key: induk dulu, anak kemudian. */
    private const TABEL = [
        'pengguna', 'aduan', 'penugasan_teknisi', 'riwayat_aduan', 'solusi_aduan',
        'konsultasi', 'pesan_konsultasi', 'riwayat_konsultasi', 'kuota_konsultasi', 'survei',
    ];

    public function run(): void
    {
        $this->call(DataAcuanSeeder::class);   // instansi, modul, artikel_bantuan

        if (DB::table('pengguna')->exists()) {
            $this->command?->info('Tabel pengguna sudah berisi, data contoh tidak ditambahkan lagi.');

            return;
        }

        $data = json_decode(file_get_contents(database_path('data/demo.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            foreach (self::TABEL as $tabel) {
                foreach (array_chunk($data[$tabel], 50) as $baris) {
                    DB::table($tabel)->insert($baris);
                }
            }
        });
    }
}
