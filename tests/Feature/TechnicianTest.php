<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Pengujian CRUD /technicians. Teknisi adalah baris tabel pengguna berperan teknisi, nama di URL memakai nama lengkap (Rafi Pratama). */
class TechnicianTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_daftar_dan_detail_boleh_untuk_semua_peran(): void
    {
        foreach (['admin', 'rafi', 'rina'] as $akun) {
            $balasan = $this->getJson('/api/v1/technicians', $this->masuk($akun))->assertOk();
            $this->assertSame(['Rafi Pratama', 'Dina', 'Bayu'], array_column($balasan->json('data'), 'name'));
        }

        $this->getJson('/api/v1/technicians/Rafi%20Pratama', $this->masuk('rina'))
            ->assertOk()->assertExactJson(['data' => ['name' => 'Rafi Pratama', 'specialty' => 'Database']]);
        $this->getJson('/api/v1/technicians/Tidak%20Ada', $this->masuk('admin'))->assertNotFound();
    }

    public function test_post_201_location_di_encode_lalu_detail_memakai_spasi(): void
    {
        $admin = $this->masuk('admin');

        $this->postJson('/api/v1/technicians', ['name' => ' Ahmad Fauzi ', 'specialty' => ' Keamanan '], $admin)
            ->assertStatus(201)
            ->assertHeader('Location', '/api/v1/technicians/Ahmad%20Fauzi')
            ->assertExactJson(['data' => ['name' => 'Ahmad Fauzi', 'specialty' => 'Keamanan']]);

        $this->getJson('/api/v1/technicians/Ahmad%20Fauzi', $admin)->assertOk();
    }

    public function test_post_nama_kembar_409_dan_isian_salah_422(): void
    {
        $admin = $this->masuk('admin');

        $this->postJson('/api/v1/technicians', ['name' => 'Rafi Pratama', 'specialty' => 'x'], $admin)
            ->assertStatus(409)->assertJsonPath('error.code', 'SUDAH_ADA');
        $this->postJson('/api/v1/technicians', ['x' => 1], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Nama teknisi wajib diisi');
        $this->postJson('/api/v1/technicians', ['name' => str_repeat('a', 61), 'specialty' => 'x'], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Nama teknisi maksimal 60 karakter');
    }

    public function test_post_spesialisasi_wajib_diisi_kosong_atau_spasi_saja_ditolak_422(): void
    {
        $admin = $this->masuk('admin');

        foreach ([['name' => 'Tanpa Spesialisasi'], ['name' => 'Tanpa Spesialisasi', 'specialty' => ''], ['name' => 'Tanpa Spesialisasi', 'specialty' => '   '], ['name' => 'Tanpa Spesialisasi', 'specialty' => null]] as $isi) {
            $this->postJson('/api/v1/technicians', $isi, $admin)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDASI')->assertJsonPath('error.message', 'Spesialisasi wajib diisi');
        }
        // Tidak ada teknisi yang tersimpan.
        $this->getJson('/api/v1/technicians/Tanpa%20Spesialisasi', $admin)->assertNotFound();
    }

    public function test_post_nama_berisi_angka_atau_karakter_khusus_ditolak_422(): void
    {
        $admin = $this->masuk('admin');

        foreach (['@@123', 'Budi123', '123', 'Budi!', '<b>Budi</b>', '-Budi', "'Budi", '  @@  '] as $nama) {
            $this->postJson('/api/v1/technicians', ['name' => $nama, 'specialty' => 'Jaringan'], $admin)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDASI')
                ->assertJsonPath('error.message', fn ($pesan) => str_starts_with($pesan, 'Format nama tidak valid'));
        }
        $this->getJson('/api/v1/technicians/%40%40123', $admin)->assertNotFound();
    }

    public function test_post_nama_huruf_spasi_titik_apostrof_dan_strip_diterima(): void
    {
        $admin = $this->masuk('admin');

        foreach (['Siti Nurhaliza', 'Ahmad S.', "Dewi O'Brien", 'Anne-Marie', 'José Müller'] as $nama) {
            $this->postJson('/api/v1/technicians', ['name' => $nama, 'specialty' => 'Jaringan'], $admin)
                ->assertStatus(201)->assertJsonPath('data.name', $nama);
        }
    }

    public function test_put_mengganti_spesialisasi_nama_tidak_boleh_berubah_dan_404(): void
    {
        $admin = $this->masuk('admin');

        $this->putJson('/api/v1/technicians/Dina', ['specialty' => 'UI/UX'], $admin)
            ->assertOk()->assertExactJson(['data' => ['name' => 'Dina', 'specialty' => 'UI/UX']]);
        $this->putJson('/api/v1/technicians/Dina', ['name' => 'Dinda', 'specialty' => 'UI/UX'], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Nama teknisi tidak dapat diubah');
        $this->putJson('/api/v1/technicians/Dina', ['x' => 1], $admin)->assertStatus(422);
        $this->putJson('/api/v1/technicians/Tidak%20Ada', ['specialty' => 'x'], $admin)->assertNotFound();
    }

    public function test_delete_teknisi_baru_204_lalu_404(): void
    {
        $admin = $this->masuk('admin');
        $this->postJson('/api/v1/technicians', ['name' => 'Sementara', 'specialty' => 'x'], $admin)->assertStatus(201);

        $this->deleteJson('/api/v1/technicians/Sementara', [], $admin)->assertNoContent();
        $this->getJson('/api/v1/technicians/Sementara', $admin)->assertNotFound();
    }

    public function test_delete_teknisi_yang_masih_dipakai_409_dan_yang_tidak_ada_404(): void
    {
        $admin = $this->masuk('admin');

        $this->deleteJson('/api/v1/technicians/Rafi%20Pratama', [], $admin)
            ->assertStatus(409)->assertJsonPath('error.code', 'MASIH_DIPAKAI');
        $this->getJson('/api/v1/technicians/Rafi%20Pratama', $admin)->assertOk();   // tetap ada
        $this->deleteJson('/api/v1/technicians/Tidak%20Ada', [], $admin)->assertNotFound();
    }

    public function test_klien_dan_teknisi_403_untuk_tulis_dan_403_datang_sebelum_404(): void
    {
        foreach (['rina', 'rafi'] as $akun) {
            $header = $this->masuk($akun);

            $this->postJson('/api/v1/technicians', ['name' => 'X', 'specialty' => 'x'], $header)->assertStatus(403);
            $this->putJson('/api/v1/technicians/Rafi%20Pratama', ['specialty' => 'x'], $header)->assertStatus(403);
            $this->deleteJson('/api/v1/technicians/Rafi%20Pratama', [], $header)->assertStatus(403);
            $this->deleteJson('/api/v1/technicians/Tidak%20Ada', [], $header)->assertStatus(403);
        }
    }

    public function test_metode_salah_405_dengan_daftar_metode(): void
    {
        $balasan = $this->patchJson('/api/v1/technicians/Rafi%20Pratama', [], $this->masuk('admin'))->assertStatus(405);

        $this->assertEqualsCanonicalizing(['GET', 'PUT', 'DELETE'], $balasan->json('error.details.diperbolehkan'));
    }
}
