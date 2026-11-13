<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/* Skema database sesuai ERD (database/disty_helpdesk.sql): 13 tabel, kolomnya, relasi, dan data seed. */
class SkemaErdTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tiga_belas_tabel_erd_ada_dan_tabel_lama_tidak(): void
    {
        foreach ([
            'instansi', 'pengguna', 'modul', 'aduan', 'penugasan_teknisi', 'riwayat_aduan', 'solusi_aduan',
            'artikel_bantuan', 'survei', 'konsultasi', 'pesan_konsultasi', 'riwayat_konsultasi', 'kuota_konsultasi',
        ] as $tabel) {
            $this->assertTrue(Schema::hasTable($tabel), "Tabel {$tabel} harus ada");
            $this->assertTrue(Schema::hasColumns($tabel, ['id', 'created_at', 'updated_at']));
        }
        foreach (['tiket', 'teknisi', 'penugasan', 'riwayat_tiket', 'dokumentasi', 'pengumuman', 'laporan_bulanan'] as $lama) {
            $this->assertFalse(Schema::hasTable($lama), "Tabel lama {$lama} tidak boleh ada");
        }
    }

    public function test_kolom_penting_sesuai_erd(): void
    {
        $this->assertTrue(Schema::hasColumns('pengguna', ['nama', 'email', 'password', 'telepon', 'role', 'spesialisasi', 'instansi_id', 'status', 'kode_aktivasi', 'foto', 'last_login']));
        $this->assertTrue(Schema::hasColumns('aduan', ['kode_aduan', 'pelapor_id', 'modul_id', 'kategori', 'judul', 'deskripsi', 'prioritas', 'status', 'lampiran', 'foto_sebelum', 'foto_sesudah', 'rating']));
        $this->assertTrue(Schema::hasColumns('solusi_aduan', ['aduan_id', 'masalah', 'solusi', 'tanggal_selesai']));
        $this->assertTrue(Schema::hasColumns('konsultasi', ['pengaju_id', 'teknisi_id', 'topik', 'catatan', 'tanggal', 'jam', 'status', 'jumlah_ubah_jadwal']));
        $this->assertTrue(Schema::hasColumns('kuota_konsultasi', ['diatur_oleh', 'tanggal', 'jam', 'kuota']));
        $this->assertTrue(Schema::hasColumns('survei', ['pengguna_id', 'aduan_id', 'nilai_keseluruhan', 'nilai_respon', 'nilai_kemudahan', 'nilai_kualitas', 'terselesaikan', 'komentar']));
    }

    public function test_data_seed_sama_dengan_sql(): void
    {
        foreach ([
            'instansi' => 20, 'modul' => 7, 'pengguna' => 24, 'artikel_bantuan' => 11, 'aduan' => 12, 'penugasan_teknisi' => 12,
            'riwayat_aduan' => 19, 'solusi_aduan' => 8, 'konsultasi' => 5, 'pesan_konsultasi' => 6, 'riwayat_konsultasi' => 9,
            'kuota_konsultasi' => 6, 'survei' => 6,
        ] as $tabel => $jumlah) {
            $this->assertDatabaseCount($tabel, $jumlah);
        }
        $this->assertSame(20, User::where('role', 'klien')->whereNotNull('instansi_id')->count());
        $this->assertSame(0, User::whereIn('role', ['admin', 'teknisi'])->whereNotNull('instansi_id')->count());
    }

    public function test_relasi_aduan_pelapor_modul_teknisi_dan_solusi(): void
    {
        $aduan = \App\Models\Aduan::lengkap()->where('kode_aduan', 'HD-006')->first();
        $this->assertSame('Anita', explode(' ', $aduan->pelapor->nama)[1]);
        $this->assertSame('Database', $aduan->modul->nama_modul);
        $this->assertSame(['Rafi Pratama', 'Bayu'], $aduan->teknisi->pluck('nama')->all());
        $this->assertSame(['utama', 'pendamping'], $aduan->teknisi->pluck('pivot.peran')->all());
        $this->assertNotNull($aduan->solusi);
    }

    public function test_foreign_key_restrict_dan_cascade(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');

        // RESTRICT: pengguna yang masih punya aduan tidak bisa dihapus.
        $this->expectException(QueryException::class);
        User::where('email', 'like', 'rina@%')->first()->delete();
    }

    public function test_hapus_aduan_ikut_menghapus_anak_dan_survei_jadi_null(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        $id = DB::table('aduan')->where('kode_aduan', 'HD-001')->value('id');

        DB::table('aduan')->where('id', $id)->delete();

        $this->assertDatabaseMissing('penugasan_teknisi', ['aduan_id' => $id]);
        $this->assertDatabaseMissing('riwayat_aduan', ['aduan_id' => $id]);
        $this->assertDatabaseMissing('solusi_aduan', ['aduan_id' => $id]);
        $this->assertDatabaseHas('survei', ['aduan_id' => null, 'komentar' => 'Cepat dan jelas.']);
    }

    public function test_klien_dengan_domain_tidak_terdaftar_ditolak(): void
    {
        $this->expectExceptionMessage('Domain email belum terdaftar sebagai instansi');
        User::create(['nama' => 'X', 'email' => 'x@gmail.com', 'password' => 'x', 'telepon' => '1', 'role' => 'klien']);
    }

    public function test_instansi_klien_diisi_dari_domain_email_dan_ikut_berubah_saat_email_diganti(): void
    {
        $akun = User::create(['nama' => 'Uji Baru', 'email' => 'UJI@SMKN1SDA.sch.id', 'password' => 'x', 'telepon' => '1', 'role' => 'klien']);
        $this->assertSame('uji@smkn1sda.sch.id', $akun->email);
        $this->assertSame('SMKN1-SDA', $akun->fresh()->kode_instansi);

        $akun->update(['email' => 'uji@sman1sby.sch.id']);
        $this->assertSame('SMAN1-SBY', $akun->fresh()->kode_instansi);

        $staf = User::create(['nama' => 'Staf', 'email' => 'staf@gmail.com', 'password' => 'x', 'telepon' => '1', 'role' => 'teknisi', 'instansi_id' => 1]);
        $this->assertNull($staf->fresh()->instansi_id);   // admin dan teknisi tidak punya instansi
    }
}
