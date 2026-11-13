<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Instansi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Kode instansi klien otomatis dari domain email (tabel instansi). Kode customer sudah dihapus dari ERD, jadi tidak diuji lagi.
   Data seed: 20 instansi, 20 klien (satu per instansi), 1 admin, dan 3 teknisi tanpa instansi. */
class KodeInstansiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function klienBaru(string $email, string $nama = 'Klien Uji'): User
    {
        return User::create(['nama' => $nama, 'email' => $email, 'password' => 'x', 'telepon' => '081200000000', 'role' => 'klien']);
    }

    public function test_seed_membuat_20_instansi_dan_20_klien_masing_masing_di_instansi_berbeda(): void
    {
        $this->assertSame(20, Instansi::count());
        $klien = User::where('role', 'klien')->orderBy('id')->get();
        $this->assertCount(20, $klien);
        $this->assertSame(20, $klien->pluck('instansi_id')->unique()->count());
        $this->assertNotContains(null, $klien->pluck('kode_instansi')->all());
    }

    public function test_kode_instansi_cocok_dengan_domain_email_setiap_klien(): void
    {
        foreach (User::where('role', 'klien')->get() as $klien) {
            $this->assertSame(Instansi::kodeDariEmail($klien->email), $klien->kode_instansi, "{$klien->email} salah instansi");
        }
        $this->assertSame('SMKN2-SBY', User::where('email', 'rina@smkn2.sch.id')->first()->kode_instansi);
        $this->assertSame('SMAN1-GRS', User::where('email', 'juwita@sman1grs.sch.id')->first()->kode_instansi);
    }

    public function test_klien_baru_otomatis_dapat_instansi_dari_email(): void
    {
        $this->assertSame('SMKN1-MJK', $this->klienBaru('guru@smkn1mjk.sch.id')->kode_instansi);
        $this->assertSame('SMAN2-JBG', $this->klienBaru('guru2@sman2jbg.sch.id')->kode_instansi);
    }

    public function test_email_huruf_besar_dan_spasi_dinormalkan_sebelum_dicocokkan(): void
    {
        $baru = $this->klienBaru('  Guru@SMKN1MJK.sch.id ');

        $this->assertSame('guru@smkn1mjk.sch.id', $baru->email);
        $this->assertSame('SMKN1-MJK', $baru->kode_instansi);
    }

    public function test_domain_tidak_terdaftar_ditolak_dan_tidak_ada_baris_tersimpan(): void
    {
        try {
            $this->klienBaru('orang@gmail.com');
            $this->fail('Domain tidak terdaftar seharusnya ditolak');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertDatabaseMissing('pengguna', ['email' => 'orang@gmail.com']);
    }

    public function test_admin_dan_teknisi_tidak_punya_kode_instansi(): void
    {
        $admin = User::create(['nama' => 'Admin Dua', 'email' => 'admin2@perusahaan.id', 'password' => 'x', 'role' => 'admin']);

        $this->assertNull($admin->kode_instansi);
        $this->assertNull(User::where('email', 'rafi@perusahaan.id')->first()->kode_instansi);
    }

    public function test_ganti_email_ke_instansi_lain_memperbarui_instansi(): void
    {
        $klien = User::where('email', 'rina@smkn2.sch.id')->first();

        $klien->update(['email' => 'rina@sman1sda.sch.id']);

        $this->assertSame('SMAN1-SDA', $klien->fresh()->kode_instansi);
    }

    public function test_ubah_profil_klien_ke_domain_tidak_terdaftar_422(): void
    {
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'rina@gmail.com'], $this->masuk('rina'))
            ->assertStatus(422)->assertJsonPath('error.code', 'INSTANSI_TIDAK_DIKENAL')
            ->assertJsonPath('error.message', 'Domain email belum terdaftar sebagai instansi');
        $this->assertSame('rina@smkn2.sch.id', User::find(5)->email);
    }

    public function test_ubah_profil_klien_ke_instansi_lain_ikut_mengganti_kode_instansi(): void
    {
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'rina@sman1sda.sch.id'], $this->masuk('rina'))
            ->assertOk()->assertJsonPath('data.instansiCode', 'SMAN1-SDA');
    }

    public function test_aktivasi_mengenali_instansi_dari_domain_email(): void
    {
        $this->postJson('/api/v1/auth/activation/request', ['email' => 'baru@smkn1bwi.sch.id'])
            ->assertStatus(202)->assertJsonPath('data.instansiCode', 'SMKN1-BWI');
        $this->postJson('/api/v1/auth/activation/request', ['email' => 'baru@gmail.com'])
            ->assertStatus(202)->assertJsonPath('data.instansiCode', null);
    }

    public function test_teknisi_baru_dari_api_punya_akun_tidak_aktif_yang_belum_bisa_masuk(): void
    {
        $this->postJson('/api/v1/technicians', ['name' => 'Budi Santoso', 'specialty' => 'Jaringan'], $this->masuk('admin'))
            ->assertStatus(201)->assertJsonPath('data.name', 'Budi Santoso');

        $akun = User::where('nama', 'Budi Santoso')->first();
        $this->assertSame('teknisi', $akun->role);
        $this->assertSame('nonaktif', $akun->status);
        $this->assertSame('budi.santoso@perusahaan.id', $akun->email);
        $this->postJson('/api/v1/auth/login', ['email' => $akun->email, 'password' => 'rahasia123'])->assertStatus(401);
    }
}
