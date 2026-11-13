<?php

namespace Tests\Feature;

use App\Support\PemeriksaProduksi;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/* [MODUL 7] Pengujian perangkat production: pengecekan rahasia JWT, daftar periksa, seeder production,
   dan perintah ganti kata sandi. Tidak mengubah pengujian Modul 6 (api, auth, technician, jwt). */
class ProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rahasia_jwt_bawaan_atau_pendek_dianggap_lemah(): void
    {
        config(['disty.jwt.rahasia' => PemeriksaProduksi::RAHASIA_BAWAAN]);
        $this->assertTrue(PemeriksaProduksi::rahasiaJwtLemah());

        config(['disty.jwt.rahasia' => 'pendek']);
        $this->assertTrue(PemeriksaProduksi::rahasiaJwtLemah());

        config(['disty.jwt.rahasia' => 'ganti-dengan-teks-acak-panjang-yang-masih-contoh']);
        $this->assertTrue(PemeriksaProduksi::rahasiaJwtLemah());

        config(['disty.jwt.rahasia' => str_repeat('k', 48)]);
        $this->assertFalse(PemeriksaProduksi::rahasiaJwtLemah());
    }

    public function test_daftar_periksa_menandai_akun_berkata_sandi_awal_dan_migrasi_terbaru(): void
    {
        $this->seed();   // seeder pengembangan: akun berkata sandi rahasia123

        $butir = collect(PemeriksaProduksi::periksa());
        $akun = $butir->first(fn ($b) => str_starts_with($b['judul'], 'Tidak ada akun'));
        $migrasi = $butir->first(fn ($b) => $b['judul'] === 'Migrasi database sudah terbaru');

        $this->assertFalse($akun['ok']);
        $this->assertStringContainsString('rina', $akun['saran']);
        $this->assertTrue($migrasi['ok']);
    }

    public function test_perintah_periksa_produksi_gagal_di_lingkungan_pengujian(): void
    {
        // APP_ENV=testing, bukan production, jadi minimal butir APP_ENV gagal => kode keluar 1.
        $this->artisan('disty:periksa-produksi')->assertExitCode(1);
    }

    /** [MODUL 8] SQLite di memori hanya dimigrasi sekali per proses, jadi data seed dari kelas tes lain bisa terbawa.
     *  Dua pengujian seeder produksi butuh database kosong, maka semua tabel data dikosongkan dulu. */
    private function kosongkanData(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');   // berlaku di dalam transaksi tes, dicek saat commit (tidak pernah, tes di-rollback)
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('migrations', 'sqlite_sequence', 'prioritas')") as $t) {
            DB::table($t->name)->delete();
        }
    }

    public function test_seeder_produksi_membuat_satu_admin_tanpa_data_contoh(): void
    {
        $this->kosongkanData();
        config(['disty.admin_awal.password' => 'kata-sandi-kuat-123']);

        (new ProductionSeeder)->run();
        (new ProductionSeeder)->run();   // aman diulang

        $this->assertDatabaseCount('pengguna', 1);
        $this->assertDatabaseHas('pengguna', ['email' => 'admin@perusahaan.id', 'role' => 'admin']);
        $this->assertDatabaseCount('aduan', 0);
        $this->assertDatabaseCount('instansi', 20);   // data acuan ikut dibuat

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@perusahaan.id', 'password' => 'kata-sandi-kuat-123'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@perusahaan.id', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_seeder_produksi_menolak_kata_sandi_awal_dan_yang_terlalu_pendek(): void
    {
        $this->kosongkanData();
        foreach (['rahasia123', 'pendek'] as $sandi) {
            config(['disty.admin_awal.password' => $sandi]);
            try {
                (new ProductionSeeder)->run();
                $this->fail("Kata sandi '{$sandi}' seharusnya ditolak");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('minimal', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('pengguna', 0);
    }

    public function test_ganti_kata_sandi_berhasil(): void
    {
        $this->seed();

        $this->artisan('disty:ganti-kata-sandi', ['akun' => 'rina'])
            ->expectsQuestion('Kata sandi baru', 'sandi-baru-12345')
            ->expectsQuestion('Ulangi kata sandi baru', 'sandi-baru-12345')
            ->assertExitCode(0);

        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'sandi-baru-12345'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_ganti_kata_sandi_menolak_pendek_tidak_cocok_dan_akun_tidak_ada(): void
    {
        $this->seed();

        $this->artisan('disty:ganti-kata-sandi', ['akun' => 'rina'])
            ->expectsQuestion('Kata sandi baru', 'pendek')
            ->assertExitCode(1);

        $this->artisan('disty:ganti-kata-sandi', ['akun' => 'rina'])
            ->expectsQuestion('Kata sandi baru', 'sandi-baru-12345')
            ->expectsQuestion('Ulangi kata sandi baru', 'sandi-lain-123456')
            ->assertExitCode(1);

        $this->artisan('disty:ganti-kata-sandi', ['akun' => 'tidak-ada'])->assertExitCode(1);

        // Kata sandi lama tetap berlaku karena tidak ada yang berhasil diubah.
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertOk();
    }
}
