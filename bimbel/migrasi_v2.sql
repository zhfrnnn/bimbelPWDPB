-- =====================================================================
-- MIGRASI v2 — jalankan SEKALI pada database db_bimbel yang SUDAH ADA
-- (jika Anda import database.sql versi baru dari nol, file ini TIDAK perlu).
-- Menambahkan: peran owner, status siswa 'menunggu', pencatat pertemuan,
-- tabel pengumuman, dan tabel untuk REST API.
-- Aman dijalankan di phpMyAdmin (tab SQL) setelah memilih db_bimbel.
-- =====================================================================
USE `db_bimbel`;

ALTER TABLE `users`
  MODIFY `role` ENUM('admin','owner','pengajar','siswa') NOT NULL;

ALTER TABLE `siswa`
  MODIFY `status` ENUM('menunggu','aktif','nonaktif','lulus') NOT NULL DEFAULT 'aktif'
  COMMENT 'menunggu = baru registrasi mandiri, belum diverifikasi admin';

ALTER TABLE `pertemuan`
  ADD COLUMN `dicatat_oleh` INT UNSIGNED DEFAULT NULL COMMENT 'id_user pengajar/admin yang mencatat' AFTER `catatan`,
  ADD CONSTRAINT `fk_pertemuan_pencatat` FOREIGN KEY (`dicatat_oleh`)
    REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS `pengumuman` (
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

CREATE TABLE IF NOT EXISTS `api_token` (
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

CREATE TABLE IF NOT EXISTS `api_login_gagal` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`       VARCHAR(45) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `waktu`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_gagal` (`ip`,`username`,`waktu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akun Owner default (username: owner, password: owner123) — WAJIB diganti.
INSERT INTO `users` (`username`, `password`, `role`, `status`)
SELECT 'owner', '$2y$10$k415GyxEhHenL7RpFa8j5eS74j5eCR5xSbSFvsryDM/N8GJu6Elo2', 'owner', 'aktif'
 WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'owner');
