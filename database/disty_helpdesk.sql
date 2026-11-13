-- =====================================================================================
-- DISTY HELPDESK : DATABASE SESUAI ERD ANALIS (erd_disty_helpdesk (7).drawio)
-- MySQL 8 / MariaDB 10.5+   |   Nama database: disty_helpdesk (sama dengan DB_DATABASE di .env Laravel)
--
-- CARA PAKAI
--   mysql -u root < disty_helpdesk.sql
--   atau phpMyAdmin > tab SQL > tempel seluruh isi berkas ini (DELIMITER di trigger didukung).
--
-- PERINGATAN: skrip ini MENGHAPUS database disty_helpdesk lalu membuatnya ulang (DROP DATABASE).
--             Jangan dijalankan di database yang isinya masih dipakai.
--
-- 13 tabel dari ERD: instansi, pengguna, modul, aduan, penugasan_teknisi, riwayat_aduan, solusi_aduan,
--   artikel_bantuan, survei, konsultasi, pesan_konsultasi, riwayat_konsultasi, kuota_konsultasi.
-- Semua tabel memakai id BIGINT AUTO_INCREMENT dan created_at / updated_at (gaya Laravel).
--
-- PERUBAHAN DARI VERSI SQL SEBELUMNYA (mengikuti ERD terbaru):
--   tiket -> aduan (id_tiket -> kode_aduan), penugasan -> penugasan_teknisi, riwayat_tiket -> riwayat_aduan
--   teknisi digabung ke pengguna (kolom spesialisasi), prioritas jadi ENUM di aduan, dokumentasi jadi foto_sebelum / foto_sesudah
--   pengumuman, laporan_bulanan, urutan_customer, kode_customer DIHAPUS (tidak ada di ERD)
--   BARU: solusi_aduan, riwayat_konsultasi, kuota_konsultasi
-- Trigger tetap ada: instansi_id klien diisi OTOMATIS dari domain email (tabel instansi).
-- =====================================================================================

DROP DATABASE IF EXISTS disty_helpdesk;
CREATE DATABASE disty_helpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE disty_helpdesk;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== TABEL ERD =====
CREATE TABLE instansi (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  kode_instansi VARCHAR(20)  NOT NULL,
  nama_instansi VARCHAR(100) NOT NULL,
  domain_email  VARCHAR(100) NOT NULL,          -- bagian setelah @ pada email klien, huruf kecil
  created_at    TIMESTAMP    NULL DEFAULT NULL,
  updated_at    TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_instansi_kode (kode_instansi),
  UNIQUE KEY uq_instansi_domain (domain_email)
) ENGINE=InnoDB;

