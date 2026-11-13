<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/* Aduan lewat telepon: klien menelepon teknisi langsung (tanpa admin), teknisi mencatat aduannya.
   Saat selesai, alurnya Teknisi -> Admin -> Klien. Data seed: rina@smkn2.sch.id (klien), rafi (teknisi), admin. */
class AduanTeleponTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function isian(array $ubah = []): array
    {
        return [
            'reporterEmail' => 'rina@smkn2.sch.id',
            'title' => 'Printer lab mati total',
            'category' => 'Gangguan / Error',
            'module' => 'Jaringan',
            'description' => 'Klien menelepon: printer lab tidak menyala sejak pagi.',
            'priority' => 'Tinggi',
            ...$ubah,
        ];
    }

    private function catatTelepon(string $teknisi = 'rafi', array $ubah = []): array
    {
        return $this->postJson('/api/v1/tickets/phone', $this->isian($ubah), $this->masuk($teknisi))
            ->assertCreated()->json('data');
    }

    /** Teknisi membawa aduan telepon sampai laporan pengerjaan terkirim ke admin. */
    private function kirimLaporan(string $kode, string $teknisi = 'rafi'): void
    {
        $this->patchJson("/api/v1/tickets/{$kode}", ['status' => 'Monitoring'], $this->masuk($teknisi))->assertOk();
        $this->putJson("/api/v1/tickets/{$kode}/solution", ['part' => 'Jaringan', 'summary' => 'Kabel daya diganti.', 'handledBy' => ['Rafi Pratama']], $this->masuk($teknisi))->assertOk();
    }

    public function test_teknisi_mencatat_aduan_telepon_langsung_diproses_tanpa_admin(): void
    {
        $aduan = $this->catatTelepon();

        $this->assertSame('Diproses', $aduan['status']);
        $this->assertSame('telepon', $aduan['channel']);
        $this->assertFalse($aduan['awaitingVerification']);
        $this->assertSame(['Rafi Pratama'], $aduan['technicians']);
        $this->assertSame('Rina', explode(' ', $aduan['reporter'])[1] ?? '');
        $this->assertSame('SMKN2-SBY', $aduan['instansiCode']);
        $this->assertStringContainsString('lewat telepon', $aduan['logs'][0]['message']);
        $this->assertSame('Rafi Pratama', $aduan['logs'][0]['actor']);
    }

    public function test_aduan_telepon_muncul_di_aduan_saya_klien_dengan_status_diproses(): void
    {
        $kode = $this->catatTelepon()['id'];

        $daftar = $this->getJson('/api/v1/tickets', $this->masuk('rina'))->assertOk()->json('data');
        $ini = collect($daftar)->firstWhere('id', $kode);

        $this->assertNotNull($ini);
        $this->assertSame('Diproses', $ini['status']);
        $this->assertSame('telepon', $ini['channel']);
        $this->getJson("/api/v1/tickets/{$kode}", $this->masuk('rina'))->assertOk();
    }

    public function test_klien_instansi_lain_tidak_melihat_aduan_telepon(): void
    {
        $kode = $this->catatTelepon()['id'];

        $this->getJson("/api/v1/tickets/{$kode}", $this->masuk('fitri'))->assertNotFound();
    }

    public function test_hanya_teknisi_boleh_mencatat_aduan_telepon(): void
    {
        $this->postJson('/api/v1/tickets/phone', $this->isian(), $this->masuk('rina'))->assertForbidden();
        $this->postJson('/api/v1/tickets/phone', $this->isian(), $this->masuk('admin'))->assertForbidden();
        $this->postJson('/api/v1/tickets/phone', $this->isian())->assertUnauthorized();
    }

    public function test_validasi_dan_klien_tidak_dikenal(): void
    {
        $header = $this->masuk('rafi');

        $this->postJson('/api/v1/tickets/phone', $this->isian(['reporterEmail' => '']), $header)
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih klien yang menelepon');
        $this->postJson('/api/v1/tickets/phone', $this->isian(['title' => '']), $header)
            ->assertStatus(422)->assertJsonPath('error.message', 'Judul aduan wajib diisi');
        $this->postJson('/api/v1/tickets/phone', $this->isian(['reporterEmail' => 'tidakada@smkn2.sch.id']), $header)
            ->assertNotFound();
        // Akun teknisi dan admin bukan klien: tidak bisa dijadikan pelapor.
        $this->postJson('/api/v1/tickets/phone', $this->isian(['reporterEmail' => 'admin@perusahaan.id']), $header)
            ->assertNotFound();
    }

    public function test_klien_belum_aktif_ditolak(): void
    {
        User::where('email', 'rina@smkn2.sch.id')->update(['status' => 'nonaktif']);

        $this->postJson('/api/v1/tickets/phone', $this->isian(), $this->masuk('rafi'))
            ->assertStatus(409)->assertJsonPath('error.code', 'AKUN_BELUM_AKTIF');
    }

    public function test_selesai_aduan_telepon_dikirim_ke_admin_dulu_klien_belum_melihat_solusi(): void
    {
        $kode = $this->catatTelepon()['id'];
        $this->kirimLaporan($kode);

        // Teknisi dan admin melihat laporan menunggu pemeriksaan; status belum Selesai.
        foreach (['rafi', 'admin'] as $akun) {
            $dto = $this->getJson("/api/v1/tickets/{$kode}", $this->masuk($akun))->assertOk()->json('data');
            $this->assertSame('Monitoring', $dto['status']);
            $this->assertTrue($dto['awaitingVerification']);
            $this->assertSame('Kabel daya diganti.', $dto['solution']['summary']);
        }

        // Klien belum menerima apa pun: tidak ada solusi, dan teks solusi tidak bocor lewat riwayat.
        $dto = $this->getJson("/api/v1/tickets/{$kode}", $this->masuk('rina'))->assertOk()->json('data');
        $this->assertSame('Monitoring', $dto['status']);
        $this->assertNull($dto['solution']);
        $this->assertStringNotContainsString('Kabel daya diganti', json_encode($dto['logs']));
        $this->putJson("/api/v1/tickets/{$kode}/rating", ['score' => 5], $this->masuk('rina'))->assertStatus(409);
    }

    public function test_teknisi_tidak_bisa_menyelesaikan_aduan_telepon_tanpa_admin(): void
    {
        $kode = $this->catatTelepon()['id'];
        $this->kirimLaporan($kode);
        $rafi = $this->masuk('rafi');

        $this->patchJson("/api/v1/tickets/{$kode}", ['status' => 'Selesai'], $rafi)->assertStatus(409)->assertJsonPath('error.code', 'PERLU_VERIFIKASI_ADMIN');
        $this->putJson("/api/v1/tickets/{$kode}/solution", ['part' => 'Jaringan', 'summary' => 'Dikirim lagi.', 'handledBy' => ['Rafi Pratama']], $rafi)
            ->assertStatus(409)->assertJsonPath('error.code', 'MENUNGGU_VERIFIKASI');
    }

    public function test_admin_menyetujui_lalu_klien_menerima_solusi(): void
    {
        $kode = $this->catatTelepon()['id'];
        $this->kirimLaporan($kode);

        $dto = $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => true], $this->masuk('admin'))
            ->assertOk()->json('data');
        $this->assertSame('Selesai', $dto['status']);
        $this->assertFalse($dto['awaitingVerification']);

        $klien = $this->getJson("/api/v1/tickets/{$kode}", $this->masuk('rina'))->assertOk()->json('data');
        $this->assertSame('Selesai', $klien['status']);
        $this->assertSame('Kabel daya diganti.', $klien['solution']['summary']);
        $this->assertSame('Admin', end($klien['logs'])['actor']);
        $this->assertStringContainsString('Selesai', end($klien['logs'])['message']);
        $this->putJson("/api/v1/tickets/{$kode}/rating", ['score' => 5], $this->masuk('rina'))->assertOk()->assertJsonPath('data.rating', 5);
    }

    public function test_admin_mengembalikan_ke_teknisi_yang_lalu_mengirim_ulang(): void
    {
        $kode = $this->catatTelepon()['id'];
        $this->kirimLaporan($kode);
        $admin = $this->masuk('admin');

        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => false], $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Tulis alasan pengembalian untuk teknisi');
        $dto = $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => false, 'note' => 'Tambahkan nomor seri printer.'], $admin)
            ->assertOk()->json('data');
        $this->assertSame('Monitoring', $dto['status']);
        $this->assertFalse($dto['awaitingVerification']);

        $this->putJson("/api/v1/tickets/{$kode}/solution", ['part' => 'Jaringan', 'summary' => 'Kabel daya diganti, printer seri X1.', 'handledBy' => ['Rafi Pratama']], $this->masuk('rafi'))->assertOk();
        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => true], $admin)->assertOk()->assertJsonPath('data.status', 'Selesai');
        $this->getJson("/api/v1/tickets/{$kode}", $this->masuk('rina'))->assertJsonPath('data.solution.summary', 'Kabel daya diganti, printer seri X1.');
    }

    public function test_verifikasi_hanya_admin_dan_hanya_saat_menunggu(): void
    {
        $kode = $this->catatTelepon()['id'];

        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => true], $this->masuk('rafi'))->assertForbidden();
        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => true], $this->masuk('rina'))->assertForbidden();
        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => true], $this->masuk('admin'))
            ->assertStatus(409)->assertJsonPath('error.code', 'TIDAK_MENUNGGU_VERIFIKASI');
        $this->putJson("/api/v1/tickets/{$kode}/verification", ['approve' => 'ya'], $this->masuk('admin'))->assertStatus(422);
    }

    public function test_aduan_web_biasa_tidak_berubah(): void
    {
        $this->assertSame('web', $this->getJson('/api/v1/tickets/HD-010', $this->masuk('admin'))->json('data.channel'));
        $this->assertFalse($this->getJson('/api/v1/tickets/HD-010', $this->masuk('admin'))->json('data.awaitingVerification'));
        // verifikasi admin tidak berlaku untuk aduan web
        $this->putJson('/api/v1/tickets/HD-010/verification', ['approve' => true], $this->masuk('admin'))->assertStatus(409);
    }

    public function test_kontak_teknisi_terbuka_untuk_beranda(): void
    {
        $data = $this->getJson('/api/v1/contacts/technicians')->assertOk()->json('data');

        $rafi = collect($data)->firstWhere('name', 'Rafi Pratama');
        $this->assertSame('081230000002', $rafi['phone']);
        $this->assertSame('Database', $rafi['specialty']);
        $this->assertSame(['name', 'phone', 'specialty'], collect($rafi)->keys()->sort()->values()->all());

        User::where('email', 'dina@perusahaan.id')->update(['status' => 'nonaktif']);
        $this->assertNull(collect($this->getJson('/api/v1/contacts/technicians')->json('data'))->firstWhere('name', 'Dina'));
    }
}
