<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Data referensi (/knowledge-base, /institutions, /solutions), survei (/surveys/me), dan teknisi (/technicians). */
class ReferensiSurveiTeknisiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_basis_pengetahuan_dikelompokkan_per_modul(): void
    {
        $data = $this->getJson('/api/v1/knowledge-base', $this->masuk('rina'))->assertOk()->json('data');
        $this->assertSame(6, count($data));
        $this->assertSame(['Lupa kata sandi', 'Akun terkunci setelah salah sandi'], $data['Login / Akun']);
    }

    public function test_instansi_disusun_dari_database_dan_solusi_kosong(): void
    {
        $data = $this->getJson('/api/v1/institutions', $this->masuk('rina'))->assertOk()->json('data');
        $this->assertCount(20, $data);
        $this->assertSame('smkn2.sch.id', $data['SMKN2-SBY']['dom']);
        $this->assertSame('Bu Rina', $data['SMKN2-SBY']['p'][0]['n']);
        $this->assertSame([['HD-001', 5, 'Cepat dan jelas.']], $data['SMKN2-SBY']['p'][0]['v']);

        $this->getJson('/api/v1/solutions', $this->masuk('rina'))->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_survei_baca_kirim_dan_kirim_ulang_memperbarui_baris_terakhir(): void
    {
        $h = $this->masuk('rina');
        $this->getJson('/api/v1/surveys/me', $h)->assertOk()->assertJsonPath('data.overall', 5)->assertJsonPath('data.resolved', 'Ya, sudah');

        $this->putJson('/api/v1/surveys/me', ['overall' => 3, 'aspects' => [3, 4, 2], 'resolved' => 'Sebagian', 'comment' => 'Lumayan'], $h)
            ->assertOk()->assertJsonPath('data.aspects', [3, 4, 2])->assertJsonPath('data.resolved', 'Sebagian');
        $this->assertDatabaseCount('survei', 6);   // diperbarui, bukan menambah
        $this->assertDatabaseHas('survei', ['nilai_keseluruhan' => 3, 'nilai_respon' => 3, 'nilai_kemudahan' => 4, 'nilai_kualitas' => 2, 'terselesaikan' => 'Sebagian']);

        $this->putJson('/api/v1/surveys/me', ['overall' => 0, 'aspects' => [1, 1, 1], 'resolved' => ''], $h)->assertStatus(422);
        $this->putJson('/api/v1/surveys/me', ['overall' => 4, 'aspects' => [1, 1, 1], 'resolved' => ''], $h)->assertOk()->assertJsonPath('data.resolved', 'Belum');
        $this->putJson('/api/v1/surveys/me', ['overall' => 4, 'aspects' => [1, 1, 1], 'resolved' => ''], $this->masuk('admin'))->assertStatus(403);
    }

    public function test_klien_tanpa_survei_menerima_data_null_dan_survei_baru_menambah_baris(): void
    {
        $h = $this->masuk('gunawan');
        $this->getJson('/api/v1/surveys/me', $h)->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/v1/surveys/me', ['overall' => 5, 'aspects' => [5, 5, 5], 'resolved' => 'Ya, sudah'], $h)->assertOk();
        $this->assertDatabaseCount('survei', 7);
    }

    public function test_teknisi_daftar_tambah_ubah_dan_hapus(): void
    {
        $admin = $this->masuk('admin');
        $this->getJson('/api/v1/technicians', $admin)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0', ['name' => 'Rafi Pratama', 'specialty' => 'Database']);

        $this->postJson('/api/v1/technicians', ['name' => 'Budi Santoso', 'specialty' => 'Jaringan'], $admin)
            ->assertStatus(201)->assertJsonPath('data.specialty', 'Jaringan');
        $this->assertDatabaseHas('pengguna', ['nama' => 'Budi Santoso', 'role' => 'teknisi', 'status' => 'nonaktif', 'email' => 'budi.santoso@perusahaan.id']);
        $this->postJson('/api/v1/technicians', ['name' => 'Budi Santoso', 'specialty' => 'Jaringan'], $admin)->assertStatus(409);
        $this->postJson('/api/v1/technicians', ['name' => 'Cici'], $admin)->assertStatus(422)->assertJsonPath('error.message', 'Spesialisasi wajib diisi');
        $this->postJson('/api/v1/technicians', ['name' => 'Cici', 'specialty' => 'Umum'], $admin)->assertStatus(201)->assertJsonPath('data.specialty', 'Umum');
        $this->postJson('/api/v1/technicians', ['name' => 'Wati', 'specialty' => str_repeat('a', 51)], $admin)->assertStatus(422);

        // Akun baru nonaktif, jadi belum bisa login.
        $this->postJson('/api/v1/auth/login', ['email' => 'budi.santoso@perusahaan.id', 'password' => 'apa saja'])->assertStatus(401);

        $this->putJson('/api/v1/technicians/Budi%20Santoso', ['specialty' => 'Server'], $admin)->assertOk()->assertJsonPath('data.specialty', 'Server');
        $this->deleteJson('/api/v1/technicians/Budi%20Santoso', [], $admin)->assertNoContent();
        $this->deleteJson('/api/v1/technicians/Budi%20Santoso', [], $admin)->assertStatus(404);
        $this->deleteJson('/api/v1/technicians/Rafi%20Pratama', [], $admin)->assertStatus(409)->assertJsonPath('error.code', 'MASIH_DIPAKAI');
    }
}
