<?php

namespace Tests\Feature;

use App\Models\ArtikelBantuan;
use App\Models\Instansi;
use App\Models\Modul;
use Database\Seeders\DataAcuanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Pengujian data referensi: GET /knowledge-base, GET /solutions, dan GET /institutions.
   Semuanya dibaca dari database (tabel artikel_bantuan, instansi, pengguna, survei). Data seed ada di database/data/demo.json. */
class ReferensiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ---- Akses ----

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/knowledge-base')->assertStatus(401);
        $this->getJson('/api/v1/solutions')->assertStatus(401);
        $this->getJson('/api/v1/institutions')->assertStatus(401);
    }

    public function test_semua_peran_boleh_membaca(): void
    {
        foreach (['admin', 'rafi', 'rina'] as $akun) {
            $this->getJson('/api/v1/knowledge-base', $this->masuk($akun))->assertOk();
            $this->getJson('/api/v1/solutions', $this->masuk($akun))->assertOk();
            $this->getJson('/api/v1/institutions', $this->masuk($akun))->assertOk();
        }
    }

    public function test_hanya_metode_baca_yang_ada(): void
    {
        $this->postJson('/api/v1/knowledge-base', [], $this->masuk('admin'))->assertStatus(405);
        $this->putJson('/api/v1/solutions', [], $this->masuk('admin'))->assertStatus(405);
    }

    // ---- GET /knowledge-base ----

    public function test_basis_pengetahuan_berbentuk_objek_kategori_ke_daftar_judul_dengan_urutan_asli(): void
    {
        $data = $this->getJson('/api/v1/knowledge-base', $this->masuk('rina'))->assertOk()->json('data');

        $this->assertSame(['Database', 'Login / Akun', 'Laporan', 'Pembayaran', 'Tampilan', 'Lainnya'], array_keys($data));
        $this->assertSame(['Data tidak tersimpan setelah klik simpan', 'Cara memeriksa koneksi database'], $data['Database']);
        $this->assertSame(['Lupa kata sandi', 'Akun terkunci setelah salah sandi'], $data['Login / Akun']);
        $this->assertSame(['Laporan kosong atau tidak muncul', 'Cara mengunduh laporan PDF'], $data['Laporan']);
        $this->assertSame(['Pembayaran e-wallet belum masuk', 'Status transaksi pending'], $data['Pembayaran']);
        $this->assertSame(['Tampilan berantakan di HP', 'Membersihkan cache browser'], $data['Tampilan']);
        $this->assertSame(['Panduan penggunaan sistem'], $data['Lainnya']);
        $this->assertSame(11, ArtikelBantuan::count());
    }

    public function test_isi_artikel_dibaca_dari_tabel_yang_sudah_ada(): void
    {
        $data = $this->getJson('/api/v1/knowledge-base/'.rawurlencode('Lupa kata sandi'), $this->masuk('rina'))->assertOk()->json('data');

        $this->assertSame('Lupa kata sandi', $data['title']);
        $this->assertSame('Login / Akun', $data['module']);
        $this->assertStringContainsString('reset sandi', $data['content']);
        $this->assertSame(11, ArtikelBantuan::count());
    }

    public function test_artikel_yang_tidak_ada_balas_404(): void
    {
        $this->getJson('/api/v1/knowledge-base/xyzabc123', $this->masuk('rina'))->assertStatus(404);
    }

    public function test_seeder_data_acuan_aman_diulang(): void
    {
        (new DataAcuanSeeder)->run();
        (new DataAcuanSeeder)->run();

        $this->assertSame(11, ArtikelBantuan::count());
        $this->assertSame(20, Instansi::count());
        $this->assertSame(7, Modul::count());
    }

    public function test_basis_pengetahuan_kosong_tetap_objek_bukan_daftar(): void
    {
        ArtikelBantuan::query()->delete();

        $balasan = $this->getJson('/api/v1/knowledge-base', $this->masuk('rina'))->assertOk();

        $this->assertSame('{"data":{}}', $balasan->getContent());
    }

    public function test_judul_baru_di_kategori_lama_muncul_di_kategori_itu(): void
    {
        ArtikelBantuan::create(['modul_id' => Modul::idDariNama('Database'), 'judul' => 'Judul baru', 'isi' => 'Isi panduan baru']);

        $data = $this->getJson('/api/v1/knowledge-base', $this->masuk('rina'))->json('data');

        $this->assertSame(['Data tidak tersimpan setelah klik simpan', 'Cara memeriksa koneksi database', 'Judul baru'], $data['Database']);
        $this->assertCount(6, $data);
    }

    // ---- GET /solutions ----

    public function test_arsip_solusi_lama_kosong_karena_solusi_ada_di_setiap_aduan(): void
    {
        $this->getJson('/api/v1/solutions', $this->masuk('rafi'))->assertOk()->assertExactJson(['data' => []]);

        // Solusi penanganan sekarang ikut di aduan yang selesai (tabel solusi_aduan).
        $selesai = array_filter(
            $this->getJson('/api/v1/tickets', $this->masuk('admin'))->json('data'),
            fn ($a) => $a['status'] === 'Selesai',
        );
        $this->assertCount(8, $selesai);
        foreach ($selesai as $aduan) {
            $this->assertNotNull($aduan['solution'], "{$aduan['id']} selesai tetapi tanpa solusi");
        }
    }

    // ---- GET /institutions ----

    public function test_instansi_berisi_dua_puluh_kode_dengan_nama_domain_dan_orang(): void
    {
        $data = $this->getJson('/api/v1/institutions', $this->masuk('rina'))->assertOk()->json('data');

        $this->assertCount(20, $data);
        $this->assertSame(['SMKN2-SBY', 'SMAN5-SBY', 'MAN1-SDA'], array_slice(array_keys($data), 0, 3));
        $this->assertSame('SMK Negeri 2 Surabaya', $data['SMKN2-SBY']['n']);
        $this->assertSame('smkn2.sch.id', $data['SMKN2-SBY']['dom']);
        $this->assertSame('sman5sby.sch.id', $data['SMAN5-SBY']['dom']);
        $this->assertSame('man1sda.sch.id', $data['MAN1-SDA']['dom']);
        foreach ($data as $kode => $instansi) {
            $this->assertCount(1, $instansi['p'], "{$kode} seharusnya punya satu klien di seed");
        }
    }

    public function test_orang_di_instansi_berbentuk_kontrak(): void
    {
        $rina = $this->getJson('/api/v1/institutions', $this->masuk('admin'))->json('data.SMKN2-SBY.p.0');

        $this->assertSame(['n', 'j', 'e', 'tel', 'g', 'login', 'last', 'h', 'k', 'v', 'act'], array_keys($rina));
        $this->assertSame('Bu Rina', $rina['n']);
        $this->assertSame('Klien', $rina['j']);
        $this->assertSame('rina@smkn2.sch.id', $rina['e']);
        $this->assertSame('085700000001', $rina['tel']);
        $this->assertSame('6 Nov 2026', $rina['g']);
        $this->assertSame('-', $rina['login']);
        $this->assertSame([], $rina['h']);
        $this->assertSame([], $rina['k']);
        $this->assertSame([['HD-001', 5, 'Cepat dan jelas.']], $rina['v']);
    }

    public function test_domain_setiap_instansi_sama_dengan_tabel_instansi(): void
    {
        $data = $this->getJson('/api/v1/institutions', $this->masuk('rina'))->json('data');

        foreach (Instansi::all() as $instansi) {
            $this->assertSame($instansi->domain_email, $data[$instansi->kode_instansi]['dom']);
            $this->assertSame($instansi->nama_instansi, $data[$instansi->kode_instansi]['n']);
        }
    }
}
