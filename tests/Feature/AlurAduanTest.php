<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Pengujian alur aduan: PATCH, assignments, logs, solution, rating. Data seed (database/data/demo.json):
   HD-010 Diproses (Rafi Pratama, tanpa solusi, 4 log, pelapor Bu Fitri SMAN1-MLG)
   HD-008 Selesai (Bayu, sudah dinilai 4, pelapor Bu Citra SMKN1-MLG)
   HD-011 Baru (tanpa teknisi, 1 log, pelapor Pak Gunawan SMKN4-MLG). */
class AlurAduanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function logTerakhir(array $aduan, int $n = 1): array
    {
        return array_slice($aduan['logs'], -$n);
    }

    /** Pindahkan aduan ke Monitoring sebagai teknisi (catatan tahapan opsional), karena Selesai hanya boleh dari Monitoring. */
    private function keMonitoring(string $kode = 'HD-010', string $akun = 'rafi'): void
    {
        $this->patchJson("/api/v1/tickets/{$kode}", ['status' => 'Monitoring', 'note' => 'Perbaikan selesai, dipantau dulu.'], $this->masuk($akun))->assertOk();
    }

    // ---- PATCH /tickets/{id} ----

    public function test_admin_menetapkan_prioritas_dan_tercatat_di_log(): void
    {
        $balasan = $this->patchJson('/api/v1/tickets/HD-010', ['priority' => 'Urgent'], $this->masuk('admin'))->assertOk();

        $this->assertSame('Urgent', $balasan->json('data.priority'));
        $this->assertCount(5, $balasan->json('data.logs'));
        $terakhir = $balasan->json('data.logs.4');
        $this->assertSame('Admin', $terakhir['actor']);
        $this->assertSame('Prioritas ditetapkan: Urgent', $terakhir['message']);
        $this->assertSame('Urgent', $this->getJson('/api/v1/tickets/HD-010', $this->masuk('fitri'))->json('data.priority'));
    }

    public function test_teknisi_mengubah_status_dan_nama_teknisi_jadi_aktor(): void
    {
        $balasan = $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Monitoring', 'note' => '  Perbaikan selesai, dipantau  '], $this->masuk('rafi'))->assertOk();

        $this->assertSame('Monitoring', $balasan->json('data.status'));
        $this->assertCount(6, $balasan->json('data.logs'));   // catatan tahapan dulu, lalu status
        $this->assertSame(['at' => $balasan->json('data.logs.4.at'), 'actor' => 'Rafi Pratama', 'message' => 'Perbaikan selesai, dipantau'], $balasan->json('data.logs.4'));
        $this->assertSame(['at' => $balasan->json('data.logs.5.at'), 'actor' => 'Rafi Pratama', 'message' => 'Status diubah: Monitoring'], $balasan->json('data.logs.5'));
    }

    public function test_peran_yang_salah_untuk_isi_patch_403_dan_tidak_mengubah_apa_pun(): void
    {
        $this->patchJson('/api/v1/tickets/HD-010', ['priority' => 'Urgent'], $this->masuk('rafi'))
            ->assertStatus(403)->assertJsonPath('error.message', 'Hanya admin yang boleh menetapkan prioritas');
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Monitoring'], $this->masuk('admin'))
            ->assertStatus(403)->assertJsonPath('error.message', 'Hanya teknisi yang boleh mengubah status');
        $this->patchJson('/api/v1/tickets/HD-010', ['priority' => 'Urgent'], $this->masuk('fitri'))
            ->assertStatus(403)->assertJsonPath('error.code', 'DILARANG');

        $aduan = $this->getJson('/api/v1/tickets/HD-010', $this->masuk('admin'))->json('data');
        $this->assertSame('Tinggi', $aduan['priority']);
        $this->assertSame('Diproses', $aduan['status']);
        $this->assertCount(4, $aduan['logs']);
    }

    public function test_patch_validasi_404_dan_selesai_butuh_solusi(): void
    {
        $admin = $this->masuk('admin');
        $rafi = $this->masuk('rafi');

        $this->patchJson('/api/v1/tickets/HD-010', ['priority' => 'Kilat'], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Prioritas tidak valid');
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Beres'], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Status tidak valid');
        $this->patchJson('/api/v1/tickets/HD-010', ['x' => 1], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Isi prioritas atau status yang ingin diubah');
        $this->patchJson('/api/v1/tickets/HD-999', ['priority' => 'Urgent'], $admin)->assertNotFound();

        // Melompat Diproses -> Selesai ditolak karena urutan (bukan karena solusi). Sesudah Monitoring, barulah solusi yang diperiksa.
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Selesai', 'note' => 'x'], $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $this->keMonitoring();
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Selesai', 'note' => 'x'], $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'SOLUSI_BELUM_ADA');
        $this->assertSame('Monitoring', $this->getJson('/api/v1/tickets/HD-010', $rafi)->json('data.status'));
    }

    public function test_tandai_selesai_langsung_dari_diproses_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $rafi = $this->masuk('rafi');
        $pesan = 'Tidak diizinkan: urutan status Diproses -> Monitoring -> Selesai harus dipatuhi (status sekarang Diproses)';

        // Lewat PATCH status.
        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Selesai', 'note' => 'Langsung selesai'], $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID')->assertJsonPath('error.message', $pesan);
        // Lewat PUT solution (tombol Tandai selesai di frontend).
        $this->putJson('/api/v1/tickets/HD-010/solution', ['part' => 'Tabel siswa', 'summary' => 'Sudah beres.', 'handledBy' => ['Rafi Pratama']], $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID')->assertJsonPath('error.message', $pesan);

        $aduan = $this->getJson('/api/v1/tickets/HD-010', $rafi)->json('data');
        $this->assertSame('Diproses', $aduan['status']);
        $this->assertNull($aduan['solution']);
        $this->assertCount(4, $aduan['logs']);
        $this->assertDatabaseMissing('solusi_aduan', ['masalah' => 'Tabel siswa']);
    }

    public function test_urutan_status_hanya_maju_satu_langkah(): void
    {
        $rafi = $this->masuk('rafi');
        $ubah = fn (string $kode, string $status) => $this->patchJson("/api/v1/tickets/{$kode}", ['status' => $status, 'note' => 'Catatan'], $rafi);

        // Baru: hanya boleh ke Diproses.
        $ubah('HD-011', 'Monitoring')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Selesai')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Diproses')->assertOk()->assertJsonPath('data.status', 'Diproses');

        // Diproses: bukan Diproses lagi, bukan mundur ke Baru, bukan lompat ke Selesai.
        $ubah('HD-011', 'Diproses')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Baru')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Selesai')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Monitoring')->assertOk()->assertJsonPath('data.status', 'Monitoring');

        // Monitoring: tidak boleh mundur. Hanya Selesai (lewat solusi) yang sah.
        $ubah('HD-011', 'Diproses')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $ubah('HD-011', 'Baru')->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        $this->putJson('/api/v1/tickets/HD-011/solution', ['part' => 'P', 'summary' => 'S', 'handledBy' => ['Rafi Pratama']], $rafi)
            ->assertOk()->assertJsonPath('data.status', 'Selesai');

        // Selesai: status terkunci.
        foreach (['Baru', 'Diproses', 'Monitoring', 'Selesai'] as $tujuan) {
            $ubah('HD-011', $tujuan)->assertStatus(409)->assertJsonPath('error.code', 'URUTAN_STATUS_TIDAK_VALID');
        }
        $this->assertSame('Selesai', $this->getJson('/api/v1/tickets/HD-011', $rafi)->json('data.status'));
    }

    public function test_catatan_tahapan_boleh_kosong_tetapi_dibatasi_panjangnya(): void
    {
        $rafi = $this->masuk('rafi');

        $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Monitoring', 'note' => str_repeat('a', 5001)], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Catatan tahapan maksimal 5.000 karakter');

        $aduan = $this->getJson('/api/v1/tickets/HD-010', $rafi)->json('data');
        $this->assertSame('Diproses', $aduan['status']);   // gagal = status dan riwayat tidak berubah
        $this->assertCount(4, $aduan['logs']);

        $balasan = $this->patchJson('/api/v1/tickets/HD-010', ['status' => 'Monitoring'], $rafi)->assertOk();
        $this->assertSame('Monitoring', $balasan->json('data.status'));
        $this->assertCount(5, $balasan->json('data.logs'));   // tanpa catatan: hanya riwayat status
    }

    // ---- POST /tickets/{id}/assignments ----

    public function test_teknisi_pertama_mengubah_status_jadi_diproses_berikutnya_hanya_pendamping(): void
    {
        $admin = $this->masuk('admin');

        $satu = $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Dina'], $admin)->assertOk();
        $this->assertSame('Diproses', $satu->json('data.status'));
        $this->assertSame(['Dina'], $satu->json('data.technicians'));
        $this->assertSame('Aduan diteruskan ke Dina', $satu->json('data.logs.1.message'));
        $this->assertSame('Admin', $satu->json('data.logs.1.actor'));

        $dua = $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Bayu'], $admin)->assertOk();
        $this->assertSame(['Dina', 'Bayu'], $dua->json('data.technicians'));
        $this->assertSame('Bayu ditambahkan sebagai teknisi pendamping', $dua->json('data.logs.2.message'));
    }

    public function test_penugasan_ganda_409_teknisi_tidak_ada_404_dan_isian_kosong_422(): void
    {
        $admin = $this->masuk('admin');

        $this->postJson('/api/v1/tickets/HD-010/assignments', ['technician' => 'Rafi Pratama'], $admin)
            ->assertStatus(409)->assertJsonPath('error.code', 'SUDAH_DITUGASKAN');
        $this->postJson('/api/v1/tickets/HD-010/assignments', ['technician' => 'Hantu'], $admin)->assertNotFound();
        $this->postJson('/api/v1/tickets/HD-010/assignments', ['x' => 1], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih teknisi dulu');
        $this->postJson('/api/v1/tickets/HD-999/assignments', ['technician' => 'Dina'], $admin)->assertNotFound();
        $this->postJson('/api/v1/tickets/HD-011/assignments', ['technician' => 'Dina'], $this->masuk('rafi'))->assertStatus(403);
    }

    // ---- POST /tickets/{id}/logs ----

    public function test_teknisi_mencatat_tindakan_201_dengan_aktor_nama_teknisi(): void
    {
        $balasan = $this->postJson('/api/v1/tickets/HD-010/logs', ['message' => '  Backup database dibuat  '], $this->masuk('rafi'))->assertStatus(201);

        $this->assertCount(5, $balasan->json('data.logs'));
        $this->assertSame('Rafi Pratama', $balasan->json('data.logs.4.actor'));
        $this->assertSame('Backup database dibuat', $balasan->json('data.logs.4.message'));
    }

    public function test_catatan_kosong_422_dan_hanya_teknisi_boleh(): void
    {
        $this->postJson('/api/v1/tickets/HD-010/logs', ['message' => '   '], $this->masuk('rafi'))
            ->assertStatus(422)->assertJsonPath('error.message', 'Isi tindakan dulu');
        $this->postJson('/api/v1/tickets/HD-010/logs', ['message' => 'x'], $this->masuk('admin'))->assertStatus(403);
        $this->postJson('/api/v1/tickets/HD-010/logs', ['message' => 'x'], $this->masuk('fitri'))->assertStatus(403);
        $this->postJson('/api/v1/tickets/HD-999/logs', ['message' => 'x'], $this->masuk('rafi'))->assertNotFound();
    }

    // ---- PUT /tickets/{id}/solution ----

    public function test_solusi_menandai_selesai_dengan_dua_log_dan_penangan_terurut(): void
    {
        $this->keMonitoring();   // Selesai hanya boleh dari Monitoring (log: 4 awal + catatan + status Monitoring)
        $balasan = $this->putJson('/api/v1/tickets/HD-010/solution', [
            'part' => 'Tabel siswa (lock)',
            'summary' => 'Proses yang menahan lock dihentikan.',
            'handledBy' => ['Bayu', 'Rafi Pratama'],
        ], $this->masuk('rafi'))->assertOk();

        $this->assertSame('Selesai', $balasan->json('data.status'));
        $solusi = $balasan->json('data.solution');
        $this->assertSame(['part', 'summary', 'handledBy', 'date'], array_keys($solusi));
        $this->assertSame(['Rafi Pratama', 'Bayu'], $solusi['handledBy']);   // urut menurut id teknisi, sama dengan data seed
        $this->assertSame(now()->toDateString(), $solusi['date']);

        $logs = $balasan->json('data.logs');
        $this->assertCount(8, $logs);
        $this->assertSame('Solusi: Tabel siswa (lock). Proses yang menahan lock dihentikan. (ditangani oleh Rafi Pratama, Bayu)', $logs[6]['message']);
        $this->assertSame('Status diubah: Selesai', $logs[7]['message']);
        $this->assertSame('Rafi Pratama', $logs[7]['actor']);
    }

    public function test_solusi_kedua_409_dan_validasi_422(): void
    {
        $rafi = $this->masuk('rafi');
        $isi = ['part' => 'P', 'summary' => 'S', 'handledBy' => ['Rafi Pratama']];
        $this->keMonitoring();

        $this->putJson('/api/v1/tickets/HD-010/solution', [...$isi, 'part' => ''], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih bagian yang diperbaiki');
        $this->putJson('/api/v1/tickets/HD-010/solution', [...$isi, 'summary' => ''], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Isi solusi penanganan');
        $this->putJson('/api/v1/tickets/HD-010/solution', [...$isi, 'handledBy' => []], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih teknisi yang menangani');
        $this->putJson('/api/v1/tickets/HD-010/solution', [...$isi, 'handledBy' => ['Rafi Pratama', 'Hantu']], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Teknisi tidak dikenal: Hantu');
        $this->assertSame('Monitoring', $this->getJson('/api/v1/tickets/HD-010', $rafi)->json('data.status'));   // gagal = tidak ada yang berubah

        $this->putJson('/api/v1/tickets/HD-010/solution', $isi, $rafi)->assertOk();
        $this->putJson('/api/v1/tickets/HD-010/solution', $isi, $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'SUDAH_SELESAI');
        $this->putJson('/api/v1/tickets/HD-010/solution', $isi, $this->masuk('admin'))->assertStatus(403);
    }

    // ---- PUT /tickets/{id}/rating ----

    public function test_klien_menilai_aduan_selesai_instansinya_dan_boleh_mengganti(): void
    {
        $citra = $this->masuk('citra');

        $this->assertSame(4, $this->getJson('/api/v1/tickets/HD-008', $citra)->json('data.rating'));   // nilai awal dari seed
        $this->putJson('/api/v1/tickets/HD-008/rating', ['score' => 5], $citra)->assertOk()->assertJsonPath('data.rating', 5);
        $this->putJson('/api/v1/tickets/HD-008/rating', ['score' => 3], $citra)->assertOk()->assertJsonPath('data.rating', 3);
        $this->assertSame(3, $this->getJson('/api/v1/tickets/HD-008', $this->masuk('admin'))->json('data.rating'));
    }

    public function test_rating_ditolak_bila_belum_selesai_nilai_salah_instansi_lain_atau_bukan_klien(): void
    {
        $fitri = $this->masuk('fitri');
        $citra = $this->masuk('citra');

        $this->putJson('/api/v1/tickets/HD-010/rating', ['score' => 5], $fitri)
            ->assertStatus(409)->assertJsonPath('error.code', 'BELUM_SELESAI');
        foreach ([0, 6, 'lima', null] as $nilai) {
            $this->putJson('/api/v1/tickets/HD-008/rating', ['score' => $nilai], $citra)
                ->assertStatus(422)->assertJsonPath('error.message', 'Nilai harus 1 sampai 5');
        }
        $this->putJson('/api/v1/tickets/HD-008/rating', ['score' => 5], $this->masuk('yusuf'))->assertNotFound();
        $this->putJson('/api/v1/tickets/HD-008/rating', ['score' => 5], $this->masuk('admin'))->assertStatus(403);
        $this->assertSame(4, $this->getJson('/api/v1/tickets/HD-008', $citra)->json('data.rating'));   // tetap nilai seed
    }

    // ---- Alur lengkap seperti yang dijalankan frontend ----

    public function test_alur_lengkap_klien_admin_teknisi_klien(): void
    {
        $baru = $this->postJson('/api/v1/tickets', [
            'title' => 'Printer tidak terdeteksi', 'category' => 'Gangguan / Error', 'module' => 'Jaringan',
            'description' => 'Printer tidak muncul.', 'priority' => 'Sedang',
        ], $this->masuk('rina'))->assertStatus(201)->json('data.id');

        $this->patchJson("/api/v1/tickets/{$baru}", ['priority' => 'Tinggi'], $this->masuk('admin'))->assertOk();
        $this->postJson("/api/v1/tickets/{$baru}/assignments", ['technician' => 'Bayu'], $this->masuk('admin'))
            ->assertOk()->assertJsonPath('data.status', 'Diproses');
        $this->postJson("/api/v1/tickets/{$baru}/logs", ['message' => 'Cek kabel dan IP printer'], $this->masuk('bayu'))->assertStatus(201);
        $this->patchJson("/api/v1/tickets/{$baru}", ['status' => 'Monitoring', 'note' => 'IP printer sudah diatur, dipantau'], $this->masuk('bayu'))
            ->assertOk()->assertJsonPath('data.status', 'Monitoring');
        $this->putJson("/api/v1/tickets/{$baru}/solution", ['part' => 'IP statis printer', 'summary' => 'IP diatur ulang.', 'handledBy' => ['Bayu']], $this->masuk('bayu'))
            ->assertOk()->assertJsonPath('data.status', 'Selesai');
        $this->putJson("/api/v1/tickets/{$baru}/rating", ['score' => 4], $this->masuk('rina'))->assertOk();

        $rina = User::where('email', 'rina@smkn2.sch.id')->first();
        DB::table('pengguna')->insert([
            'nama' => 'Rekan Rina', 'email' => 'rekan@smkn2.sch.id', 'password' => $rina->password, 'telepon' => '081200000000',
            'role' => 'klien', 'instansi_id' => $rina->instansi_id, 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $akhir = $this->getJson("/api/v1/tickets/{$baru}", $this->masuk('rekan'))->assertOk()->json('data');   // rekan satu instansi ikut melihat
        $this->assertSame('Selesai', $akhir['status']);
        $this->assertSame('Tinggi', $akhir['priority']);
        $this->assertSame(4, $akhir['rating']);
        $this->assertSame(
            ['Aduan dikirim', 'Prioritas ditetapkan: Tinggi', 'Aduan diteruskan ke Bayu', 'Cek kabel dan IP printer'],
            array_column(array_slice($akhir['logs'], 0, 4), 'message'),
        );
        $this->assertCount(8, $akhir['logs']);   // dikirim, prioritas, diteruskan, tindakan, catatan Monitoring, status Monitoring, solusi, status Selesai
    }
}
