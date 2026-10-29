-- =====================================================================
-- SISTEM INFORMASI BIMBINGAN BELAJAR (BIMBEL) TATAP MUKA
-- Tahap 1 : Rancangan Database
-- DBMS    : MySQL / MariaDB (InnoDB, utf8mb4)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `db_bimbel`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `db_bimbel`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `api_login_gagal`;
DROP TABLE IF EXISTS `api_token`;
DROP TABLE IF EXISTS `pengumuman`;
DROP TABLE IF EXISTS `pembayaran`;
DROP TABLE IF EXISTS `absensi`;
DROP TABLE IF EXISTS `pertemuan`;
DROP TABLE IF EXISTS `jadwal`;
DROP TABLE IF EXISTS `pendaftaran`;
DROP TABLE IF EXISTS `kelas`;
DROP TABLE IF EXISTS `mata_pelajaran`;
DROP TABLE IF EXISTS `pengajar`;
DROP TABLE IF EXISTS `siswa`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. USERS  (akun login untuk semua peran: admin, owner, pengajar, siswa)
--    role 'owner'    -> pemilik bimbel (melihat dashboard & laporan)
--    role 'admin'    -> pusat kendali
--    role 'pengajar' -> portal informasi (User)
--    role 'siswa'    -> portal informasi (User)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id_user`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL COMMENT 'hash bcrypt via password_hash()',
  `role`       ENUM('admin','owner','pengajar','siswa') NOT NULL,
  `status`     ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. SISWA  (profil siswa, 1 akun user = 1 siswa)
-- ---------------------------------------------------------------------
CREATE TABLE `siswa` (
  `id_siswa`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`       INT UNSIGNED NOT NULL,
  `nis`           VARCHAR(20)  NOT NULL COMMENT 'nomor induk siswa bimbel',
  `nama_lengkap`  VARCHAR(100) NOT NULL,
  `jenis_kelamin` ENUM('L','P') NOT NULL,
  `tanggal_lahir` DATE DEFAULT NULL,
  `alamat`        TEXT,
  `no_telp`       VARCHAR(20)  DEFAULT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `sekolah_asal`  VARCHAR(100) DEFAULT NULL,
  `tingkat`       ENUM('SD','SMP','SMA','SMK','Umum') NOT NULL DEFAULT 'SMA',
  `nama_wali`     VARCHAR(100) DEFAULT NULL,
  `no_telp_wali`  VARCHAR(20)  DEFAULT NULL,
  `tanggal_daftar` DATE NOT NULL DEFAULT (CURRENT_DATE),
  `status`        ENUM('menunggu','aktif','nonaktif','lulus') NOT NULL DEFAULT 'aktif' COMMENT 'menunggu = baru registrasi mandiri, belum diverifikasi admin',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_siswa`),
  UNIQUE KEY `uq_siswa_nis`  (`nis`),
  UNIQUE KEY `uq_siswa_user` (`id_user`),
  KEY `idx_siswa_nama` (`nama_lengkap`),
  CONSTRAINT `fk_siswa_user` FOREIGN KEY (`id_user`)
    REFERENCES `users` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. PENGAJAR  (profil pengajar, 1 akun user = 1 pengajar)
-- ---------------------------------------------------------------------
CREATE TABLE `pengajar` (
  `id_pengajar`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`            INT UNSIGNED NOT NULL,
  `nip`                VARCHAR(25)  NOT NULL COMMENT 'nomor induk pengajar',
  `nama_lengkap`       VARCHAR(100) NOT NULL,
  `jenis_kelamin`      ENUM('L','P') NOT NULL,
  `tanggal_lahir`      DATE DEFAULT NULL,
  `alamat`             TEXT,
  `no_telp`            VARCHAR(20)  DEFAULT NULL,
  `email`              VARCHAR(100) DEFAULT NULL,
  `pendidikan_terakhir` VARCHAR(50) DEFAULT NULL,
  `keahlian`           VARCHAR(150) DEFAULT NULL,
  `tanggal_bergabung`  DATE NOT NULL DEFAULT (CURRENT_DATE),
  `status`             ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pengajar`),
  UNIQUE KEY `uq_pengajar_nip`  (`nip`),
  UNIQUE KEY `uq_pengajar_user` (`id_user`),
  KEY `idx_pengajar_nama` (`nama_lengkap`),
  CONSTRAINT `fk_pengajar_user` FOREIGN KEY (`id_user`)
    REFERENCES `users` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. MATA PELAJARAN
-- ---------------------------------------------------------------------
CREATE TABLE `mata_pelajaran` (
  `id_mapel`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_mapel` VARCHAR(100) NOT NULL,
  `deskripsi`  VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_mapel`),
  UNIQUE KEY `uq_mapel_nama` (`nama_mapel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. KELAS  (kelas bimbel = mapel + pengajar + tingkat + kuota + biaya)
