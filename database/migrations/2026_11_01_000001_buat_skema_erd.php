<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* SKEMA DATABASE SESUAI ERD ANALIS (erd_disty_helpdesk (7).drawio): 13 tabel.
   instansi, pengguna, modul, aduan, penugasan_teknisi, riwayat_aduan, solusi_aduan, artikel_bantuan, survei,
   konsultasi, pesan_konsultasi, riwayat_konsultasi, kuota_konsultasi.
   Padanan database/disty_helpdesk.sql (MySQL langsung): nama tabel, kolom, ENUM, FK, dan CHECK sama.
   Semua tabel memakai id BIGINT AUTO_INCREMENT dan created_at / updated_at (gaya Laravel).
   Pasang dengan: php artisan migrate:fresh --seed */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instansi', function (Blueprint $t) {
            $t->id();
            $t->string('kode_instansi', 20)->unique('uq_instansi_kode');
            $t->string('nama_instansi', 100);
            $t->string('domain_email', 100)->unique('uq_instansi_domain');   // bagian setelah @ pada email klien, huruf kecil
            $t->timestamps();
        });

        Schema::create('pengguna', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 100);
            $t->string('email', 255)->unique('uq_pengguna_email');
            $t->string('password', 255);                                       // hash bcrypt, bukan teks asli
            $t->string('telepon', 20);
            $t->enum('role', ['klien', 'admin', 'teknisi']);
            $t->string('spesialisasi', 50)->nullable();                        // hanya untuk teknisi
            $t->unsignedBigInteger('instansi_id')->nullable()->index('idx_pengguna_instansi');   // hanya klien
            $t->enum('status', ['aktif', 'nonaktif'])->default('nonaktif');
            $t->string('kode_aktivasi', 8)->nullable();
            $t->string('foto', 255)->nullable();
            $t->dateTime('last_login')->nullable();
            $t->timestamps();
            $t->foreign('instansi_id', 'fk_pengguna_instansi')->references('id')->on('instansi')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('modul', function (Blueprint $t) {
            $t->id();
            $t->string('nama_modul', 50)->unique('uq_modul_nama');
            $t->timestamps();
        });

        Schema::create('aduan', function (Blueprint $t) {
            $t->id();
            $t->string('kode_aduan', 10)->unique('uq_aduan_kode');             // HD-014
            $t->unsignedBigInteger('pelapor_id')->index('idx_aduan_pelapor');
            $t->unsignedBigInteger('modul_id')->index('idx_aduan_modul');
            $t->string('kategori', 30);
            $t->string('judul', 100);
            $t->text('deskripsi');
            $t->enum('prioritas', ['Urgent', 'Tinggi', 'Sedang', 'Rendah'])->nullable();
            $t->enum('status', ['Baru', 'Diproses', 'Monitoring', 'Selesai'])->default('Baru')->index('idx_aduan_status');
            $t->string('lampiran', 255)->nullable();
            $t->string('foto_sebelum', 255)->nullable();
            $t->string('foto_sesudah', 255)->nullable();
            $t->tinyInteger('rating')->nullable();
            $t->timestamps();
            $t->foreign('pelapor_id', 'fk_aduan_pelapor')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreign('modul_id', 'fk_aduan_modul')->references('id')->on('modul')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('penugasan_teknisi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aduan_id');
            $t->unsignedBigInteger('teknisi_id')->index('idx_penugasan_teknisi');
            $t->enum('peran', ['utama', 'pendamping']);
            $t->timestamps();
            $t->unique(['aduan_id', 'teknisi_id'], 'uq_penugasan');
            $t->foreign('aduan_id', 'fk_penugasan_aduan')->references('id')->on('aduan')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreign('teknisi_id', 'fk_penugasan_teknisi')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('riwayat_aduan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aduan_id');
            $t->unsignedBigInteger('pengguna_id')->index('idx_riwayat_pengguna');
            $t->text('tindakan');
            $t->timestamps();
            $t->index(['aduan_id', 'created_at'], 'idx_riwayat_aduan');
            $t->foreign('aduan_id', 'fk_riwayat_aduan')->references('id')->on('aduan')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreign('pengguna_id', 'fk_riwayat_pengguna')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('solusi_aduan', function (Blueprint $t) {               // Aduan 1 : 0..1 Solusi
            $t->id();
            $t->unsignedBigInteger('aduan_id')->unique('uq_solusi_aduan');
            $t->string('masalah', 150);
            $t->text('solusi');
            $t->dateTime('tanggal_selesai');
            $t->timestamps();
            $t->foreign('aduan_id', 'fk_solusi_aduan')->references('id')->on('aduan')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('artikel_bantuan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('modul_id')->index('idx_artikel_modul');
            $t->string('judul', 150);
            $t->text('isi');
            $t->timestamps();
            $t->foreign('modul_id', 'fk_artikel_modul')->references('id')->on('modul')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('survei', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pengguna_id')->index('idx_survei_pengguna');
            $t->unsignedBigInteger('aduan_id')->nullable()->index('idx_survei_aduan');
            $t->tinyInteger('nilai_keseluruhan');
            $t->tinyInteger('nilai_respon');
            $t->tinyInteger('nilai_kemudahan');
            $t->tinyInteger('nilai_kualitas');
            $t->enum('terselesaikan', ['Ya', 'Sebagian', 'Belum']);
            $t->text('komentar')->nullable();
            $t->timestamps();
            $t->foreign('pengguna_id', 'fk_survei_pengguna')->references('id')->on('pengguna')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreign('aduan_id', 'fk_survei_aduan')->references('id')->on('aduan')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('konsultasi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pengaju_id')->index('idx_konsultasi_pengaju');
            $t->unsignedBigInteger('teknisi_id')->nullable()->index('idx_konsultasi_teknisi');
            $t->string('topik', 150);
            $t->text('catatan')->nullable();
            $t->date('tanggal');
            $t->string('jam', 5);                                              // format HH:MM, contoh 10:00
            $t->enum('status', ['Menunggu', 'Terjadwal', 'Jadi', 'Tidak', 'Ditolak'])->default('Menunggu');
            $t->tinyInteger('jumlah_ubah_jadwal')->default(0);
            $t->timestamps();
            $t->index(['tanggal', 'jam'], 'idx_konsultasi_jadwal');
            $t->foreign('pengaju_id', 'fk_konsultasi_pengaju')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreign('teknisi_id', 'fk_konsultasi_teknisi')->references('id')->on('pengguna')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('pesan_konsultasi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('konsultasi_id');
            $t->unsignedBigInteger('pengirim_id')->index('idx_pesan_pengirim');
            $t->text('pesan');
            $t->timestamps();
            $t->index(['konsultasi_id', 'created_at'], 'idx_pesan_konsultasi');
            $t->foreign('konsultasi_id', 'fk_pesan_konsultasi')->references('id')->on('konsultasi')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreign('pengirim_id', 'fk_pesan_pengirim')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('riwayat_konsultasi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('konsultasi_id');
            $t->string('aktivitas', 255);
            $t->timestamps();
            $t->index(['konsultasi_id', 'created_at'], 'idx_riwayat_konsultasi');
            $t->foreign('konsultasi_id', 'fk_riwayat_konsultasi')->references('id')->on('konsultasi')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::create('kuota_konsultasi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('diatur_oleh')->index('idx_kuota_admin');
            $t->date('tanggal')->nullable();                                   // NULL = berlaku untuk semua hari kerja
            $t->string('jam', 5);                                              // format HH:MM
            $t->tinyInteger('kuota')->default(2);
            $t->timestamps();
            $t->index(['tanggal', 'jam'], 'idx_kuota_tanggal_jam');
            $t->foreign('diatur_oleh', 'fk_kuota_admin')->references('id')->on('pengguna')->cascadeOnUpdate()->restrictOnDelete();
        });

        $this->batasanCek();
        $this->trigger();
    }

    /** CHECK constraint seperti di SQL (SQLite versi uji tidak mendukung ALTER ... ADD CONSTRAINT, jadi dilewati di sana). */
    private function batasanCek(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE aduan ADD CONSTRAINT chk_aduan_kategori CHECK (kategori IN ('Gangguan / Error','Pengembangan / Update Fitur'))");
        DB::statement('ALTER TABLE aduan ADD CONSTRAINT chk_aduan_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE survei ADD CONSTRAINT chk_survei_nilai CHECK (nilai_keseluruhan BETWEEN 0 AND 5 AND nilai_respon BETWEEN 0 AND 5 AND nilai_kemudahan BETWEEN 0 AND 5 AND nilai_kualitas BETWEEN 0 AND 5)');
    }

    /** Trigger yang sama dengan di SQL: instansi_id klien diisi otomatis dari domain email (hanya MySQL/MariaDB).
     *  Model User melakukan hal yang sama di PHP (pesan galat 422 yang rapi), trigger ini pengaman bila data dimasukkan lewat SQL langsung. */
    private function trigger(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_pengguna_sebelum_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_pengguna_sebelum_update');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_pengguna_sebelum_insert BEFORE INSERT ON pengguna
FOR EACH ROW
BEGIN
  DECLARE v_instansi BIGINT;
  SET NEW.email = LOWER(TRIM(NEW.email));
  IF NEW.role = 'klien' THEN
    SELECT id INTO v_instansi FROM instansi WHERE domain_email = SUBSTRING_INDEX(NEW.email, '@', -1) LIMIT 1;
    IF v_instansi IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Domain email belum terdaftar sebagai instansi';
    END IF;
    SET NEW.instansi_id = v_instansi;
  ELSE
    SET NEW.instansi_id = NULL;
  END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_pengguna_sebelum_update BEFORE UPDATE ON pengguna
FOR EACH ROW
BEGIN
  DECLARE v_instansi BIGINT;
  SET NEW.email = LOWER(TRIM(NEW.email));
  IF NEW.role = 'klien' AND NEW.email <> OLD.email THEN
    SELECT id INTO v_instansi FROM instansi WHERE domain_email = SUBSTRING_INDEX(NEW.email, '@', -1) LIMIT 1;
    IF v_instansi IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Domain email belum terdaftar sebagai instansi';
    END IF;
    SET NEW.instansi_id = v_instansi;
  END IF;
END
SQL);
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_pengguna_sebelum_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_pengguna_sebelum_update');
        }
        foreach ([
            'kuota_konsultasi', 'riwayat_konsultasi', 'pesan_konsultasi', 'konsultasi', 'survei', 'artikel_bantuan',
            'solusi_aduan', 'riwayat_aduan', 'penugasan_teknisi', 'aduan', 'modul', 'pengguna', 'instansi',
        ] as $tabel) {
            Schema::dropIfExists($tabel);
        }
    }
};
