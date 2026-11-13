<?php

namespace Database\Seeders;

use App\Models\ArtikelBantuan;
use App\Models\Instansi;
use App\Models\Modul;
use Illuminate\Database\Seeder;

/* Data acuan: 20 instansi (kode dan domain email), 7 modul, dan panduan Pusat Bantuan. Isinya sama dengan database/disty_helpdesk.sql
   (dibaca dari database/data/demo.json). Dipakai oleh DatabaseSeeder (pengembangan) dan ProductionSeeder (produksi).
   Aman dijalankan ulang: data yang sudah ada (menurut kode, nama, atau modul + judul) tidak digandakan. */
class DataAcuanSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/demo.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['instansi'] as $i) {
            Instansi::updateOrCreate(['kode_instansi' => $i['kode_instansi']], [
                'nama_instansi' => $i['nama_instansi'], 'domain_email' => $i['domain_email'],
            ]);
        }
        foreach ($data['modul'] as $m) {
            Modul::firstOrCreate(['nama_modul' => $m['nama_modul']]);
        }
        $namaModul = collect($data['modul'])->pluck('nama_modul', 'id');
        foreach ($data['artikel_bantuan'] as $a) {
            ArtikelBantuan::updateOrCreate(
                ['modul_id' => Modul::idDariNama($namaModul[$a['modul_id']]), 'judul' => $a['judul']],
                ['isi' => $a['isi']],
            );
        }
    }
}
