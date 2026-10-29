-- =====================================================================
-- DATA DEMO (opsional) — Sistem Informasi Bimbel Tatap Muka
-- Import SETELAH database.sql pada database yang masih kosong.
-- (admin = id 1, owner = id 2 dari database.sql; akun demo mulai id 3)
-- Semua akun demo memakai password: bimbel123
--   pengajar : guru1, guru2, guru3
--   siswa    : siswa1 ... siswa8
-- Tanggal absensi & tagihan berpatokan pada September-Oktober 2026.
-- =====================================================================
USE `db_bimbel`;
SET NAMES utf8mb4;

INSERT INTO `users` (`id_user`,`username`,`password`,`role`,`status`) VALUES
(3,'guru1','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','pengajar','aktif'),
(4,'guru2','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','pengajar','aktif'),
(5,'guru3','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','pengajar','aktif'),
(6,'siswa1','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(7,'siswa2','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(8,'siswa3','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(9,'siswa4','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(10,'siswa5','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(11,'siswa6','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(12,'siswa7','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif'),
(13,'siswa8','$2y$10$pezasHod1SgNoPn9c1sS.e0Xr.NV3E7TJUjxfncoDJEFVqmNPQOfq','siswa','aktif');

INSERT INTO `pengajar` (`id_pengajar`,`id_user`,`nip`,`nama_lengkap`,`jenis_kelamin`,`no_telp`,`email`,`pendidikan_terakhir`,`keahlian`,`tanggal_bergabung`) VALUES
(1,3,'P-2024-001','Rina Marlina, S.Pd','P','081234500001','rina@bimbel.test','S1 Pendidikan Matematika','Matematika, Fisika','2024-07-15'),
(2,4,'P-2025-002','Dedi Kurniawan, S.Kom','L','081234500002','dedi@bimbel.test','S1 Teknik Informatika','Pemrograman, Web','2025-01-10'),
(3,5,'P-2025-003','Sarah Wulandari, M.Pd','P','081234500003','sarah@bimbel.test','S2 Pendidikan Bahasa Inggris','Bahasa Inggris, TOEFL','2025-03-01');

INSERT INTO `siswa` (`id_siswa`,`id_user`,`nis`,`nama_lengkap`,`jenis_kelamin`,`no_telp`,`email`,`sekolah_asal`,`tingkat`,`nama_wali`,`no_telp_wali`,`tanggal_daftar`) VALUES
(1,6,'2026001','Ahmad Fauzi','L','081300000001','ahmad@mail.test','SMAN 1 Padang','SMA','Bapak Hasan','081311110001','2026-07-20'),
(2,7,'2026002','Siti Nurhaliza','P','081300000002','siti@mail.test','SMAN 3 Padang','SMA','Ibu Rahma','081311110002','2026-07-20'),
(3,8,'2026003','Budi Santoso','L','081300000003',NULL,'SMAN 2 Padang','SMA','Bapak Santoso','081311110003','2026-07-22'),
(4,9,'2026004','Dewi Lestari','P','081300000004','dewi@mail.test','SMAN 10 Padang','SMA','Ibu Lestari','081311110004','2026-07-25'),
(5,10,'2026005','Rizky Ramadhan','L','081300000005',NULL,'Umum','Umum','Bapak Ramadhan','081311110005','2026-08-01'),
(6,11,'2026006','Putri Anggraini','P','081300000006','putri@mail.test','SMKN 1 Padang','SMK','Ibu Anggraini','081311110006','2026-08-03'),
(7,12,'2026007','Muhammad Iqbal','L','081300000007',NULL,'SMKN 2 Padang','SMK','Bapak Iqbal','081311110007','2026-08-05'),
(8,13,'2026008','Nadia Safitri','P','081300000008','nadia@mail.test','SMKN 1 Padang','SMK','Ibu Safitri','081311110008','2026-10-02');

INSERT INTO `kelas` (`id_kelas`,`nama_kelas`,`id_mapel`,`id_pengajar`,`tingkat`,`kuota`,`biaya_bulanan`,`tahun_ajaran`,`status`) VALUES
(1,'Matematika SMA XII',1,1,'SMA',15,250000,'2026/2027','buka'),
(2,'Fisika SMA XI',3,1,'SMA',12,250000,'2026/2027','buka'),
(3,'English Conversation',2,3,'Umum',20,200000,'2026/2027','buka'),
(4,'Pemrograman Dasar Web',6,2,'SMK',10,300000,'2026/2027','buka'),
(5,'Kimia SMA X',4,NULL,'SMA',15,230000,'2026/2027','tutup');

INSERT INTO `jadwal` (`id_jadwal`,`id_kelas`,`hari`,`jam_mulai`,`jam_selesai`,`ruangan`) VALUES
(1,1,'Senin','15:00','16:30','Ruang A'),
(2,1,'Rabu','15:00','16:30','Ruang A'),
(3,2,'Selasa','16:00','17:30','Ruang B'),
(4,3,'Kamis','15:30','17:00','Ruang A'),
(5,3,'Minggu','10:00','11:30','Ruang B'),
(6,4,'Sabtu','09:00','11:00','Lab Komputer');

INSERT INTO `pendaftaran` (`id_pendaftaran`,`id_siswa`,`id_kelas`,`tanggal_daftar`,`status`) VALUES
(1,1,1,'2026-07-21','aktif'),
(2,2,1,'2026-07-21','aktif'),
(3,3,1,'2026-07-23','aktif'),
(4,1,2,'2026-07-21','aktif'),
(5,4,2,'2026-07-26','aktif'),
(6,5,3,'2026-08-02','aktif'),
(7,2,3,'2026-08-02','aktif'),
(8,6,4,'2026-08-04','aktif'),
(9,7,4,'2026-08-06','aktif'),
(10,8,4,'2026-10-15','pending'),
(11,3,3,'2026-10-16','pending'),
(12,4,1,'2026-08-10','batal');

INSERT INTO `pembayaran` (`id_pembayaran`,`id_pendaftaran`,`no_invoice`,`periode_bulan`,`periode_tahun`,`jumlah`,`tanggal_bayar`,`metode`,`status`,`diverifikasi_oleh`) VALUES
(1,1,'INV-202609-000001',9,2026,250000,'2026-09-04','tunai','lunas',1),
(2,2,'INV-202609-000002',9,2026,250000,'2026-09-05','qris','lunas',1),
(3,3,'INV-202609-000003',9,2026,250000,'2026-09-06','transfer','lunas',1),
(4,4,'INV-202609-000004',9,2026,250000,'2026-09-07','tunai','lunas',1),
(5,5,'INV-202609-000005',9,2026,250000,'2026-09-03','qris','lunas',1),
(6,6,'INV-202609-000006',9,2026,200000,'2026-09-04','transfer','lunas',1),
(7,7,'INV-202609-000007',9,2026,200000,'2026-09-05','tunai','lunas',1),
(8,8,'INV-202609-000008',9,2026,300000,'2026-09-06','qris','lunas',1),
(9,9,'INV-202609-000009',9,2026,300000,'2026-09-07','transfer','lunas',1),
(10,1,'INV-202610-000001',10,2026,250000,'2026-10-05','tunai','lunas',1),
(11,2,'INV-202610-000002',10,2026,250000,'2026-10-16','transfer','menunggu_verifikasi',NULL),
(12,3,'INV-202610-000003',10,2026,250000,NULL,NULL,'belum_bayar',NULL),
(13,4,'INV-202610-000004',10,2026,250000,'2026-10-04','tunai','lunas',1),
(14,5,'INV-202610-000005',10,2026,250000,NULL,NULL,'belum_bayar',NULL),
(15,6,'INV-202610-000006',10,2026,200000,NULL,NULL,'belum_bayar',NULL),
(16,7,'INV-202610-000007',10,2026,200000,'2026-10-16','transfer','menunggu_verifikasi',NULL),
(17,8,'INV-202610-000008',10,2026,300000,'2026-10-04','qris','lunas',1),
(18,9,'INV-202610-000009',10,2026,300000,NULL,NULL,'belum_bayar',NULL);

INSERT INTO `pertemuan` (`id_pertemuan`,`id_jadwal`,`tanggal`,`pertemuan_ke`,`materi`) VALUES
(1,1,'2026-10-05',1,'Turunan fungsi aljabar'),
(2,2,'2026-10-07',1,'Aplikasi turunan'),
(3,1,'2026-10-12',2,'Integral tak tentu'),
(4,2,'2026-10-14',2,'Integral tentu'),
(5,3,'2026-10-06',1,'Gerak lurus'),
(6,3,'2026-10-13',2,'Gerak parabola'),
(7,4,'2026-10-08',1,'Daily conversation'),
(8,4,'2026-10-15',2,'Asking for directions'),
(9,5,'2026-10-11',1,'Role play: restaurant'),
(10,6,'2026-10-10',1,'HTML dasar'),
(11,6,'2026-10-17',2,'CSS dasar');

INSERT INTO `absensi` (`id_pertemuan`,`id_siswa`,`status`,`keterangan`) VALUES
(1,1,'hadir',NULL),
(1,2,'hadir',NULL),
(1,3,'alpha',NULL),
(2,1,'hadir',NULL),
(2,2,'sakit','Demam'),
(2,3,'hadir',NULL),
(3,1,'hadir',NULL),
(3,2,'hadir',NULL),
(3,3,'hadir',NULL),
(4,1,'izin','Acara keluarga'),
(4,2,'hadir',NULL),
(4,3,'sakit','Demam'),
(5,1,'hadir',NULL),
(5,4,'hadir',NULL),
(6,1,'hadir',NULL),
(6,4,'izin','Acara keluarga'),
(7,5,'hadir',NULL),
(7,2,'hadir',NULL),
(8,5,'alpha',NULL),
(8,2,'hadir',NULL),
(9,5,'hadir',NULL),
(9,2,'hadir',NULL),
(10,6,'hadir',NULL),
(10,7,'alpha',NULL),
(11,6,'sakit','Demam'),
(11,7,'hadir',NULL);

-- Pengumuman contoh (1 umum, 1 perubahan jadwal)
INSERT INTO `pengumuman` (`judul`,`isi`,`tipe`,`target`,`id_kelas`,`dibuat_oleh`) VALUES
('Selamat datang di Bimbel','Jadwal dan kehadiran kini dapat dilihat langsung lewat sistem. Hubungi admin bila ada data yang keliru.','umum','semua',NULL,1),
('Perubahan jadwal','Jadwal kelas pertama di semester ini dapat berubah; pantau halaman ini untuk info terbaru.','jadwal','semua',NULL,1);
