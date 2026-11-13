<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* [MODUL 6] Pengujian rute dasar, GET/POST /tickets, dan hak akses per peran .
   Jalankan:  php artisan test   (SQLite di memori, database diisi ulang oleh seeder tiap pengujian) */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const KUNCI_KONTRAK = ['id', 'title', 'category', 'module', 'description', 'status', 'priority', 'channel', 'awaitingVerification', 'technicians',
        'attachment', 'photos', 'instansiCode', 'reporter', 'rating', 'solution', 'logs', 'createdAt'];

    public function test_ping_terbuka_tanpa_token(): void
    {
        $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_rute_tidak_dikenal_404(): void
    {
        $this->getJson('/api/v1/tidak-ada')->assertNotFound()->assertJsonPath('error.code', 'TIDAK_DITEMUKAN');
        $this->getJson('/di-luar-api')->assertNotFound()->assertJsonPath('error.code', 'TIDAK_DITEMUKAN');
        // Navigasi browser (Accept html) ke alamat tidak dikenal, mis. Klien mengetik /admin/dashboard, kembali ke halaman awal.
        $this->get('/admin/dashboard', ['Accept' => 'text/html'])->assertRedirect('/');
    }

    public function test_metode_salah_405_dengan_daftar_metode(): void
    {
        $balasan = $this->deleteJson('/api/v1/tickets')->assertStatus(405);

        $this->assertSame('METODE_TIDAK_DIPERBOLEHKAN', $balasan->json('error.code'));
        $this->assertEqualsCanonicalizing(['GET', 'POST'], $balasan->json('error.details.diperbolehkan'));
    }

    public function test_preflight_cors_204(): void
    {
        $balasan = $this->call('OPTIONS', '/api/v1/tickets', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5500',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $balasan->assertStatus(204);
        $this->assertSame('*', $balasan->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_daftar_aduan_admin_dua_belas_data_terbaru_di_depan(): void
    {
        $balasan = $this->getJson('/api/v1/tickets', $this->masuk('admin'))->assertOk();

        $this->assertCount(12, $balasan->json('data'));
        $this->assertSame('HD-012', $balasan->json('data.0.id'));
        $this->assertSame(self::KUNCI_KONTRAK, array_keys($balasan->json('data.0')));
        $this->assertSame('2026-10-20T01:00:00.000Z', $balasan->json('data.0.createdAt'));
        $this->assertSame(['Bayu'], $balasan->json('data.0.technicians'));
        $this->assertNull($balasan->json('data.0.solution'));
        $this->assertCount(2, $balasan->json('data.0.logs'));
        $this->assertSame('2026-10-20T01:00:00.000Z', $balasan->json('data.0.logs.0.at'));
    }

    public function test_aduan_selesai_memuat_solusi_sesuai_kontrak(): void
    {
        // HD-006 ditangani Rafi dan Bayu (urut menurut id teknisi), solusi selesai 2026-09-12.
        $solusi = $this->getJson('/api/v1/tickets/HD-006', $this->masuk('admin'))->assertOk()->json('data.solution');

        $this->assertSame(['part', 'summary', 'handledBy', 'date'], array_keys($solusi));
        $this->assertSame(['Rafi Pratama', 'Bayu'], $solusi['handledBy']);
        $this->assertSame('2026-09-12', $solusi['date']);
    }

    public function test_saring_status_dan_prioritas(): void
    {
        $admin = $this->masuk('admin');

        $this->assertCount(8, $this->getJson('/api/v1/tickets?status=Selesai', $admin)->json('data'));
        $this->assertCount(4, $this->getJson('/api/v1/tickets?priority=Tinggi', $admin)->json('data'));
        $this->getJson('/api/v1/tickets?status=Salah', $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Status tidak valid');
        $this->getJson('/api/v1/tickets?priority=Salah', $admin)
            ->assertStatus(422)->assertJsonPath('error.message', 'Prioritas tidak valid');
    }

    public function test_detail_aduan_tidak_peka_huruf_besar_dan_404_bila_tidak_ada(): void
    {
        $admin = $this->masuk('admin');

        $this->getJson('/api/v1/tickets/hd-012', $admin)->assertOk()->assertJsonPath('data.id', 'HD-012');
        $this->getJson('/api/v1/tickets/HD-999', $admin)->assertNotFound()->assertJsonPath('error.code', 'TIDAK_DITEMUKAN');
    }

    public function test_klien_hanya_melihat_aduan_instansinya(): void
    {
        // Seed hanya punya satu klien per instansi, jadi tambahkan rekan sekantor Bu Rina (SMKN2-SBY).
        $rina = User::where('email', 'rina@smkn2.sch.id')->first();
        DB::table('pengguna')->insert([
            'nama' => 'Rekan Rina', 'email' => 'rekan@smkn2.sch.id', 'password' => $rina->password,
            'telepon' => '081200000000', 'role' => 'klien', 'instansi_id' => $rina->instansi_id, 'status' => 'aktif',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rekan = $this->getJson('/api/v1/tickets', $this->masuk('rekan'))->json('data');
        $this->assertCount(1, $rekan);
        $this->assertSame('HD-001', $rekan[0]['id']);
        $this->assertSame('SMKN2-SBY', $rekan[0]['instansiCode']);

        $yusuf = $this->getJson('/api/v1/tickets', $this->masuk('yusuf'))->json('data');
        $this->assertCount(1, $yusuf);
        $this->assertSame('SMAN5-SBY', $yusuf[0]['instansiCode']);
    }

    public function test_klien_membuka_aduan_instansi_lain_dijawab_404_bukan_403(): void
    {
        $this->getJson('/api/v1/tickets/HD-012', $this->masuk('yusuf'))->assertNotFound();
    }

    public function test_admin_dan_teknisi_melihat_semua_aduan(): void
    {
        $this->assertCount(12, $this->getJson('/api/v1/tickets', $this->masuk('admin'))->json('data'));
        $this->assertCount(12, $this->getJson('/api/v1/tickets', $this->masuk('rafi'))->json('data'));
    }

    public function test_klien_membuat_aduan_pelapor_dan_instansi_dari_token(): void
    {
        $balasan = $this->postJson('/api/v1/tickets', [
            'title' => '  Printer tidak terdeteksi  ',
            'category' => 'Gangguan / Error',
            'module' => 'Laporan',
            'description' => 'Printer tidak muncul di aplikasi.',
            'priority' => 'Sedang',
        ], $this->masuk('rina'));

        $balasan->assertStatus(201)->assertHeader('Location', '/api/v1/tickets/HD-013');
        $this->assertSame(self::KUNCI_KONTRAK, array_keys($balasan->json('data')));
        $this->assertSame('HD-013', $balasan->json('data.id'));
        $this->assertSame('Printer tidak terdeteksi', $balasan->json('data.title'));
        $this->assertSame('Baru', $balasan->json('data.status'));
        $this->assertSame('Bu Rina', $balasan->json('data.reporter'));
        $this->assertSame('SMKN2-SBY', $balasan->json('data.instansiCode'));
        $this->assertSame([], $balasan->json('data.technicians'));
        $this->assertCount(1, $balasan->json('data.logs'));
        $this->assertSame('Aduan dikirim', $balasan->json('data.logs.0.message'));

        // Langsung terlihat oleh Klien itu (paling depan), tetapi tidak oleh instansi lain.
        $rina = $this->getJson('/api/v1/tickets', $this->masuk('rina'))->json('data');
        $this->assertCount(2, $rina);
        $this->assertSame('HD-013', $rina[0]['id']);
        $this->assertCount(1, $this->getJson('/api/v1/tickets', $this->masuk('yusuf'))->json('data'));
    }

    public function test_admin_dan_teknisi_tidak_boleh_membuat_aduan(): void
    {
        $isi = ['title' => 'x', 'category' => 'Gangguan / Error', 'description' => 'x', 'priority' => 'Sedang'];

        $this->postJson('/api/v1/tickets', $isi, $this->masuk('admin'))->assertStatus(403)->assertJsonPath('error.code', 'DILARANG');
        $this->postJson('/api/v1/tickets', $isi, $this->masuk('rafi'))->assertStatus(403);
    }

    public function test_validasi_aduan_422_pesan_pertama_dan_rincian(): void
    {
        $balasan = $this->postJson('/api/v1/tickets', ['x' => 1], $this->masuk('rina'))->assertStatus(422);

        $this->assertSame('VALIDASI', $balasan->json('error.code'));
        $this->assertSame('Isi cerita singkat dulu', $balasan->json('error.message'));
        $this->assertSame(['description', 'title', 'category'], array_keys($balasan->json('error.details')));

        $this->postJson('/api/v1/tickets', [
            'title' => str_repeat('a', 101), 'category' => 'Gangguan / Error', 'description' => 'x', 'priority' => 'Sedang',
        ], $this->masuk('rina'))->assertStatus(422)->assertJsonPath('error.message', 'Judul aduan maksimal 100 karakter');

        $this->postJson('/api/v1/tickets', [
            'title' => 'x', 'category' => 'Lain', 'description' => 'x', 'priority' => 'Sedang',
        ], $this->masuk('rina'))->assertStatus(422)->assertJsonPath('error.message', 'Jenis bantuan tidak dikenal');
    }

    public function test_json_rusak_400(): void
    {
        $header = $this->masuk('rina');

        foreach (['{rusak', '[1,2]', '"teks"'] as $isi) {
            $this->call('POST', '/api/v1/tickets', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => $header['Authorization'],
            ], $isi)->assertStatus(400)->assertJsonPath('error.code', 'JSON_RUSAK');
        }
    }
}
