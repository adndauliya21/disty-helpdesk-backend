<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Aduan (tabel aduan, penugasan_teknisi, riwayat_aduan, solusi_aduan) lewat endpoint /tickets.
   Data seed: HD-001 sampai HD-012. HD-010 (Bu Fitri, SMAN1-MLG) ditangani Rafi, HD-011 (Pak Gunawan) belum ditugaskan. */
class AduanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_melihat_semua_aduan_terbaru_di_depan_dan_bentuk_sesuai_kontrak(): void
    {
        $balasan = $this->getJson('/api/v1/tickets', $this->masuk('admin'))->assertOk();
        $this->assertSame(12, count($balasan->json('data')));
        $this->assertSame('HD-012', $balasan->json('data.0.id'));

        $hd1 = collect($balasan->json('data'))->firstWhere('id', 'HD-001');
        $this->assertSame(
            ['id', 'title', 'category', 'module', 'description', 'status', 'priority', 'channel', 'awaitingVerification', 'technicians', 'attachment', 'photos', 'instansiCode', 'reporter', 'rating', 'solution', 'logs', 'createdAt'],
            array_keys($hd1),
        );
        $this->assertSame('Jaringan', $hd1['module']);
        $this->assertSame(['Bayu'], $hd1['technicians']);
        $this->assertSame('SMKN2-SBY', $hd1['instansiCode']);
        $this->assertSame('Bu Rina', $hd1['reporter']);
        $this->assertSame('2026-09-15', $hd1['solution']['date']);
        $this->assertSame(['Bayu'], $hd1['solution']['handledBy']);
        $this->assertSame(['Klien', 'Bayu'], array_column($hd1['logs'], 'actor'));
    }

    public function test_klien_hanya_melihat_aduan_instansinya_dan_aduan_lain_404(): void
    {
        $this->getJson('/api/v1/tickets', $this->masuk('fitri'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'HD-010');
        $this->getJson('/api/v1/tickets/hd-010', $this->masuk('fitri'))->assertOk();
        $this->getJson('/api/v1/tickets/HD-001', $this->masuk('fitri'))->assertStatus(404);
        $this->getJson('/api/v1/tickets/HD-999', $this->masuk('admin'))->assertStatus(404);
    }

    public function test_saring_status_dan_prioritas(): void
    {
        $h = $this->masuk('admin');
        $this->getJson('/api/v1/tickets?status=Selesai', $h)->assertJsonCount(8, 'data');
        $this->getJson('/api/v1/tickets?priority=Urgent', $h)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tickets?status=Salah', $h)->assertStatus(422);
    }

    public function test_klien_membuat_aduan_kode_berikutnya_dan_prioritas_boleh_kosong(): void
    {
        $this->postJson('/api/v1/tickets', [
            'title' => 'Printer macet', 'category' => 'Gangguan / Error', 'module' => 'Perangkat Baru', 'description' => 'Tidak mencetak',
        ], $this->masuk('rina'))
            ->assertStatus(201)->assertHeader('Location', '/api/v1/tickets/HD-013')
            ->assertJsonPath('data.id', 'HD-013')->assertJsonPath('data.status', 'Baru')->assertJsonPath('data.priority', null)
            ->assertJsonPath('data.reporter', 'Bu Rina')->assertJsonPath('data.instansiCode', 'SMKN2-SBY')
            ->assertJsonPath('data.logs.0.message', 'Aduan dikirim');

        $this->assertDatabaseHas('modul', ['nama_modul' => 'Perangkat Baru']);   // modul baru dibuat bila belum ada
    }

    public function test_validasi_aduan_422(): void
    {
        $h = $this->masuk('rina');
        $this->postJson('/api/v1/tickets', ['title' => '', 'category' => 'Gangguan / Error', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Judul aduan wajib diisi');
        $this->postJson('/api/v1/tickets', ['title' => str_repeat('a', 101), 'category' => 'Gangguan / Error', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Judul aduan maksimal 100 karakter');
        $this->postJson('/api/v1/tickets', ['title' => 'a', 'category' => 'Lain', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Jenis bantuan tidak dikenal');

        // Jenis bantuan dan bagian yang bermasalah wajib dipilih; aduan yang ditolak tidak tersimpan.
        $this->postJson('/api/v1/tickets', ['title' => 'Tanpa jenis', 'module' => 'Database', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Jenis bantuan wajib dipilih');
        $this->postJson('/api/v1/tickets', ['title' => 'Tanpa bagian', 'category' => 'Gangguan / Error', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Bagian yang bermasalah wajib dipilih');
        $this->postJson('/api/v1/tickets', ['title' => 'Bagian kosong', 'category' => 'Gangguan / Error', 'module' => '   ', 'description' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('error.message', 'Bagian yang bermasalah wajib dipilih');
        foreach (['Tanpa jenis', 'Tanpa bagian', 'Bagian kosong'] as $judul) {
            $this->assertDatabaseMissing('aduan', ['judul' => $judul]);
        }
    }

    public function test_alur_prioritas_tugaskan_catat_solusi_selesai_dan_rating(): void
    {
        $admin = $this->masuk('admin');
        $rafi = $this->masuk('rafi');
        $klien = $this->masuk('gunawan');   // pemilik HD-011

        // Prioritas hanya admin.
        $this->patchJson('/api/v1/tickets/HD-011', ['priority' => 'Urgent'], $rafi)->assertStatus(403);
        $this->patchJson('/api/v1/tickets/HD-011', ['priority' => 'Urgent'], $admin)->assertOk()->assertJsonPath('data.priority', 'Urgent');

        // Teknisi pertama = utama dan status jadi Diproses, berikutnya pendamping, dua kali = 409.
        $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Dina'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'Diproses')->assertJsonPath('data.technicians', ['Dina']);
        $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Bayu'], $admin)->assertOk()->assertJsonPath('data.technicians', ['Dina', 'Bayu']);
        $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Bayu'], $admin)->assertStatus(409);
        $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Tidak Ada'], $admin)->assertStatus(404);
        $this->assertDatabaseHas('penugasan_teknisi', ['teknisi_id' => \App\Models\User::where('nama', 'Bayu')->value('id'), 'peran' => 'pendamping']);

        // Catatan tindakan.
        $this->postJson('/api/v1/tickets/HD-011/logs', ['message' => 'Cek awal'], $rafi)->assertStatus(201);
        $this->postJson('/api/v1/tickets/HD-011/logs', ['message' => ''], $rafi)->assertStatus(422);

        // Urutan wajib: tidak boleh melompat dari Diproses ke Selesai. Sesudah Monitoring, solusi tetap wajib. Belum selesai: tidak boleh dinilai.
        $this->patchJson('/api/v1/tickets/HD-011', ['status' => 'Selesai', 'note' => 'x'], $rafi)->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $this->patchJson('/api/v1/tickets/HD-011', ['status' => 'Monitoring', 'note' => 'Dipantau'], $rafi)->assertOk()->assertJsonPath('data.status', 'Monitoring');
        $this->patchJson('/api/v1/tickets/HD-011', ['status' => 'Selesai', 'note' => 'x'], $rafi)->assertStatus(409)->assertJsonPath('error.code', 'SOLUSI_BELUM_ADA');
        $this->putJson('/api/v1/tickets/HD-011/rating', ['score' => 5], $klien)->assertStatus(409);

        // Solusi: penangan yang belum ditugaskan (Rafi) ikut tercatat di penugasan_teknisi sebagai pendamping.
        $hasil = $this->putJson('/api/v1/tickets/HD-011/solution', ['part' => 'Modul rapor', 'summary' => 'Fitur PDF dibuat', 'handledBy' => ['Dina', 'Rafi Pratama']], $rafi)
            ->assertOk()->assertJsonPath('data.status', 'Selesai')->assertJsonPath('data.solution.part', 'Modul rapor');
        $this->assertSame(['Dina', 'Bayu', 'Rafi Pratama'], $hasil->json('data.solution.handledBy'));
        $this->assertDatabaseHas('solusi_aduan', ['masalah' => 'Modul rapor', 'solusi' => 'Fitur PDF dibuat']);
        $this->putJson('/api/v1/tickets/HD-011/solution', ['part' => 'a', 'summary' => 'b', 'handledBy' => ['Dina']], $rafi)->assertStatus(409);

        // Rating klien pemilik, hanya 1 sampai 5.
        $this->putJson('/api/v1/tickets/HD-011/rating', ['score' => 6], $klien)->assertStatus(422);
        $this->putJson('/api/v1/tickets/HD-011/rating', ['score' => 5], $klien)->assertOk()->assertJsonPath('data.rating', 5);
        $this->putJson('/api/v1/tickets/HD-011/rating', ['score' => 5], $this->masuk('fitri'))->assertStatus(404);   // instansi lain
    }

    public function test_solusi_dengan_teknisi_tidak_dikenal_ditolak_tanpa_mengubah_data(): void
    {
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Monitoring', 'note' => 'Dipantau'], $this->masuk('rafi'))->assertOk();
        $this->putJson('/api/v1/tickets/HD-010/solution', ['part' => 'a', 'summary' => 'b', 'handledBy' => ['Rafi Pratama', 'Hantu']], $this->masuk('rafi'))
            ->assertStatus(422)->assertJsonPath('error.message', 'Teknisi tidak dikenal: Hantu');
        $this->assertDatabaseMissing('solusi_aduan', ['masalah' => 'a']);
        $this->assertDatabaseHas('aduan', ['kode_aduan' => 'HD-010', 'status' => 'Monitoring']);
    }
}