CREATE TABLE pengguna (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  nama          VARCHAR(100) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password      VARCHAR(255) NOT NULL,          -- hash bcrypt, bukan teks asli
  telepon       VARCHAR(20)  NOT NULL,
  role          ENUM('klien','admin','teknisi') NOT NULL,
  spesialisasi  VARCHAR(50)  NULL,              -- hanya untuk teknisi
  instansi_id   BIGINT       NULL,              -- hanya klien, diisi trigger dari domain email
  status        ENUM('aktif','nonaktif') NOT NULL DEFAULT 'nonaktif',
  kode_aktivasi VARCHAR(8)   NULL,
  foto          VARCHAR(255) NULL,
  last_login    DATETIME     NULL,
  created_at    TIMESTAMP    NULL DEFAULT NULL,
  updated_at    TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pengguna_email (email),
  KEY idx_pengguna_instansi (instansi_id),
  CONSTRAINT fk_pengguna_instansi FOREIGN KEY (instansi_id) REFERENCES instansi (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE modul (
  id         BIGINT      NOT NULL AUTO_INCREMENT,
  nama_modul VARCHAR(50) NOT NULL,
  created_at TIMESTAMP   NULL DEFAULT NULL,
  updated_at TIMESTAMP   NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_modul_nama (nama_modul)
) ENGINE=InnoDB;

CREATE TABLE aduan (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  kode_aduan  VARCHAR(10)  NOT NULL,            -- HD-014
  pelapor_id  BIGINT       NOT NULL,
  modul_id    BIGINT       NOT NULL,
  kategori    VARCHAR(30)  NOT NULL,
  judul       VARCHAR(100) NOT NULL,
  deskripsi   TEXT         NOT NULL,
  prioritas   ENUM('Urgent','Tinggi','Sedang','Rendah') NULL,
  status      ENUM('Baru','Diproses','Monitoring','Selesai') NOT NULL DEFAULT 'Baru',
  kanal       ENUM('web','telepon') NOT NULL DEFAULT 'web',   -- telepon = dicatat teknisi setelah klien menelepon langsung (tanpa penugasan admin)
  menunggu_verifikasi TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = laporan pengerjaan aduan telepon menunggu diperiksa admin
  lampiran    VARCHAR(255) NULL,
  foto_sebelum VARCHAR(255) NULL,
  foto_sesudah VARCHAR(255) NULL,
  rating      TINYINT      NULL,
  created_at  TIMESTAMP    NULL DEFAULT NULL,
  updated_at  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aduan_kode (kode_aduan),
  KEY idx_aduan_pelapor (pelapor_id),
  KEY idx_aduan_modul (modul_id),
  KEY idx_aduan_status (status),
  CONSTRAINT chk_aduan_kategori CHECK (kategori IN ('Gangguan / Error','Pengembangan / Update Fitur')),
  CONSTRAINT chk_aduan_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
  CONSTRAINT fk_aduan_pelapor FOREIGN KEY (pelapor_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_aduan_modul   FOREIGN KEY (modul_id)   REFERENCES modul (id)    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE penugasan_teknisi (
  id         BIGINT    NOT NULL AUTO_INCREMENT,
  aduan_id   BIGINT    NOT NULL,
  teknisi_id BIGINT    NOT NULL,
  peran      ENUM('utama','pendamping') NOT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_penugasan (aduan_id, teknisi_id),
  KEY idx_penugasan_teknisi (teknisi_id),
  CONSTRAINT fk_penugasan_aduan   FOREIGN KEY (aduan_id)   REFERENCES aduan (id)    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_penugasan_teknisi FOREIGN KEY (teknisi_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE riwayat_aduan (
  id          BIGINT    NOT NULL AUTO_INCREMENT,
  aduan_id    BIGINT    NOT NULL,
  pengguna_id BIGINT    NOT NULL,
  tindakan    TEXT      NOT NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_riwayat_aduan (aduan_id, created_at),
  KEY idx_riwayat_pengguna (pengguna_id),
  CONSTRAINT fk_riwayat_aduan    FOREIGN KEY (aduan_id)    REFERENCES aduan (id)    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_riwayat_pengguna FOREIGN KEY (pengguna_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE solusi_aduan (                     -- Aduan 1 : 0..1 Solusi
  id              BIGINT       NOT NULL AUTO_INCREMENT,
  aduan_id        BIGINT       NOT NULL,
  masalah         VARCHAR(150) NOT NULL,
  solusi          TEXT         NOT NULL,
  tanggal_selesai DATETIME     NOT NULL,
  created_at      TIMESTAMP    NULL DEFAULT NULL,
  updated_at      TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_solusi_aduan (aduan_id),
  CONSTRAINT fk_solusi_aduan FOREIGN KEY (aduan_id) REFERENCES aduan (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE artikel_bantuan (
  id         BIGINT       NOT NULL AUTO_INCREMENT,
  modul_id   BIGINT       NOT NULL,
  judul      VARCHAR(150) NOT NULL,
  isi        TEXT         NOT NULL,
  created_at TIMESTAMP    NULL DEFAULT NULL,
  updated_at TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_artikel_modul (modul_id),
  CONSTRAINT fk_artikel_modul FOREIGN KEY (modul_id) REFERENCES modul (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE survei (
  id                BIGINT    NOT NULL AUTO_INCREMENT,
  pengguna_id       BIGINT    NOT NULL,
  aduan_id          BIGINT    NULL,
  nilai_keseluruhan TINYINT   NOT NULL,
  nilai_respon      TINYINT   NOT NULL,
  nilai_kemudahan   TINYINT   NOT NULL,
  nilai_kualitas    TINYINT   NOT NULL,
  terselesaikan     ENUM('Ya','Sebagian','Belum') NOT NULL,
  komentar          TEXT      NULL,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  updated_at        TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_survei_pengguna (pengguna_id),
  KEY idx_survei_aduan (aduan_id),
  CONSTRAINT chk_survei_nilai CHECK (nilai_keseluruhan BETWEEN 0 AND 5 AND nilai_respon BETWEEN 0 AND 5
                                 AND nilai_kemudahan BETWEEN 0 AND 5 AND nilai_kualitas BETWEEN 0 AND 5),
  CONSTRAINT fk_survei_pengguna FOREIGN KEY (pengguna_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_survei_aduan    FOREIGN KEY (aduan_id)    REFERENCES aduan (id)    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE konsultasi (
  id                 BIGINT       NOT NULL AUTO_INCREMENT,
  pengaju_id         BIGINT       NOT NULL,
  teknisi_id         BIGINT       NULL,
  topik              VARCHAR(150) NOT NULL,
  catatan            TEXT         NULL,
  tanggal            DATE         NOT NULL,
  jam                VARCHAR(5)   NOT NULL,      -- format HH:MM, contoh 10:00
  status             ENUM('Menunggu','Terjadwal','Jadi','Tidak','Ditolak') NOT NULL DEFAULT 'Menunggu',
  jumlah_ubah_jadwal TINYINT      NOT NULL DEFAULT 0,
  created_at         TIMESTAMP    NULL DEFAULT NULL,
  updated_at         TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_konsultasi_pengaju (pengaju_id),
  KEY idx_konsultasi_teknisi (teknisi_id),
  KEY idx_konsultasi_jadwal (tanggal, jam),
  CONSTRAINT fk_konsultasi_pengaju FOREIGN KEY (pengaju_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_konsultasi_teknisi FOREIGN KEY (teknisi_id) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE pesan_konsultasi (
  id            BIGINT    NOT NULL AUTO_INCREMENT,
  konsultasi_id BIGINT    NOT NULL,
  pengirim_id   BIGINT    NOT NULL,
  pesan         TEXT      NOT NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_pesan_konsultasi (konsultasi_id, created_at),
  KEY idx_pesan_pengirim (pengirim_id),
  CONSTRAINT fk_pesan_konsultasi FOREIGN KEY (konsultasi_id) REFERENCES konsultasi (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pesan_pengirim   FOREIGN KEY (pengirim_id)   REFERENCES pengguna (id)   ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE riwayat_konsultasi (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  konsultasi_id BIGINT       NOT NULL,
  aktivitas     VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP    NULL DEFAULT NULL,
  updated_at    TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_riwayat_konsultasi (konsultasi_id, created_at),
  CONSTRAINT fk_riwayat_konsultasi FOREIGN KEY (konsultasi_id) REFERENCES konsultasi (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE kuota_konsultasi (
  id          BIGINT     NOT NULL AUTO_INCREMENT,
  diatur_oleh BIGINT     NOT NULL,
  tanggal     DATE       NULL,                  -- NULL = berlaku untuk semua hari kerja
  jam         VARCHAR(5) NOT NULL,              -- format HH:MM
  kuota       TINYINT    NOT NULL DEFAULT 2,
  created_at  TIMESTAMP  NULL DEFAULT NULL,
  updated_at  TIMESTAMP  NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_kuota_tanggal_jam (tanggal, jam),
  KEY idx_kuota_admin (diatur_oleh),
  CONSTRAINT fk_kuota_admin FOREIGN KEY (diatur_oleh) REFERENCES pengguna (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =====================================================================================
-- TRIGGER: klien cukup memasukkan email instansi. instansi_id terisi otomatis dari domain email.
--   email  ...@smkn1sda.sch.id  -> domain smkn1sda.sch.id -> dicari di instansi.domain_email -> instansi_id
--   Domain tidak terdaftar -> pendaftaran DITOLAK (error 45000). Teknisi dan admin tidak punya instansi.
-- =====================================================================================
DELIMITER $$

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
END$$

-- Bila email klien diganti, instansinya ikut dicocokkan ulang.
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
END$$

DELIMITER ;

-- =====================================================================================
-- DATA DUMMY. Semua kata sandi dummy: rahasia123 (hash bcrypt, HANYA untuk pengembangan).
-- =====================================================================================
START TRANSACTION;

-- 20 kode instansi
INSERT INTO instansi (kode_instansi, nama_instansi, domain_email, created_at, updated_at) VALUES
  ('SMKN2-SBY', 'SMK Negeri 2 Surabaya', 'smkn2.sch.id', NOW(), NOW()),
  ('SMAN5-SBY', 'SMA Negeri 5 Surabaya', 'sman5sby.sch.id', NOW(), NOW()),
  ('MAN1-SDA', 'MAN 1 Sidoarjo', 'man1sda.sch.id', NOW(), NOW()),
  ('SMKN1-SDA', 'SMK Negeri 1 Sidoarjo', 'smkn1sda.sch.id', NOW(), NOW()),
  ('SMAN1-SDA', 'SMA Negeri 1 Sidoarjo', 'sman1sda.sch.id', NOW(), NOW()),
  ('SMKN1-SBY', 'SMK Negeri 1 Surabaya', 'smkn1sby.sch.id', NOW(), NOW()),
  ('SMAN1-SBY', 'SMA Negeri 1 Surabaya', 'sman1sby.sch.id', NOW(), NOW()),
  ('SMAN3-SBY', 'SMA Negeri 3 Surabaya', 'sman3sby.sch.id', NOW(), NOW()),
  ('SMKN1-MLG', 'SMK Negeri 1 Malang', 'smkn1mlg.sch.id', NOW(), NOW()),
  ('SMAN3-MLG', 'SMA Negeri 3 Malang', 'sman3mlg.sch.id', NOW(), NOW()),
  ('SMAN1-MLG', 'SMA Negeri 1 Malang', 'sman1mlg.sch.id', NOW(), NOW()),
  ('SMKN4-MLG', 'SMK Negeri 4 Malang', 'smkn4mlg.sch.id', NOW(), NOW()),
  ('MAN1-MLG', 'MAN 1 Kota Malang', 'man1mlg.sch.id', NOW(), NOW()),
  ('SMKN1-GRS', 'SMK Negeri 1 Gresik', 'smkn1grs.sch.id', NOW(), NOW()),
  ('SMAN1-GRS', 'SMA Negeri 1 Gresik', 'sman1grs.sch.id', NOW(), NOW()),
  ('SMKN1-MJK', 'SMK Negeri 1 Mojokerto', 'smkn1mjk.sch.id', NOW(), NOW()),
  ('SMAN1-PSR', 'SMA Negeri 1 Pasuruan', 'sman1psr.sch.id', NOW(), NOW()),
  ('SMKN2-KDR', 'SMK Negeri 2 Kediri', 'smkn2kdr.sch.id', NOW(), NOW()),
  ('SMAN2-JBG', 'SMA Negeri 2 Jombang', 'sman2jbg.sch.id', NOW(), NOW()),
  ('SMKN1-BWI', 'SMK Negeri 1 Banyuwangi', 'smkn1bwi.sch.id', NOW(), NOW());

INSERT INTO modul (nama_modul, created_at, updated_at) VALUES ('Database',NOW(),NOW()),('Login / Akun',NOW(),NOW()),('Laporan',NOW(),NOW()),('Pembayaran',NOW(),NOW()),('Tampilan',NOW(),NOW()),('Jaringan',NOW(),NOW()),('Lainnya',NOW(),NOW());

-- Staf (admin dan teknisi): tanpa instansi. Teknisi punya spesialisasi.
INSERT INTO pengguna (nama, email, password, telepon, role, spesialisasi, status, created_at, updated_at) VALUES
  ('Admin Helpdesk', 'admin@perusahaan.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '081230000001', 'admin', NULL, 'aktif', NOW(), NOW()),
  ('Rafi Pratama', 'rafi@perusahaan.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '081230000002', 'teknisi', 'Database', 'aktif', NOW(), NOW()),
  ('Dina', 'dina@perusahaan.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '081230000003', 'teknisi', 'Frontend', 'aktif', NOW(), NOW()),
  ('Bayu', 'bayu@perusahaan.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '081230000004', 'teknisi', 'Server & Jaringan', 'aktif', NOW(), NOW());

-- 20 klien. instansi_id TIDAK ditulis: diisi trigger dari domain email.
INSERT INTO pengguna (nama, email, password, telepon, role, status, created_at, updated_at) VALUES
  ('Bu Rina', 'rina@smkn2.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000001', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Yusuf', 'yusuf@sman5sby.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000002', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Nur', 'nur@man1sda.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000003', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Agus', 'agus@smkn1sda.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000004', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Wulan', 'wulan@sman1sda.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000005', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Dimas', 'dimas@smkn1sby.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000006', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Anita', 'anita@sman1sby.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000007', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Bagas', 'bagas@sman3sby.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000008', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Citra', 'citra@smkn1mlg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000009', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Eko', 'eko@sman3mlg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000010', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Fitri', 'fitri@sman1mlg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000011', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Gunawan', 'gunawan@smkn4mlg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000012', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Hesti', 'hesti@man1mlg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000013', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Irfan', 'irfan@smkn1grs.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000014', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Juwita', 'juwita@sman1grs.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000015', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Kurniawan', 'kurniawan@smkn1mjk.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000016', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Lestari', 'lestari@sman1psr.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000017', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Mahendra', 'mahendra@smkn2kdr.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000018', 'klien', 'aktif', NOW(), NOW()),
  ('Bu Novi', 'novi@sman2jbg.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000019', 'klien', 'aktif', NOW(), NOW()),
  ('Pak Oky', 'oky@smkn1bwi.sch.id', '$2y$10$OdD0VmWtZ5iySz3GBLDjO.rwu5ttXbfS4sWHP8pKHQq2XSsrLhDXi', '085700000020', 'klien', 'aktif', NOW(), NOW());

INSERT INTO artikel_bantuan (modul_id, judul, isi, created_at, updated_at) VALUES
  ((SELECT id FROM modul WHERE nama_modul='Database'), 'Data tidak tersimpan setelah klik simpan', 'Masalah ini biasanya muncul karena koneksi ke database terputus, tabel sedang terkunci, atau ada kolom wajib yang belum terisi.

Langkah pemeriksaan:
1. Pastikan semua kolom bertanda wajib sudah terisi dan formatnya benar, misalnya tanggal dan angka.
2. Muat ulang halaman, lalu cek apakah data yang tadi disimpan sudah muncul di daftar.
3. Periksa koneksi database dengan mengikuti artikel Cara memeriksa koneksi database.
4. Coba simpan ulang dengan jumlah data yang lebih sedikit. Bila berhasil, kemungkinan ada satu data yang formatnya bermasalah.
5. Jika masih gagal, buat aduan dengan kategori Gangguan / Error pada modul Database.

Yang perlu dilampirkan di aduan: tangkapan layar pesan galat, waktu kejadian, dan nama menu tempat data disimpan. Informasi ini membantu teknisi menemukan penyebabnya lebih cepat.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Database'), 'Cara memeriksa koneksi database', 'Uji koneksi dipakai untuk memastikan aplikasi masih bisa berkomunikasi dengan server database.

Langkah-langkah:
1. Buka menu pengaturan sistem.
2. Pilih bagian database, lalu klik tombol uji koneksi.
3. Tunggu sampai hasil uji muncul.
4. Bila berhasil, koneksi aman dan masalah kemungkinan ada di bagian lain.
5. Bila gagal, catat pesan galat yang tampil, misalnya connection refused atau access denied.

Arti pesan yang sering muncul:
- Connection refused: server database tidak aktif atau alamat dan port salah.
- Access denied: nama pengguna atau kata sandi database salah.
- Unknown database: nama database yang diisi belum dibuat atau salah ketik.

Sertakan pesan galat tersebut saat membuat aduan agar teknisi tidak perlu menebak penyebabnya.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Login / Akun'), 'Lupa kata sandi', 'Kalau lupa kata sandi, kamu bisa melakukan reset sandi sendiri tanpa menghubungi admin.

Langkah-langkah:
1. Buka halaman masuk, lalu klik Lupa kata sandi.
2. Masukkan email instansi yang terdaftar di sistem.
3. Buka kotak masuk email dan cari pesan berisi kode atau tautan reset. Cek juga folder spam bila belum ada.
4. Ikuti petunjuk di email, lalu buat kata sandi baru.
5. Masuk kembali memakai kata sandi baru.

Tips membuat kata sandi yang kuat:
- Gunakan minimal 8 karakter.
- Gabungkan huruf besar, huruf kecil, angka, dan simbol.
- Hindari tanggal lahir, nama sendiri, atau urutan angka seperti 12345678.
- Jangan memakai kata sandi yang sama dengan akun lain.

Jika email reset tidak kunjung masuk setelah beberapa menit, pastikan alamat email yang diketik sudah benar, lalu hubungi admin helpdesk.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Login / Akun'), 'Akun terkunci setelah salah sandi', 'Sistem mengunci akun sementara setelah beberapa kali salah memasukkan kata sandi. Tujuannya melindungi akun dari upaya masuk oleh pihak yang tidak berhak.

Yang perlu dilakukan:
1. Tunggu 15 menit tanpa mencoba masuk lagi, karena percobaan tambahan bisa memperpanjang waktu kunci.
2. Setelah 15 menit, coba masuk dengan kata sandi yang benar.
3. Bila lupa kata sandi, gunakan fitur Lupa kata sandi di halaman masuk.
4. Bila akun masih terkunci, hubungi admin helpdesk dan sebutkan email akun yang terkunci.

Pencegahan: simpan kata sandi di aplikasi pengelola kata sandi dan pastikan tombol Caps Lock tidak aktif saat mengetik.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Laporan'), 'Laporan kosong atau tidak muncul', 'Laporan yang kosong biasanya bukan kerusakan sistem. Penyebab paling umum adalah filter yang belum sesuai.

Cek satu per satu:
1. Pastikan rentang tanggal sudah benar. Jangan sampai tanggal awal lebih besar dari tanggal akhir.
2. Pastikan pada periode tersebut memang ada data yang tercatat.
3. Periksa filter lain seperti kategori, status, atau instansi, lalu coba kosongkan filter.
4. Muat ulang halaman dengan menekan Ctrl + F5 agar tampilan terbaru dimuat.
5. Coba buka dengan browser lain untuk memastikan masalah bukan dari browser.

Jika semua langkah sudah dicoba tetapi laporan tetap kosong, buat aduan pada modul Laporan. Lampirkan tangkapan layar filter yang dipakai agar teknisi bisa mengulang kondisi yang sama.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Laporan'), 'Cara mengunduh laporan PDF', 'Laporan dapat disimpan dalam bentuk berkas PDF untuk dicetak atau dibagikan.

Langkah-langkah:
1. Buka menu laporan.
2. Pilih periode atau rentang tanggal yang dibutuhkan.
3. Tampilkan laporan terlebih dahulu dan pastikan isinya sudah sesuai.
4. Klik tombol Unduh, lalu pilih format PDF.
5. Tunggu proses selesai, kemudian cek berkas di folder Unduhan pada perangkat.

Bila unduhan tidak berjalan, periksa apakah browser memblokir pop-up atau unduhan otomatis, lalu izinkan untuk situs ini. Pastikan juga penyimpanan perangkat masih cukup.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Pembayaran'), 'Pembayaran e-wallet belum masuk', 'Pembayaran lewat e-wallet kadang butuh waktu karena harus dikonfirmasi oleh penyedia layanan.

Langkah yang disarankan:
1. Tunggu sampai 30 menit setelah pembayaran dilakukan.
2. Cek riwayat transaksi di aplikasi e-wallet dan pastikan saldo benar-benar sudah terpotong.
3. Muat ulang halaman status pembayaran di sistem.
4. Bila status tetap pending setelah 30 menit, buat aduan pada modul Pembayaran.

Lampirkan bukti pembayaran berupa tangkapan layar yang menampilkan nominal, tanggal, jam, dan nomor referensi transaksi. Hindari membayar ulang sebelum ada kepastian dari tim agar tidak terjadi pembayaran ganda.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Pembayaran'), 'Status transaksi pending', 'Status pending berarti pembayaran sudah dikirim tetapi belum dikonfirmasi oleh penyedia pembayaran.

Hal yang perlu diketahui:
- Pending bukan berarti gagal. Sistem masih menunggu jawaban dari penyedia.
- Jangan bayar ulang sebelum 1 jam, karena bisa menyebabkan pembayaran ganda.
- Selama menunggu, simpan bukti pembayaran dan nomor referensi transaksi.

Langkah lanjutan:
1. Tunggu minimal 1 jam, lalu muat ulang halaman status transaksi.
2. Jika status berubah menjadi berhasil, tidak ada yang perlu dilakukan lagi.
3. Jika masih pending atau berubah menjadi gagal padahal saldo terpotong, buat aduan pada modul Pembayaran beserta bukti pembayaran.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Tampilan'), 'Tampilan berantakan di HP', 'Tampilan yang tidak rapi di ponsel biasanya disebabkan browser versi lama atau cache lama yang masih tersimpan.

Langkah perbaikan:
1. Perbarui browser ke versi terbaru lewat Google Play Store atau App Store.
2. Bersihkan cache browser dengan mengikuti artikel Membersihkan cache browser.
3. Putar layar ke mode potret atau lanskap, lalu muat ulang halaman.
4. Coba buka lewat browser lain untuk membandingkan hasilnya.
5. Pastikan mode hemat data atau mode baca tidak sedang aktif, karena bisa mengubah tampilan.

Bila masih berantakan, buat aduan pada modul Tampilan. Sertakan tangkapan layar, tipe atau merek HP, versi sistem operasi, dan nama browser yang dipakai.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Tampilan'), 'Membersihkan cache browser', 'Cache adalah data sementara yang disimpan browser agar halaman terbuka lebih cepat. Cache lama kadang membuat tampilan atau data tidak ikut diperbarui.

Di komputer (Chrome atau Edge):
1. Tekan Ctrl + Shift + Delete.
2. Pilih rentang waktu, misalnya Semua waktu.
3. Centang gambar dan berkas dalam cache.
4. Klik Hapus data, lalu muat ulang halaman.

Di ponsel:
1. Buka pengaturan browser, lalu pilih hapus data penelusuran.
2. Centang gambar dan berkas tersimpan.
3. Konfirmasi penghapusan, lalu buka kembali situs ini.

Catatan: jangan centang kata sandi tersimpan kecuali memang ingin menghapusnya. Setelah cache dihapus, halaman pertama kali dibuka mungkin terasa sedikit lebih lambat.', NOW(), NOW()),
  ((SELECT id FROM modul WHERE nama_modul='Lainnya'), 'Panduan penggunaan sistem', 'Disty HelpDesk membantu klien menyampaikan kendala IT dan memantau penanganannya.

Alur penggunaan:
1. Masuk memakai email instansi dan kata sandi.
2. Buat aduan: pilih kategori dan modul, tulis judul serta deskripsi masalah, lalu lampirkan tangkapan layar bila ada.
3. Pantau status aduan di dashboard. Setiap perubahan status dan tindakan teknisi tercatat di riwayat.
4. Ajukan konsultasi bila butuh diskusi langsung dengan teknisi. Pilih tanggal dan jam yang tersedia.
5. Isi survei setelah aduan selesai untuk menilai kecepatan respon, kemudahan, dan kualitas penanganan.

Tips menulis aduan yang cepat ditangani:
- Gunakan judul yang spesifik, misalnya Printer lab tidak terhubung ke jaringan.
- Jelaskan langkah yang sudah dicoba.
- Sebutkan waktu mulai masalah terjadi.
- Cari dulu di Pusat Bantuan, siapa tahu jawabannya sudah ada.', NOW(), NOW());

INSERT INTO aduan (kode_aduan, pelapor_id, modul_id, kategori, judul, deskripsi, prioritas, status, lampiran, foto_sebelum, foto_sesudah, rating, created_at, updated_at) VALUES
  ('HD-001', (SELECT id FROM pengguna WHERE email='rina@smkn2.sch.id'), (SELECT id FROM modul WHERE nama_modul='Jaringan'), 'Gangguan / Error', 'Printer lab tidak terhubung ke jaringan', 'Printer di lab komputer tidak terdeteksi oleh semua komputer.', 'Sedang', 'Selesai', NULL, NULL, NULL, 4, '2026-09-08 02:00:00', '2026-09-15 07:00:00'),
  ('HD-002', (SELECT id FROM pengguna WHERE email='yusuf@sman5sby.sch.id'), (SELECT id FROM modul WHERE nama_modul='Login / Akun'), 'Gangguan / Error', 'Guru lupa kata sandi akun', 'Tiga guru tidak bisa masuk karena lupa kata sandi.', 'Rendah', 'Selesai', NULL, NULL, NULL, 5, '2026-09-01 01:00:00', '2026-09-02 04:00:00'),
  ('HD-003', (SELECT id FROM pengguna WHERE email='nur@man1sda.sch.id'), (SELECT id FROM modul WHERE nama_modul='Database'), 'Gangguan / Error', 'Data tidak tersimpan setelah import', 'Import data siswa dari Excel selesai tetapi data tidak muncul.', 'Tinggi', 'Selesai', NULL, NULL, NULL, 4, '2026-08-03 02:00:00', '2026-08-03 09:00:00'),
  ('HD-004', (SELECT id FROM pengguna WHERE email='wulan@sman1sda.sch.id'), (SELECT id FROM modul WHERE nama_modul='Laporan'), 'Gangguan / Error', 'Cetak surat keterangan tidak muncul', 'Nama siswa kosong pada surat keterangan hasil cetak.', 'Sedang', 'Selesai', NULL, NULL, NULL, 5, '2026-09-08 03:00:00', '2026-09-09 08:00:00'),
  ('HD-005', (SELECT id FROM pengguna WHERE email='dimas@smkn1sby.sch.id'), (SELECT id FROM modul WHERE nama_modul='Tampilan'), 'Gangguan / Error', 'Tampilan menu rusak di HP', 'Menu tertutup di layar kecil.', 'Rendah', 'Selesai', NULL, 'dokumentasi/HD-005-sebelum.jpg', 'dokumentasi/HD-005-sesudah.jpg', 4, '2026-09-18 02:00:00', '2026-09-18 07:00:00'),
  ('HD-006', (SELECT id FROM pengguna WHERE email='anita@sman1sby.sch.id'), (SELECT id FROM modul WHERE nama_modul='Database'), 'Gangguan / Error', 'Query failed di rekap nilai', 'Muncul pesan Query failed saat membuka rekap nilai.', 'Tinggi', 'Selesai', NULL, NULL, NULL, 5, '2026-09-12 01:30:00', '2026-09-12 09:30:00'),
  ('HD-007', (SELECT id FROM pengguna WHERE email='bagas@sman3sby.sch.id'), (SELECT id FROM modul WHERE nama_modul='Database'), 'Gangguan / Error', 'Database absensi lambat', 'Rekap absensi butuh waktu lama.', 'Sedang', 'Selesai', NULL, 'dokumentasi/HD-007-sebelum.jpg', 'dokumentasi/HD-007-sesudah.jpg', 5, '2026-09-20 02:00:00', '2026-09-20 09:00:00'),
  ('HD-008', (SELECT id FROM pengguna WHERE email='citra@smkn1mlg.sch.id'), (SELECT id FROM modul WHERE nama_modul='Login / Akun'), 'Gangguan / Error', 'Login guru lambat', 'Butuh sekitar 1 menit untuk masuk.', 'Sedang', 'Selesai', NULL, NULL, 'dokumentasi/HD-008-sesudah.jpg', 4, '2026-09-28 01:00:00', '2026-09-28 06:00:00'),
  ('HD-009', (SELECT id FROM pengguna WHERE email='eko@sman3mlg.sch.id'), (SELECT id FROM modul WHERE nama_modul='Database'), 'Gangguan / Error', 'Database laporan nilai error', 'Pesan Query failed di rekap nilai semester.', 'Tinggi', 'Monitoring', NULL, NULL, NULL, NULL, '2026-09-27 03:00:00', '2026-09-27 03:00:00'),
  ('HD-010', (SELECT id FROM pengguna WHERE email='fitri@sman1mlg.sch.id'), (SELECT id FROM modul WHERE nama_modul='Database'), 'Gangguan / Error', 'Data siswa tidak tersimpan', 'Setelah klik simpan, data siswa baru hilang saat halaman dimuat ulang.', 'Tinggi', 'Diproses', NULL, NULL, NULL, NULL, '2026-09-30 02:10:00', '2026-09-30 02:10:00'),
  ('HD-011', (SELECT id FROM pengguna WHERE email='gunawan@smkn4mlg.sch.id'), (SELECT id FROM modul WHERE nama_modul='Laporan'), 'Pengembangan / Update Fitur', 'Tambah fitur cetak rapor PDF', 'Ingin rapor bisa diunduh PDF.', 'Rendah', 'Baru', NULL, NULL, NULL, NULL, '2026-10-01 00:30:00', '2026-10-01 00:30:00'),
  ('HD-012', (SELECT id FROM pengguna WHERE email='hesti@man1mlg.sch.id'), (SELECT id FROM modul WHERE nama_modul='Pembayaran'), 'Gangguan / Error', 'Pembayaran SPP e-wallet tidak tercatat', 'Orang tua sudah bayar tetapi status SPP masih belum lunas.', 'Urgent', 'Diproses', NULL, NULL, NULL, NULL, '2026-10-20 01:00:00', '2026-10-20 01:00:00');

INSERT INTO penugasan_teknisi (aduan_id, teknisi_id, peran, created_at, updated_at) VALUES
  ((SELECT id FROM aduan WHERE kode_aduan='HD-001'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'utama', '2026-09-08 03:00:00', '2026-09-08 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-002'), (SELECT id FROM pengguna WHERE email='dina@perusahaan.id'), 'utama', '2026-09-01 02:00:00', '2026-09-01 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-003'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-08-03 03:00:00', '2026-08-03 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-004'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-09-08 04:00:00', '2026-09-08 04:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-005'), (SELECT id FROM pengguna WHERE email='dina@perusahaan.id'), 'utama', '2026-09-18 03:00:00', '2026-09-18 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-006'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-09-12 02:00:00', '2026-09-12 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-006'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'pendamping', '2026-09-12 02:00:00', '2026-09-12 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-007'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-09-20 03:00:00', '2026-09-20 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-008'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'utama', '2026-09-28 02:00:00', '2026-09-28 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-009'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-09-27 04:00:00', '2026-09-27 04:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-010'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'utama', '2026-09-30 03:00:00', '2026-09-30 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-012'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'utama', '2026-10-20 01:30:00', '2026-10-20 01:30:00');

INSERT INTO riwayat_aduan (aduan_id, pengguna_id, tindakan, created_at, updated_at) VALUES
  ((SELECT id FROM aduan WHERE kode_aduan='HD-010'), (SELECT id FROM pengguna WHERE email='fitri@sman1mlg.sch.id'), 'Aduan dikirim', '2026-09-30 02:10:00', '2026-09-30 02:10:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-010'), (SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), 'Aduan diteruskan ke Rafi (Teknisi)', '2026-09-30 03:00:00', '2026-09-30 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-010'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Telepon klien (offline) untuk konfirmasi gejala', '2026-09-30 04:00:00', '2026-09-30 04:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-010'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Ditemukan tabel siswa terkunci, sedang diperbaiki', '2026-09-30 07:00:00', '2026-09-30 07:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-009'), (SELECT id FROM pengguna WHERE email='eko@sman3mlg.sch.id'), 'Aduan dikirim', '2026-09-27 03:00:00', '2026-09-27 03:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-009'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Database sudah selesai diperbaiki', '2026-09-27 08:00:00', '2026-09-27 08:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-009'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Dipantau sampai stabil', '2026-09-28 01:00:00', '2026-09-28 01:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-008'), (SELECT id FROM pengguna WHERE email='citra@smkn1mlg.sch.id'), 'Aduan dikirim', '2026-09-28 01:00:00', '2026-09-28 01:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-008'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'Akan dikoordinasikan dengan tim server', '2026-09-28 02:00:00', '2026-09-28 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-008'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'Server sudah dioptimalkan, login normal', '2026-09-28 06:00:00', '2026-09-28 06:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-007'), (SELECT id FROM pengguna WHERE email='bagas@sman3sby.sch.id'), 'Aduan dikirim', '2026-09-20 02:00:00', '2026-09-20 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-007'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Index database ditambahkan, selesai', '2026-09-20 09:00:00', '2026-09-20 09:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-005'), (SELECT id FROM pengguna WHERE email='dimas@smkn1sby.sch.id'), 'Aduan dikirim', '2026-09-18 02:00:00', '2026-09-18 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-005'), (SELECT id FROM pengguna WHERE email='dina@perusahaan.id'), 'CSS diperbaiki, selesai', '2026-09-18 07:00:00', '2026-09-18 07:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-011'), (SELECT id FROM pengguna WHERE email='gunawan@smkn4mlg.sch.id'), 'Aduan dikirim', '2026-10-01 00:30:00', '2026-10-01 00:30:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-012'), (SELECT id FROM pengguna WHERE email='hesti@man1mlg.sch.id'), 'Aduan dikirim', '2026-10-20 01:00:00', '2026-10-20 01:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-012'), (SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), 'Prioritas Urgent, diteruskan ke Bayu (Teknisi)', '2026-10-20 01:30:00', '2026-10-20 01:30:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-001'), (SELECT id FROM pengguna WHERE email='rina@smkn2.sch.id'), 'Aduan dikirim', '2026-09-08 02:00:00', '2026-09-08 02:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-001'), (SELECT id FROM pengguna WHERE email='bayu@perusahaan.id'), 'IP statis printer diatur ulang, selesai', '2026-09-15 07:00:00', '2026-09-15 07:00:00');

-- Solusi hanya untuk aduan berstatus Selesai
INSERT INTO solusi_aduan (aduan_id, masalah, solusi, tanggal_selesai, created_at, updated_at) VALUES
  ((SELECT id FROM aduan WHERE kode_aduan='HD-001'), 'Printer lab tidak terdeteksi di jaringan', 'IP statis printer diatur ulang sehingga terdeteksi kembali oleh semua komputer lab.', '2026-09-15 07:00:00', '2026-09-15 07:00:00', '2026-09-15 07:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-002'), 'Tiga akun guru tidak bisa masuk', 'Kata sandi akun direset oleh teknisi dan guru diminta menggantinya saat masuk pertama.', '2026-09-02 04:00:00', '2026-09-02 04:00:00', '2026-09-02 04:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-003'), 'Data hasil import siswa tidak muncul', 'Data hasil import diperiksa dan dimuat ulang ke tabel siswa sampai tampil lengkap.', '2026-08-03 09:00:00', '2026-08-03 09:00:00', '2026-08-03 09:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-004'), 'Nama siswa kosong pada surat keterangan', 'Template surat diperbaiki agar kolom nama terisi dari data siswa.', '2026-09-09 08:00:00', '2026-09-09 08:00:00', '2026-09-09 08:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-005'), 'Menu tertutup di layar kecil', 'CSS diperbaiki sehingga menu tampil rapi di layar HP.', '2026-09-18 07:00:00', '2026-09-18 07:00:00', '2026-09-18 07:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-006'), 'Query failed pada rekap nilai', 'Query rekap nilai diperbaiki dan koneksi database diperiksa ulang.', '2026-09-12 09:30:00', '2026-09-12 09:30:00', '2026-09-12 09:30:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-007'), 'Rekap absensi butuh waktu lama', 'Index database ditambahkan sehingga rekap absensi jauh lebih cepat.', '2026-09-20 09:00:00', '2026-09-20 09:00:00', '2026-09-20 09:00:00'),
  ((SELECT id FROM aduan WHERE kode_aduan='HD-008'), 'Login guru butuh sekitar 1 menit', 'Server dioptimalkan sehingga login kembali normal.', '2026-09-28 06:00:00', '2026-09-28 06:00:00', '2026-09-28 06:00:00');

INSERT INTO konsultasi (pengaju_id, teknisi_id, topik, catatan, tanggal, jam, status, jumlah_ubah_jadwal, created_at, updated_at) VALUES
  ((SELECT id FROM pengguna WHERE email='rina@smkn2.sch.id'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Proyek sistem akademik baru untuk SMK 2', 'Diundang presentasi di sekolah', '2026-10-08', '10:00', 'Terjadwal', 0, '2026-10-01 02:00:00', '2026-10-01 02:00:00'),
  ((SELECT id FROM pengguna WHERE email='yusuf@sman5sby.sch.id'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Migrasi data siswa tahun ajaran baru', 'Butuh pendampingan teknisi', '2026-09-05', '09:00', 'Jadi', 0, '2026-09-01 02:00:00', '2026-09-01 02:00:00'),
  ((SELECT id FROM pengguna WHERE email='nur@man1sda.sch.id'), (SELECT id FROM pengguna WHERE email='rafi@perusahaan.id'), 'Rencana integrasi data EMIS', 'Mohon dijadwalkan ulang', '2026-10-14', '13:00', 'Terjadwal', 1, '2026-10-03 02:00:00', '2026-10-03 02:00:00'),
  ((SELECT id FROM pengguna WHERE email='agus@smkn1sda.sch.id'), NULL, 'Konsultasi pengembangan portal sekolah', NULL, '2026-11-03', '11:00', 'Menunggu', 0, '2026-10-26 01:00:00', '2026-10-26 01:00:00'),
  ((SELECT id FROM pengguna WHERE email='wulan@sman1sda.sch.id'), NULL, 'Integrasi jadwal pelajaran', 'Sudah dibahas lewat telepon', '2026-09-10', '14:00', 'Tidak', 0, '2026-09-08 03:00:00', '2026-09-08 03:00:00');

INSERT INTO pesan_konsultasi (konsultasi_id, pengirim_id, pesan, created_at, updated_at) VALUES
  ((SELECT id FROM konsultasi WHERE topik='Proyek sistem akademik baru untuk SMK 2'), (SELECT id FROM pengguna WHERE email='rina@smkn2.sch.id'), 'Kami ingin konsultasi sistem akademik baru, mohon jadwalnya.', '2026-10-01 02:00:00', '2026-10-01 02:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Proyek sistem akademik baru untuk SMK 2'), (SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), 'Baik Bu, jadwal 8 Oktober pukul 10.00 sudah kami konfirmasi.', '2026-10-01 03:00:00', '2026-10-01 03:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Rencana integrasi data EMIS'), (SELECT id FROM pengguna WHERE email='nur@man1sda.sch.id'), 'Mohon jadwal dipindah karena kepala sekolah berhalangan.', '2026-10-07 02:00:00', '2026-10-07 02:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Rencana integrasi data EMIS'), (SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), 'Jadwal baru 14 Oktober pukul 13.00, ya Bu.', '2026-10-07 04:00:00', '2026-10-07 04:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Migrasi data siswa tahun ajaran baru'), (SELECT id FROM pengguna WHERE email='yusuf@sman5sby.sch.id'), 'Terima kasih, konsultasinya sangat membantu.', '2026-09-05 06:00:00', '2026-09-05 06:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Konsultasi pengembangan portal sekolah'), (SELECT id FROM pengguna WHERE email='agus@smkn1sda.sch.id'), 'Kami mengajukan konsultasi untuk portal sekolah.', '2026-10-26 01:00:00', '2026-10-26 01:00:00');

INSERT INTO riwayat_konsultasi (konsultasi_id, aktivitas, created_at, updated_at) VALUES
  ((SELECT id FROM konsultasi WHERE topik='Proyek sistem akademik baru untuk SMK 2'), 'Konsultasi diajukan', '2026-10-01 02:00:00', '2026-10-01 02:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Proyek sistem akademik baru untuk SMK 2'), 'Jadwal dikonfirmasi admin: 8 Oktober 2026 pukul 10:00', '2026-10-01 03:00:00', '2026-10-01 03:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Migrasi data siswa tahun ajaran baru'), 'Konsultasi diajukan', '2026-09-01 02:00:00', '2026-09-01 02:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Migrasi data siswa tahun ajaran baru'), 'Konsultasi terlaksana', '2026-09-05 06:00:00', '2026-09-05 06:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Rencana integrasi data EMIS'), 'Konsultasi diajukan', '2026-10-03 02:00:00', '2026-10-03 02:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Rencana integrasi data EMIS'), 'Jadwal diubah dari 7 Oktober 2026 pukul 10:00 ke 14 Oktober 2026 pukul 13:00 (alasan: kepala sekolah berhalangan hadir)', '2026-10-07 04:00:00', '2026-10-07 04:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Konsultasi pengembangan portal sekolah'), 'Konsultasi diajukan', '2026-10-26 01:00:00', '2026-10-26 01:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Integrasi jadwal pelajaran'), 'Konsultasi diajukan', '2026-09-08 03:00:00', '2026-09-08 03:00:00'),
  ((SELECT id FROM konsultasi WHERE topik='Integrasi jadwal pelajaran'), 'Konsultasi tidak dilanjutkan, sudah dibahas lewat telepon', '2026-09-10 14:00:00', '2026-09-10 14:00:00');

-- Kuota konsultasi default: 2 slot per jam, hari kerja 09.00 sampai 15.00 (istirahat 12.00). tanggal NULL = berlaku umum.
INSERT INTO kuota_konsultasi (diatur_oleh, tanggal, jam, kuota, created_at, updated_at) VALUES
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '09:00', 2, NOW(), NOW()),
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '10:00', 2, NOW(), NOW()),
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '11:00', 2, NOW(), NOW()),
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '13:00', 2, NOW(), NOW()),
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '14:00', 2, NOW(), NOW()),
  ((SELECT id FROM pengguna WHERE email='admin@perusahaan.id'), NULL, '15:00', 2, NOW(), NOW());

INSERT INTO survei (pengguna_id, aduan_id, nilai_keseluruhan, nilai_respon, nilai_kemudahan, nilai_kualitas, terselesaikan, komentar, created_at, updated_at) VALUES
  ((SELECT id FROM pengguna WHERE email='rina@smkn2.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-001'), 5, 4, 5, 5, 'Ya', 'Cepat dan jelas.', '2026-09-16 03:00:00', '2026-09-16 03:00:00'),
  ((SELECT id FROM pengguna WHERE email='yusuf@sman5sby.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-002'), 5, 5, 4, 5, 'Ya', 'Tuntas dan dijelaskan penyebabnya.', '2026-09-13 03:00:00', '2026-09-13 03:00:00'),
  ((SELECT id FROM pengguna WHERE email='nur@man1sda.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-003'), 4, 4, 4, 4, 'Ya', NULL, '2026-08-04 03:00:00', '2026-08-04 03:00:00'),
  ((SELECT id FROM pengguna WHERE email='wulan@sman1sda.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-004'), 5, 5, 5, 5, 'Ya', 'Cepat dan jelas.', '2026-09-10 03:00:00', '2026-09-10 03:00:00'),
  ((SELECT id FROM pengguna WHERE email='dimas@smkn1sby.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-005'), 4, 4, 5, 4, 'Ya', 'Menu sudah rapi di HP.', '2026-09-19 03:00:00', '2026-09-19 03:00:00'),
  ((SELECT id FROM pengguna WHERE email='bagas@sman3sby.sch.id'), (SELECT id FROM aduan WHERE kode_aduan='HD-007'), 5, 5, 5, 5, 'Ya', 'Rekap absensi jadi jauh lebih cepat.', '2026-09-21 03:00:00', '2026-09-21 03:00:00');

COMMIT;

-- =====================================================================================
-- CEK HASIL (opsional)
--   SELECT p.nama, p.email, p.role, i.kode_instansi, i.nama_instansi
--     FROM pengguna p LEFT JOIN instansi i ON i.id = p.instansi_id ORDER BY p.id;
--   SELECT a.kode_aduan, a.judul, a.prioritas, a.status, pl.nama AS pelapor, s.solusi
--     FROM aduan a JOIN pengguna pl ON pl.id = a.pelapor_id LEFT JOIN solusi_aduan s ON s.aduan_id = a.id ORDER BY a.id;
--   -- Uji deteksi otomatis (klien baru dengan domain terdaftar -> instansi_id SMKN1-SDA terisi sendiri):
--   INSERT INTO pengguna (nama, email, password, telepon, role) VALUES ('Uji Baru', 'uji@smkn1sda.sch.id', 'x', '08123', 'klien');
--   -- Domain tidak terdaftar -> ditolak: INSERT ... 'uji@gmail.com' ... 'klien';
-- =====================================================================================
