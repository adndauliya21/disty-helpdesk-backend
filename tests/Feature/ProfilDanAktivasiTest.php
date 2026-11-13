<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* [MODUL 8] Pengujian endpoint kontrak di sekitar akun: logout, PUT /me, dan aktivasi akun.
   Acuan perilaku: js/29-api-server-tiruan.js di frontend. */
class ProfilDanAktivasiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_logout_204_tanpa_isi(): void
    {
        $this->postJson('/api/v1/auth/logout', [], $this->masuk('rina'))->assertNoContent();
    }

    public function test_ubah_profil_menyimpan_dan_membalas_bentuk_kontrak(): void
    {
        $header = $this->masuk('rina');

        $this->putJson('/api/v1/me', ['name' => '  Bu Rina Baru ', 'email' => 'RINA.BARU@smkn2.sch.id', 'phone' => ' 0812 0000 1111 '], $header)
            ->assertOk()
            ->assertExactJson(['data' => [
                'role' => 'klien',
                'name' => 'Bu Rina Baru',
                'email' => 'rina.baru@smkn2.sch.id',
                'phone' => '0812 0000 1111',
                'photo' => null,
                'instansiCode' => 'SMKN2-SBY',
            ]]);

        // Tersimpan di database: GET /auth/me memakai token lama tetapi isinya sudah baru.
        $this->getJson('/api/v1/auth/me', $header)->assertOk()->assertJsonPath('data.name', 'Bu Rina Baru');

        // Login dengan email baru berhasil, email lama tidak.
        $this->postJson('/api/v1/auth/login', ['email' => 'rina.baru@smkn2.sch.id', 'password' => 'rahasia123'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_aduan_baru_memakai_nama_profil_terbaru_tanpa_keterangan_dalam_kurung(): void
    {
        $header = $this->masuk('rina');
        $this->putJson('/api/v1/me', ['name' => 'Bu Rina Kepala Lab (SMK 2 Surabaya)', 'email' => 'rina@smkn2.sch.id'], $header)->assertOk();

        $this->postJson('/api/v1/tickets', [
            'title' => 'x', 'category' => 'Gangguan / Error', 'module' => 'Database', 'description' => 'x', 'priority' => 'Sedang',
        ], $header)->assertStatus(201)->assertJsonPath('data.reporter', 'Bu Rina Kepala Lab');
    }

    public function test_ubah_profil_validasi_dan_email_bentrok(): void
    {
        $header = $this->masuk('rina');

        $this->putJson('/api/v1/me', ['name' => '', 'email' => 'a@b.id'], $header)
            ->assertStatus(422)->assertJsonPath('error.message', 'Nama dan email yang valid wajib diisi');
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'tanpa-at'], $header)
            ->assertStatus(422)->assertJsonPath('error.message', 'Nama dan email yang valid wajib diisi');
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'yusuf@sman5sby.sch.id'], $header)
            ->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_DIPAKAI');
        // Domain yang belum terdaftar sebagai instansi ditolak untuk klien.
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'rina@gmail.com'], $header)
            ->assertStatus(422)->assertJsonPath('error.code', 'INSTANSI_TIDAK_DIKENAL');

        // Email sendiri (tidak berubah) tidak dianggap bentrok, dan telepon boleh kosong.
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'rina@smkn2.sch.id'], $header)
            ->assertOk()->assertJsonPath('data.phone', '085700000001');   // phone tidak dikirim: tidak berubah
        $this->putJson('/api/v1/me', ['name' => 'Rina', 'email' => 'rina@smkn2.sch.id', 'phone' => ''], $header)
            ->assertOk()->assertJsonPath('data.phone', '');
    }

    public function test_aktivasi_request_mengenali_instansi_dari_domain_email(): void
    {
        $this->postJson('/api/v1/auth/activation/request', ['email' => 'baru@smkn2.sch.id'])
            ->assertStatus(202)
            ->assertExactJson(['data' => ['message' => 'Kode aktivasi dikirim', 'instansiCode' => 'SMKN2-SBY', 'expiresInSeconds' => 600]]);

        $this->postJson('/api/v1/auth/activation/request', ['email' => 'siapa@gmail.com'])
            ->assertStatus(202)->assertJsonPath('data.instansiCode', null);
    }

    public function test_aktivasi_validasi_email_dan_kode(): void
    {
        $this->postJson('/api/v1/auth/activation/request', ['email' => 'bukan-email'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Isi email yang valid dulu');

        $this->postJson('/api/v1/auth/activation/verify', ['email' => 'baru@smkn2.sch.id', 'code' => '12'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Isi kode aktivasi dari email');
        // Akun tidak ada atau kode salah: 422 KODE_SALAH.
        $this->postJson('/api/v1/auth/activation/verify', ['email' => 'baru@smkn2.sch.id', 'code' => '482913'])
            ->assertStatus(422)->assertJsonPath('error.code', 'KODE_SALAH');
    }

    public function test_aktivasi_akun_nonaktif_dengan_kode_dari_database(): void
    {
        $akun = User::create([
            'nama' => 'Pak Baru', 'email' => 'baru@smkn2.sch.id', 'password' => 'x', 'telepon' => '08', 'role' => 'klien', 'status' => 'nonaktif',
        ]);
        $this->assertSame('SMKN2-SBY', $akun->fresh()->kode_instansi);   // instansi terisi dari domain email

        $this->postJson('/api/v1/auth/activation/request', ['email' => 'baru@smkn2.sch.id'])->assertStatus(202);
        $kode = $akun->fresh()->kode_aktivasi;
        $this->assertSame(8, strlen($kode));

        $this->postJson('/api/v1/auth/activation/verify', ['email' => 'baru@smkn2.sch.id', 'code' => 'SALAH123'])->assertStatus(422);
        $this->postJson('/api/v1/auth/activation/verify', ['email' => 'baru@smkn2.sch.id', 'code' => strtolower($kode)])
            ->assertOk()->assertExactJson(['data' => ['activated' => true]]);
        $this->assertDatabaseHas('pengguna', ['email' => 'baru@smkn2.sch.id', 'status' => 'aktif', 'kode_aktivasi' => null]);

        // Kode sekali pakai.
        $this->postJson('/api/v1/auth/activation/verify', ['email' => 'baru@smkn2.sch.id', 'code' => $kode])->assertStatus(422);
    }
}
