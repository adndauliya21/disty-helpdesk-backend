<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Pengujian survei kepuasan: GET dan PUT /surveys/me.
   Akun: admin, rafi (Teknisi), rina (SMKN2-SBY), citra (SMKN1-MLG), yusuf (SMAN5-SBY), oky (SMKN1-BWI).
   Seed berisi 6 survei, jadi tabel survei dikosongkan dulu di setUp supaya setiap tes mulai dari kondisi bersih. */
class SurveiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const URL = '/api/v1/surveys/me';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('survei')->delete();
    }

    private function isiSurvei(array $ganti = []): array
    {
        return $ganti + ['overall' => 4, 'aspects' => [5, 4, 3], 'resolved' => 'Ya, sudah', 'comment' => 'Cepat dan ramah'];
    }

    // ---- GET /surveys/me ----

    public function test_tanpa_token_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
        $this->putJson(self::URL, $this->isiSurvei())->assertStatus(401);
    }

    public function test_klien_yang_belum_mengisi_mendapat_data_null(): void
    {
        $this->getJson(self::URL, $this->masuk('rina'))->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_admin_dan_teknisi_selalu_mendapat_data_null(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(), $this->masuk('rina'))->assertOk();

        foreach (['admin', 'rafi'] as $akun) {
            $this->getJson(self::URL, $this->masuk($akun))->assertOk()->assertExactJson(['data' => null]);
        }
    }

    // ---- PUT /surveys/me ----

    public function test_klien_mengirim_survei_dan_bentuknya_sesuai_kontrak(): void
    {
        $balasan = $this->putJson(self::URL, $this->isiSurvei(), $this->masuk('rina'))->assertOk();

        $this->assertSame(['overall', 'aspects', 'resolved', 'comment', 'submittedAt'], array_keys($balasan->json('data')));
        $this->assertSame(4, $balasan->json('data.overall'));
        $this->assertSame([5, 4, 3], $balasan->json('data.aspects'));
        $this->assertSame('Ya, sudah', $balasan->json('data.resolved'));
        $this->assertSame('Cepat dan ramah', $balasan->json('data.comment'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $balasan->json('data.submittedAt'));
    }

    public function test_survei_tersimpan_dan_terbaca_lagi_lewat_get(): void
    {
        $dikirim = $this->putJson(self::URL, $this->isiSurvei(), $this->masuk('rina'))->assertOk()->json('data');

        $dibaca = $this->getJson(self::URL, $this->masuk('rina'))->assertOk()->json('data');
        $this->assertSame($dikirim, $dibaca);
        $this->assertDatabaseCount('survei', 1);
    }

    public function test_mengirim_ulang_menimpa_survei_lama_bukan_menambah(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(), $this->masuk('rina'))->assertOk();
        $this->putJson(self::URL, $this->isiSurvei(['overall' => 2, 'aspects' => [1, 2, 1], 'resolved' => 'Belum', 'comment' => '']), $this->masuk('rina'))->assertOk();

        $this->assertDatabaseCount('survei', 1);
        $data = $this->getJson(self::URL, $this->masuk('rina'))->json('data');
        $this->assertSame(2, $data['overall']);
        $this->assertSame([1, 2, 1], $data['aspects']);
        $this->assertSame('Belum', $data['resolved']);
        $this->assertSame('', $data['comment']);
    }

    public function test_survei_disimpan_per_akun_bukan_per_peran(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(['overall' => 5]), $this->masuk('rina'))->assertOk();
        $this->putJson(self::URL, $this->isiSurvei(['overall' => 1]), $this->masuk('citra'))->assertOk();

        $this->assertSame(5, $this->getJson(self::URL, $this->masuk('rina'))->json('data.overall'));
        $this->assertSame(1, $this->getJson(self::URL, $this->masuk('citra'))->json('data.overall'));
        $this->assertNull($this->getJson(self::URL, $this->masuk('yusuf'))->json('data'));
        $this->assertDatabaseCount('survei', 2);
    }

    public function test_hanya_overall_wajib_isian_lain_punya_nilai_bawaan(): void
    {
        $data = $this->putJson(self::URL, ['overall' => 3], $this->masuk('rina'))->assertOk()->json('data');

        $this->assertSame(3, $data['overall']);
        $this->assertSame([0, 0, 0], $data['aspects']);
        $this->assertSame('Belum', $data['resolved']);   // kolom terselesaikan adalah ENUM NOT NULL, kosong dihitung Belum
        $this->assertSame('', $data['comment']);
    }

    public function test_komentar_dan_pilihan_dibuang_spasi_tepinya(): void
    {
        $data = $this->putJson(self::URL, $this->isiSurvei(['resolved' => '  Sebagian ', 'comment' => "  Sudah baik \n"]), $this->masuk('rina'))->assertOk()->json('data');

        $this->assertSame('Sebagian', $data['resolved']);
        $this->assertSame('Sudah baik', $data['comment']);
    }

    public function test_angka_bulat_berbentuk_desimal_nol_diterima_seperti_di_javascript(): void
    {
        // JSON 4.0: Number.isInteger(4.0) di JavaScript true, jadi server tiruan menerimanya.
        $isi = '{"overall":4.0,"aspects":[5.0,4,3],"resolved":"","comment":""}';
        $balasan = $this->call('PUT', self::URL, [], [], [], $this->headerJson($this->masuk('rina')), $isi)->assertOk();

        $this->assertSame(4, $balasan->json('data.overall'));
        $this->assertSame([5, 4, 3], $balasan->json('data.aspects'));
    }

    // ---- Penolakan ----

    public function test_admin_dan_teknisi_tidak_boleh_mengirim_survei_403(): void
    {
        foreach (['admin', 'rafi'] as $akun) {
            $this->putJson(self::URL, $this->isiSurvei(), $this->masuk($akun))->assertStatus(403);
        }
        $this->assertDatabaseCount('survei', 0);
    }

    public function test_overall_wajib_bilangan_bulat_1_sampai_5(): void
    {
        $salah = [null, 0, 6, -1, 2.5, '4', true, [], 'bagus'];
        foreach ($salah as $nilai) {
            $this->putJson(self::URL, $this->isiSurvei(['overall' => $nilai]), $this->masuk('rina'))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDASI')
                ->assertJsonPath('error.message', 'Pilih penilaian keseluruhan dulu');
        }
        $this->assertDatabaseCount('survei', 0);
    }

    public function test_overall_tidak_diisi_422_dan_body_kosong_juga_422(): void
    {
        $this->putJson(self::URL, ['resolved' => 'Belum'], $this->masuk('rina'))
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih penilaian keseluruhan dulu');
        $this->put(self::URL, [], $this->masuk('rina') + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilih penilaian keseluruhan dulu');
    }

    public function test_aspek_harus_tiga_bilangan_bulat_0_sampai_5(): void
    {
        $salah = [[1, 2], [1, 2, 3, 4], [1, 2, 6], [1, -1, 2], [1, '2', 3], 'abc', ['a' => 1, 'b' => 2, 'c' => 3]];
        foreach ($salah as $aspek) {
            $this->putJson(self::URL, $this->isiSurvei(['aspects' => $aspek]), $this->masuk('rina'))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDASI')
                ->assertJsonPath('error.message', 'Nilai aspek harus tiga bilangan bulat 0 sampai 5');
        }
        $this->assertDatabaseCount('survei', 0);
    }

    public function test_pilihan_dan_komentar_harus_teks_dengan_panjang_wajar(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(['resolved' => 123]), $this->masuk('rina'))
            ->assertStatus(422)->assertJsonPath('error.message', 'Pilihan terselesaikan tidak valid');
        $this->putJson(self::URL, $this->isiSurvei(['resolved' => str_repeat('a', 31)]), $this->masuk('rina'))->assertStatus(422);
        $this->putJson(self::URL, $this->isiSurvei(['comment' => ['x']]), $this->masuk('rina'))
            ->assertStatus(422)->assertJsonPath('error.message', 'Komentar maksimal 2000 karakter');
        $this->putJson(self::URL, $this->isiSurvei(['comment' => str_repeat('k', 2001)]), $this->masuk('rina'))->assertStatus(422);

        $this->putJson(self::URL, $this->isiSurvei(['comment' => str_repeat('k', 2000)]), $this->masuk('rina'))->assertOk();
    }

    public function test_body_bukan_objek_json_400(): void
    {
        $this->call('PUT', self::URL, [], [], [], $this->headerJson($this->masuk('rina')), '[1,2,3]')
            ->assertStatus(400)->assertJsonPath('error.code', 'JSON_RUSAK');
        $this->call('PUT', self::URL, [], [], [], $this->headerJson($this->masuk('rina')), '{rusak')
            ->assertStatus(400)->assertJsonPath('error.code', 'JSON_RUSAK');
    }

    public function test_survei_gagal_tidak_menimpa_survei_yang_sudah_ada(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(['overall' => 5]), $this->masuk('rina'))->assertOk();
        $this->putJson(self::URL, $this->isiSurvei(['overall' => 0]), $this->masuk('rina'))->assertStatus(422);

        $this->assertSame(5, $this->getJson(self::URL, $this->masuk('rina'))->json('data.overall'));
    }

    public function test_akun_yang_sudah_dihapus_dengan_token_lama_401(): void
    {
        $header = $this->masuk('oky');
        User::where('email', 'oky@smkn1bwi.sch.id')->delete();

        $this->putJson(self::URL, $this->isiSurvei(), $header)->assertStatus(401);
        $this->assertDatabaseCount('survei', 0);
    }

    public function test_menghapus_akun_ikut_menghapus_surveinya(): void
    {
        $this->putJson(self::URL, $this->isiSurvei(), $this->masuk('oky'))->assertOk();
        $this->assertDatabaseCount('survei', 1);

        User::where('email', 'oky@smkn1bwi.sch.id')->delete();

        $this->assertDatabaseCount('survei', 0);
    }

    /** Header mentah untuk $this->call(): kunci server memakai awalan HTTP_ dan CONTENT_TYPE. */
    private function headerJson(array $masuk): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $masuk['Authorization'],
        ];
    }
}