-- ---------------------------------------------------------------------
CREATE TABLE `kelas` (
  `id_kelas`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_kelas`     VARCHAR(100) NOT NULL,
  `id_mapel`       INT UNSIGNED NOT NULL,
  `id_pengajar`    INT UNSIGNED DEFAULT NULL,
  `tingkat`        ENUM('SD','SMP','SMA','SMK','Umum') NOT NULL DEFAULT 'SMA',
  `kuota`          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  `biaya_bulanan`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tahun_ajaran`   VARCHAR(9) NOT NULL COMMENT 'contoh: 2026/2027',
  `status`         ENUM('buka','tutup') NOT NULL DEFAULT 'buka',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_kelas`),
  KEY `idx_kelas_mapel`    (`id_mapel`),
  KEY `idx_kelas_pengajar` (`id_pengajar`),
  CONSTRAINT `fk_kelas_mapel` FOREIGN KEY (`id_mapel`)
    REFERENCES `mata_pelajaran` (`id_mapel`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_kelas_pengajar` FOREIGN KEY (`id_pengajar`)
    REFERENCES `pengajar` (`id_pengajar`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. PENDAFTARAN  (siswa mendaftar ke kelas; dikelola Admin)
-- ---------------------------------------------------------------------
CREATE TABLE `pendaftaran` (
  `id_pendaftaran`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_siswa`        INT UNSIGNED NOT NULL,
  `id_kelas`        INT UNSIGNED NOT NULL,
  `tanggal_daftar`  DATE NOT NULL DEFAULT (CURRENT_DATE),
  `status`          ENUM('pending','aktif','selesai','batal') NOT NULL DEFAULT 'pending',
  `catatan`         VARCHAR(255) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pendaftaran`),
  UNIQUE KEY `uq_pendaftaran_siswa_kelas` (`id_siswa`,`id_kelas`),
  KEY `idx_pendaftaran_kelas` (`id_kelas`),
  CONSTRAINT `fk_pendaftaran_siswa` FOREIGN KEY (`id_siswa`)
    REFERENCES `siswa` (`id_siswa`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pendaftaran_kelas` FOREIGN KEY (`id_kelas`)
    REFERENCES `kelas` (`id_kelas`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. JADWAL  (jadwal mingguan tetap per kelas, dibuat Admin)
-- ---------------------------------------------------------------------
CREATE TABLE `jadwal` (
  `id_jadwal`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_kelas`    INT UNSIGNED NOT NULL,
  `hari`        ENUM('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu') NOT NULL,
  `jam_mulai`   TIME NOT NULL,
  `jam_selesai` TIME NOT NULL,
  `ruangan`     VARCHAR(50) DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_jadwal`),
  KEY `idx_jadwal_kelas` (`id_kelas`),
  KEY `idx_jadwal_hari`  (`hari`),
  CONSTRAINT `fk_jadwal_kelas` FOREIGN KEY (`id_kelas`)
    REFERENCES `kelas` (`id_kelas`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_jadwal_jam` CHECK (`jam_selesai` > `jam_mulai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. PERTEMUAN  (realisasi tiap sesi belajar pada tanggal tertentu)
--    Menjadi induk absensi siswa + mencatat kehadiran pengajar.
-- ---------------------------------------------------------------------
CREATE TABLE `pertemuan` (
  `id_pertemuan`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_jadwal`           INT UNSIGNED NOT NULL,
  `tanggal`             DATE NOT NULL,
  `pertemuan_ke`        SMALLINT UNSIGNED DEFAULT NULL,
  `materi`              VARCHAR(255) DEFAULT NULL,
  `kehadiran_pengajar`  ENUM('hadir','izin','sakit','alpha') NOT NULL DEFAULT 'hadir',
  `catatan`             VARCHAR(255) DEFAULT NULL,
  `dicatat_oleh`        INT UNSIGNED DEFAULT NULL COMMENT 'id_user pengajar/admin yang mencatat',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pertemuan`),
  UNIQUE KEY `uq_pertemuan_jadwal_tanggal` (`id_jadwal`,`tanggal`),
  CONSTRAINT `fk_pertemuan_jadwal` FOREIGN KEY (`id_jadwal`)
    REFERENCES `jadwal` (`id_jadwal`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pertemuan_pencatat` FOREIGN KEY (`dicatat_oleh`)
    REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. ABSENSI  (kehadiran siswa per pertemuan)
-- ---------------------------------------------------------------------
CREATE TABLE `absensi` (
  `id_absensi`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pertemuan` INT UNSIGNED NOT NULL,
  `id_siswa`     INT UNSIGNED NOT NULL,
  `status`       ENUM('hadir','izin','sakit','alpha') NOT NULL DEFAULT 'hadir',
  `keterangan`   VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_absensi`),
  UNIQUE KEY `uq_absensi_pertemuan_siswa` (`id_pertemuan`,`id_siswa`),
  KEY `idx_absensi_siswa` (`id_siswa`),
  CONSTRAINT `fk_absensi_pertemuan` FOREIGN KEY (`id_pertemuan`)
    REFERENCES `pertemuan` (`id_pertemuan`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absensi_siswa` FOREIGN KEY (`id_siswa`)
    REFERENCES `siswa` (`id_siswa`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. PEMBAYARAN  (tagihan & pembayaran SPP bulanan per pendaftaran)
-- ---------------------------------------------------------------------
CREATE TABLE `pembayaran` (
  `id_pembayaran`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pendaftaran` INT UNSIGNED NOT NULL,
  `no_invoice`     VARCHAR(30) NOT NULL,
  `periode_bulan`  TINYINT UNSIGNED NOT NULL COMMENT '1-12',
  `periode_tahun`  SMALLINT UNSIGNED NOT NULL,
  `jumlah`         DECIMAL(12,2) NOT NULL,
  `tanggal_bayar`  DATE DEFAULT NULL,
  `metode`         ENUM('tunai','transfer','qris') DEFAULT NULL,
  `status`         ENUM('belum_bayar','menunggu_verifikasi','lunas') NOT NULL DEFAULT 'belum_bayar',
  `keterangan`     VARCHAR(255) DEFAULT NULL,
  `diverifikasi_oleh` INT UNSIGNED DEFAULT NULL COMMENT 'id_user admin',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pembayaran`),
  UNIQUE KEY `uq_pembayaran_invoice` (`no_invoice`),
  UNIQUE KEY `uq_pembayaran_periode` (`id_pendaftaran`,`periode_bulan`,`periode_tahun`),
  KEY `idx_pembayaran_status` (`status`),
  CONSTRAINT `fk_pembayaran_pendaftaran` FOREIGN KEY (`id_pendaftaran`)
    REFERENCES `pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pembayaran_admin` FOREIGN KEY (`diverifikasi_oleh`)
    REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_pembayaran_bulan` CHECK (`periode_bulan` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11-13. PENGUMUMAN (info perubahan jadwal), TOKEN REST API, BATAS LOGIN API
-- ---------------------------------------------------------------------
CREATE TABLE `pengumuman` (
  `id_pengumuman` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `judul`         VARCHAR(150) NOT NULL,
  `isi`           TEXT NOT NULL,
  `tipe`          ENUM('umum','jadwal') NOT NULL DEFAULT 'umum' COMMENT 'jadwal = info perubahan jadwal',
  `target`        ENUM('semua','siswa','pengajar') NOT NULL DEFAULT 'semua',
  `id_kelas`      INT UNSIGNED DEFAULT NULL COMMENT 'NULL = semua kelas',
  `dibuat_oleh`   INT UNSIGNED DEFAULT NULL COMMENT 'id_user admin',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_pengumuman`),
  KEY `idx_pengumuman_kelas` (`id_kelas`),
  KEY `idx_pengumuman_tgl` (`created_at`),
  CONSTRAINT `fk_pengumuman_kelas` FOREIGN KEY (`id_kelas`)
    REFERENCES `kelas` (`id_kelas`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pengumuman_user` FOREIGN KEY (`dibuat_oleh`)
    REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_token` (
  `id_token`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `perangkat`  VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_token`),
  UNIQUE KEY `uq_api_token_hash` (`token_hash`),
  KEY `idx_api_token_user` (`id_user`),
  CONSTRAINT `fk_api_token_user` FOREIGN KEY (`id_user`)
    REFERENCES `users` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_login_gagal` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`       VARCHAR(45) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `waktu`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_gagal` (`ip`,`username`,`waktu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DATA AWAL (SEED)
-- =====================================================================

-- Akun Admin default  ->  username: admin   |  password: admin123
-- WAJIB diganti setelah login pertama kali.
INSERT INTO `users` (`username`, `password`, `role`, `status`) VALUES
('admin', '$2y$10$AwvcSawZaR.0wilQykK/kuGiHUGKpj9Dy2bbe16h9ld9rQtTOzfvi', 'admin', 'aktif');

-- Akun Owner default  ->  username: owner   |  password: owner123  (WAJIB diganti)
INSERT INTO `users` (`username`, `password`, `role`, `status`) VALUES
('owner', '$2y$10$k415GyxEhHenL7RpFa8j5eS74j5eCR5xSbSFvsryDM/N8GJu6Elo2', 'owner', 'aktif');

INSERT INTO `mata_pelajaran` (`nama_mapel`, `deskripsi`) VALUES
('Matematika',        'Aljabar, geometri, trigonometri, dan kalkulus dasar'),
('Bahasa Inggris',    'Grammar, speaking, dan persiapan TOEFL'),
('Fisika',            'Mekanika, listrik, dan gelombang'),
('Kimia',             'Kimia dasar hingga kimia organik'),
('Bahasa Indonesia',  'Tata bahasa, menulis, dan analisis teks'),
('Pemrograman Dasar', 'Logika pemrograman dan dasar web');

-- =====================================================================
-- RINGKASAN RELASI
--   users 1 --- 0..1 siswa
--   users 1 --- 0..1 pengajar
--   kelas 1 --- N pengumuman (NULL = semua kelas)
--   users 1 --- N api_token
--   mata_pelajaran 1 --- N kelas
--   pengajar       1 --- N kelas
--   siswa  N --- N kelas   (melalui pendaftaran)
--   kelas  1 --- N jadwal
--   jadwal 1 --- N pertemuan
--   pertemuan 1 --- N absensi  N --- 1 siswa
--   pendaftaran 1 --- N pembayaran
-- =====================================================================
