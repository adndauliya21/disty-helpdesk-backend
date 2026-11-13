<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/* Konsultasi (tabel konsultasi, pesan_konsultasi, riwayat_konsultasi) dan kuota jam (kuota_konsultasi). */
class KonsultasiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Waktu dibekukan: Minggu 8 Nov 2026 pukul 10.30 WIB, jadi jam 09.00 dan 10.00 hari itu sudah lewat dan 11.00 belum. */
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:30:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ajukan(array $header, string $tanggal = '2026-12-01', string $jam = '10.00')
    {
        return $this->postJson('/api/v1/consultations', ['topic' => 'Uji topik', 'note' => 'catatan', 'date' => $tanggal, 'time' => $jam], $header);
    }

    public function test_daftar_dibatasi_instansi_untuk_klien_dan_bentuk_sesuai_kontrak(): void
    {
        $this->getJson('/api/v1/consultations', $this->masuk('admin'))->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/consultations', $this->masuk('nur'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.topic', 'Rencana integrasi data EMIS')
            ->assertJsonPath('data.0.time', '13.00')
            ->assertJsonPath('data.0.technician', 'Rafi Pratama')
            ->assertJsonPath('data.0.rescheduled', 1)
            ->assertJsonPath('data.0.messages.0.from', 'k')
            ->assertJsonPath('data.0.messages.1.from', 'a');
    }

    public function test_ajukan_konsultasi_membuat_pesan_dan_riwayat_awal(): void
    {
        $this->ajukan($this->masuk('rina'))
            ->assertStatus(201)->assertJsonPath('data.status', 'Menunggu')->assertJsonPath('data.time', '10.00')
            ->assertJsonPath('data.technician', '')
            ->assertJsonPath('data.messages.0.text', 'Uji topik. catatan')
            ->assertJsonPath('data.logs.0.message', 'Pengguna mengajukan konsultasi');
        $this->assertDatabaseHas('konsultasi', ['topik' => 'Uji topik', 'jam' => '10:00', 'tanggal' => '2026-12-01']);   // jam disimpan HH:MM
    }

    public function test_kuota_penuh_409_dan_jam_ditolak_tidak_memakai_kuota(): void
    {
        $h = $this->masuk('rina');
        $pertama = $this->ajukan($h)->assertStatus(201)->json('data.id');   // id dari respons, AUTO_INCREMENT MySQL tidak ikut di-rollback
        $this->ajukan($h)->assertStatus(201);
        $this->ajukan($h)->assertStatus(409)->assertJsonPath('error.code', 'KUOTA_PENUH');
        $this->ajukan($h, '2026-12-01', '12.00')->assertStatus(409);      // bukan jam konsultasi
        $this->ajukan($h, '2026-12-01', '11.00')->assertStatus(201);       // jam lain masih kosong

        $this->patchJson("/api/v1/consultations/{$pertama}", ['status' => 'Ditolak'], $this->masuk('admin'))->assertOk();
        $this->ajukan($h)->assertStatus(201);                               // yang Ditolak membebaskan kuota
    }

    public function test_admin_atur_kuota_lalu_kuota_dipakai_saat_mengajukan(): void
    {
        $admin = $this->masuk('admin');
        $this->putJson('/api/v1/consultation-quota', ['defaults' => ['09.00' => 2, '10.00' => 2, '11.00' => 2, '13.00' => 2, '14.00' => 2, '15.00' => 2], 'overrides' => ['2026-12-02|10.00' => 1]], $admin)
            ->assertOk()->assertJsonPath('data.overrides', ['2026-12-02|10.00' => 1]);
        $this->assertDatabaseHas('kuota_konsultasi', ['tanggal' => '2026-12-02', 'jam' => '10:00', 'kuota' => 1]);
        $this->assertDatabaseHas('kuota_konsultasi', ['tanggal' => null, 'jam' => '13:00', 'kuota' => 2]);

        $h = $this->masuk('rina');
        $this->ajukan($h, '2026-12-02')->assertStatus(201);
        $this->ajukan($h, '2026-12-02')->assertStatus(409);

        // Klien tidak boleh, data buruk 422, dan menyimpan ulang mengganti (bukan menambah) isinya.
        $this->putJson('/api/v1/consultation-quota', ['defaults' => [], 'overrides' => []], $h)->assertStatus(403);
        $this->putJson('/api/v1/consultation-quota', ['defaults' => ['09.00' => 99], 'overrides' => []], $admin)->assertStatus(422);
        $this->putJson('/api/v1/consultation-quota', ['defaults' => ['09.00' => 2, '10.00' => 2, '11.00' => 2, '13.00' => 2, '14.00' => 2, '15.00' => 2], 'overrides' => []], $admin)->assertOk();
        $this->assertDatabaseCount('kuota_konsultasi', 6);
        $this->getJson('/api/v1/consultation-quota', $h)->assertOk()->assertJsonPath('data.defaults', ['09.00' => 2, '10.00' => 2, '11.00' => 2, '13.00' => 2, '14.00' => 2, '15.00' => 2]);
    }

    public function test_status_teknisi_dan_pesan_admin(): void
    {
        $admin = $this->masuk('admin');
        $id = $this->ajukan($this->masuk('rina'), '2026-12-03')->json('data.id');

        $this->patchJson("/api/v1/consultations/{$id}", ['status' => 'Terjadwal'], $admin)->assertStatus(409)->assertJsonPath('error.code', 'TEKNISI_KOSONG');
        $this->putJson("/api/v1/consultations/{$id}/technician", ['technician' => 'Dina'], $admin)->assertOk()->assertJsonPath('data.technician', 'Dina');
        $this->patchJson("/api/v1/consultations/{$id}", ['status' => 'Terjadwal'], $admin)->assertOk()->assertJsonPath('data.status', 'Terjadwal');
        $this->postJson("/api/v1/consultations/{$id}/messages", ['text' => 'Baik, sudah kami jadwalkan'], $admin)
            ->assertStatus(201)->assertJsonPath('data.messages.1.from', 'a');
        $this->assertDatabaseHas('riwayat_konsultasi', ['konsultasi_id' => $id, 'aktivitas' => 'Admin mengubah status menjadi Terjadwal']);
        $this->assertDatabaseHas('pesan_konsultasi', ['konsultasi_id' => $id, 'pesan' => 'Baik, sudah kami jadwalkan']);
    }

    public function test_klien_tidak_bisa_mengajukan_tanggal_atau_jam_yang_sudah_lewat(): void
    {
        $h = $this->masuk('rina');

        $this->ajukan($h, '2026-11-07')->assertStatus(422)->assertJsonPath('error.message', 'Tanggal harus hari ini atau setelahnya');
        $this->ajukan($h, '2026-11-08', '10.00')->assertStatus(422)->assertJsonPath('error.message', 'Jam sudah lewat, pilih jam yang masih tersedia');
        $this->ajukan($h, '2026-12-01', '')->assertStatus(422)->assertJsonPath('error.message', 'Jam wajib diisi');
        $this->assertDatabaseMissing('konsultasi', ['tanggal' => '2026-11-07']);
        $this->assertDatabaseMissing('konsultasi', ['tanggal' => '2026-12-01']);   // jam kosong tidak tersimpan
        $this->ajukan($h, '2026-11-08', '11.00')->assertStatus(201);   // hari ini, jam belum lewat
    }

    public function test_ubah_jadwal_berhasil_dan_klien_melihat_jadwal_baru_beserta_alasan(): void
    {
        $admin = $this->masuk('admin');
        $klien = $this->masuk('rina');
        $id = $this->ajukan($klien, '2026-12-03', '10.00')->json('data.id');

        $this->putJson("/api/v1/consultations/{$id}/schedule", ['date' => '2026-12-05', 'time' => '11.00', 'reason' => 'Teknisi ada tugas lapangan'], $admin)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-12-05')
            ->assertJsonPath('data.time', '11.00')
            ->assertJsonPath('data.rescheduled', 1);

        $this->assertDatabaseHas('konsultasi', ['id' => $id, 'tanggal' => '2026-12-05', 'jam' => '11:00', 'jumlah_ubah_jadwal' => 1]);

        $lihat = collect($this->getJson('/api/v1/consultations', $klien)->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('2026-12-05', $lihat['date']);
        $pesan = end($lihat['messages']);
        $this->assertSame('a', $pesan['from']);
        $this->assertSame('Jadwal konsultasi diubah ke 5 Des 2026, 11.00. Alasan: Teknisi ada tugas lapangan', $pesan['text']);
        $this->assertStringContainsString('Admin mengubah jadwal ke 5 Des 2026, 11.00', end($lihat['logs'])['message']);

        // Jam lama bebas lagi, jadi bisa dipakai pengajuan lain.
        $this->ajukan($this->masuk('rina'), '2026-12-03', '10.00')->assertStatus(201);
    }

    public function test_ubah_jadwal_ditolak_bila_isian_kosong_atau_waktu_sudah_lewat(): void
    {
        $admin = $this->masuk('admin');
        $id = $this->ajukan($this->masuk('rina'), '2026-12-03', '10.00')->json('data.id');
        $ubah = fn (array $isi) => $this->putJson("/api/v1/consultations/{$id}/schedule", $isi, $admin);

        $ubah(['date' => '2026-12-05', 'time' => '', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('error.message', 'Jam wajib diisi');
        $ubah(['date' => '2026-12-05', 'time' => '11.00', 'reason' => '   '])->assertStatus(422)->assertJsonPath('error.message', 'Alasan wajib diisi');
        $ubah(['date' => '2026-11-07', 'time' => '11.00', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('error.message', 'Tanggal harus hari ini atau setelahnya');
        $ubah(['date' => '2026-11-08', 'time' => '10.00', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('error.message', 'Jam sudah lewat, pilih jam yang masih tersedia');
        $ubah(['date' => '2026-12-03', 'time' => '10.00', 'reason' => 'x'])->assertStatus(422);   // sama dengan jadwal sekarang
        $ubah(['date' => '2026-12-05', 'time' => '11.00', 'reason' => str_repeat('a', 1001)])->assertStatus(422);

        // Semua yang gagal tidak mengubah apa pun.
        $this->assertDatabaseHas('konsultasi', ['id' => $id, 'tanggal' => '2026-12-03', 'jam' => '10:00', 'jumlah_ubah_jadwal' => 0]);

        // Hari ini dengan jam yang belum lewat boleh.
        $ubah(['date' => '2026-11-08', 'time' => '11.00', 'reason' => 'Dimajukan atas permintaan klien'])->assertOk()->assertJsonPath('data.date', '2026-11-08');
    }

    public function test_ubah_jadwal_hanya_admin_status_yang_masih_aktif_dan_jam_yang_masih_ada_kuotanya(): void
    {
        $admin = $this->masuk('admin');
        $klien = $this->masuk('rina');
        $id = $this->ajukan($klien, '2026-12-03', '10.00')->json('data.id');
        $isi = ['date' => '2026-12-05', 'time' => '11.00', 'reason' => 'Ada keperluan lain'];

        $this->putJson("/api/v1/consultations/{$id}/schedule", $isi, $klien)->assertStatus(403);
        $this->putJson('/api/v1/consultations/9999/schedule', $isi, $admin)->assertStatus(404);

        // Jam tujuan penuh (kuota 2): dua pengajuan lain sudah memakainya.
        $this->ajukan($klien, '2026-12-05', '11.00')->assertStatus(201);
        $this->ajukan($klien, '2026-12-05', '11.00')->assertStatus(201);
        $this->putJson("/api/v1/consultations/{$id}/schedule", $isi, $admin)->assertStatus(409)->assertJsonPath('error.code', 'KUOTA_PENUH');
        $this->putJson("/api/v1/consultations/{$id}/schedule", ['date' => '2026-12-05', 'time' => '12.00', 'reason' => 'x'], $admin)->assertStatus(409);   // bukan jam konsultasi

        // Konsultasi yang sudah ditolak tidak bisa dijadwal ulang.
        $this->patchJson("/api/v1/consultations/{$id}", ['status' => 'Ditolak'], $admin)->assertOk();
        $this->putJson("/api/v1/consultations/{$id}/schedule", ['date' => '2026-12-06', 'time' => '13.00', 'reason' => 'x'], $admin)
            ->assertStatus(409)->assertJsonPath('error.code', 'STATUS_TIDAK_VALID');
    }
}
