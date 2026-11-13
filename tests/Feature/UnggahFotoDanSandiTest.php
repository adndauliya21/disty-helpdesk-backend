<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/* [MODUL 8] Pengujian tiga endpoint terakhir dari docs/API-KONTRAK.md: POST /uploads, PUT /tickets/{id}/photos,
   PUT /me/password, serta kolom photo di PUT /me dan GET /auth/me.
   Acuan perilaku dan pesan: public/helpdesk/api/api-tiruan.js di frontend. Data seed: HD-010 ditangani Rafi, HD-012 ditangani Bayu, HD-001 milik Bu Rina. */
class UnggahFotoDanSandiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** PNG 1x1 yang valid. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var string[] berkas hasil unggah yang dibersihkan setelah tes */
    private array $dibuat = [];

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->dibuat as $berkas) {
            @unlink($berkas);
        }
        parent::tearDown();
    }

    private function berkasPng(string $nama = 'foto.png'): UploadedFile
    {
        $jalur = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($jalur, base64_decode(self::PNG));

        return new UploadedFile($jalur, $nama, 'image/png', null, true);
    }

    /** Unggah satu PNG dan kembalikan alamatnya. */
    private function unggah(array $header): string
    {
        $balasan = $this->post('/api/v1/uploads', ['file' => $this->berkasPng()], $header + ['Accept' => 'application/json'])->assertStatus(201);
        $url = $balasan->json('data.url');
        $this->dibuat[] = public_path('uploads/'.basename($url));

        return $url;
    }

    // ---- POST /uploads ----

    public function test_unggah_gambar_201_dan_berkas_tersimpan(): void
    {
        $balasan = $this->post('/api/v1/uploads', ['file' => $this->berkasPng('apa-saja.png')], $this->masuk('rafi') + ['Accept' => 'application/json'])
            ->assertStatus(201);

        $url = $balasan->json('data.url');
        $this->dibuat[] = public_path('uploads/'.basename($url));

        $this->assertMatchesRegularExpression('#^https?://[^/]+/uploads/[a-f0-9]{32}\.png$#', $url);
        $this->assertSame('image/png', $balasan->json('data.type'));
        $this->assertSame(strlen(base64_decode(self::PNG)), $balasan->json('data.size'));
        $this->assertFileExists(public_path('uploads/'.basename($url)));
        $this->assertSame(['url', 'size', 'type'], array_keys($balasan->json('data')));
    }

    public function test_unggah_menolak_tanpa_login_tanpa_berkas_dan_bukan_gambar(): void
    {
        $this->post('/api/v1/uploads', ['file' => $this->berkasPng()], ['Accept' => 'application/json'])->assertStatus(401);

        $header = $this->masuk('rina') + ['Accept' => 'application/json'];
        $this->post('/api/v1/uploads', [], $header)->assertStatus(422)->assertJsonPath('error.message', 'Pilih foto dulu');

        // Berkas teks yang menyamar sebagai gambar: ditolak karena isinya, bukan namanya.
        $palsu = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($palsu, '<?php echo 1;');
        $this->post('/api/v1/uploads', ['file' => new UploadedFile($palsu, 'virus.png', 'image/png', null, true)], $header)
            ->assertStatus(422)->assertJsonPath('error.message', 'Foto harus berformat JPG, PNG, WEBP, atau GIF');
    }

    public function test_unggah_menolak_berkas_lebih_dari_5_mb(): void
    {
        $besar = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($besar, base64_decode(self::PNG).str_repeat('0', 5 * 1024 * 1024));

        $this->post('/api/v1/uploads', ['file' => new UploadedFile($besar, 'besar.png', 'image/png', null, true)], $this->masuk('rina') + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Ukuran foto maksimal 5 MB');
    }

    // ---- PUT /me (photo) dan GET /auth/me ----

    public function test_profil_menyimpan_foto_lalu_menghapus_dan_tidak_berubah_bila_tidak_dikirim(): void
    {
        $header = $this->masuk('rina');
        $url = $this->unggah($header);
        $dasar = ['name' => 'Bu Rina', 'email' => 'rina@smkn2.sch.id', 'phone' => '0812'];

        $this->putJson('/api/v1/me', $dasar + ['photo' => $url], $header)->assertOk()->assertJsonPath('data.photo', $url);
        $this->getJson('/api/v1/auth/me', $header)->assertOk()->assertJsonPath('data.photo', $url);

        // Kunci photo tidak dikirim: foto tetap.
        $this->putJson('/api/v1/me', $dasar, $header)->assertOk()->assertJsonPath('data.photo', $url);

        // null: foto dihapus.
        $this->putJson('/api/v1/me', $dasar + ['photo' => null], $header)->assertOk()->assertJsonPath('data.photo', null);
    }

    public function test_profil_menolak_alamat_foto_yang_bukan_hasil_unggah(): void
    {
        $header = $this->masuk('rina');
        foreach (['https://situs-lain.test/foto.jpg', 'javascript:alert(1)', 'data:image/png;base64,AAAA', 123, 'http://localhost/uploads/../.env'] as $buruk) {
            $this->putJson('/api/v1/me', ['name' => 'Bu Rina', 'email' => 'rina@smkn2.sch.id', 'photo' => $buruk], $header)
                ->assertStatus(422)->assertJsonPath('error.message', 'Foto tidak dikenal, silakan unggah ulang');
        }
        $this->getJson('/api/v1/auth/me', $header)->assertJsonPath('data.photo', null);
    }

    // ---- PUT /tickets/{id}/photos ----

    public function test_teknisi_menyimpan_foto_sebelum_dan_sesudah_lalu_menghapus_satu(): void
    {
        $header = $this->masuk('rafi');
        $sebelum = $this->unggah($header);
        $sesudah = $this->unggah($header);

        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $sebelum], $header)
            ->assertOk()->assertJsonPath('data.photos', ['before' => $sebelum, 'after' => null]);

        // Mengirim before lagi mengganti, tidak menambah baris (satu foto per tipe).
        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $sesudah, 'after' => $sesudah], $header)
            ->assertOk()->assertJsonPath('data.photos', ['before' => $sesudah, 'after' => $sesudah]);
        $this->assertDatabaseHas('aduan', ['kode_aduan' => 'HD-010', 'foto_sebelum' => $sesudah, 'foto_sesudah' => $sesudah]);

        // Hanya after dihapus, before tetap.
        $this->putJson('/api/v1/tickets/HD-010/photos', ['after' => null], $header)
            ->assertOk()->assertJsonPath('data.photos', ['before' => $sesudah, 'after' => null]);

        // Terlihat juga oleh klien lewat GET /tickets/{id}.
        $this->getJson('/api/v1/tickets/HD-010', $this->masuk('fitri'))->assertJsonPath('data.photos.before', $sesudah);
    }

    public function test_aduan_tanpa_foto_membalas_photos_null_dua_duanya(): void
    {
        $this->getJson('/api/v1/tickets/HD-010', $this->masuk('fitri'))
            ->assertOk()->assertJsonPath('data.photos', ['before' => null, 'after' => null]);
    }

    public function test_foto_aduan_aturan_akses_dan_validasi(): void
    {
        $rafi = $this->masuk('rafi');
        $url = $this->unggah($rafi);

        // Klien dan admin tidak boleh.
        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $url], $this->masuk('rina'))->assertStatus(403);
        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $url], $this->masuk('admin'))->assertStatus(403);
        // Teknisi yang tidak ditugaskan di aduan itu.
        $this->putJson('/api/v1/tickets/HD-012/photos', ['before' => $url], $rafi)
            ->assertStatus(403)->assertJsonPath('error.message', 'Aduan ini bukan tugasmu');
        // Aduan tidak ada.
        $this->putJson('/api/v1/tickets/HD-999/photos', ['before' => $url], $rafi)->assertStatus(404);
        // Tidak ada kunci foto.
        $this->putJson('/api/v1/tickets/HD-010/photos', ['lain' => 1], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Tidak ada foto yang diubah');
        // Satu alamat buruk membatalkan semuanya: before yang benar juga tidak tersimpan.
        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $url, 'after' => 'https://situs-lain.test/x.jpg'], $rafi)
            ->assertStatus(422)->assertJsonPath('error.message', 'Foto tidak dikenal, silakan unggah ulang');
        $this->assertDatabaseHas('aduan', ['kode_aduan' => 'HD-010', 'foto_sebelum' => null, 'foto_sesudah' => null]);
        // Tanpa login.
        $this->putJson('/api/v1/tickets/HD-010/photos', ['before' => $url])->assertStatus(401);
    }

    // ---- PUT /me/password ----

    public function test_ubah_kata_sandi_204_dan_login_memakai_yang_baru(): void
    {
        $header = $this->masuk('rina');

        $this->putJson('/api/v1/me/password', ['currentPassword' => 'rahasia123', 'newPassword' => 'SandiBaru456'], $header)->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'SandiBaru456'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_ubah_kata_sandi_validasi_dan_sandi_lama_salah(): void
    {
        $header = $this->masuk('rina');
        $kirim = fn (array $b) => $this->putJson('/api/v1/me/password', $b, $header);

        $kirim(['newPassword' => 'SandiBaru456'])->assertStatus(422)->assertJsonPath('error.message', 'Isi kata sandi saat ini');
        $kirim(['currentPassword' => 'salah-total', 'newPassword' => 'SandiBaru456'])
            ->assertStatus(403)->assertJsonPath('error.code', 'SANDI_SALAH')->assertJsonPath('error.message', 'Kata sandi saat ini salah');

        $lemah = 'Kata sandi baru minimal 8 karakter dan mengandung huruf serta angka';
        $kirim(['currentPassword' => 'rahasia123', 'newPassword' => 'pendek1'])->assertStatus(422)->assertJsonPath('error.message', $lemah);
        $kirim(['currentPassword' => 'rahasia123', 'newPassword' => 'hurufsajaaa'])->assertStatus(422)->assertJsonPath('error.message', $lemah);
        $kirim(['currentPassword' => 'rahasia123', 'newPassword' => '1234567890'])->assertStatus(422)->assertJsonPath('error.message', $lemah);
        $kirim(['currentPassword' => 'rahasia123', 'newPassword' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Kata sandi baru harus berbeda dari yang lama');
        $kirim(['currentPassword' => 'rahasia123', 'newPassword' => str_repeat('a1', 40)])
            ->assertStatus(422)->assertJsonPath('error.message', 'Kata sandi terlalu panjang (maksimal 72 karakter)');

        // Semua penolakan di atas tidak mengubah apa pun.
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@smkn2.sch.id', 'password' => 'rahasia123'])->assertOk();
        $this->putJson('/api/v1/me/password', ['currentPassword' => 'rahasia123', 'newPassword' => 'SandiBaru456'])->assertStatus(401);
    }
}
