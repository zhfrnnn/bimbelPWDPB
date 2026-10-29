# Sistem Informasi Bimbel Tatap Muka (Website + REST API)

PHP native (PDO) + MySQL/MariaDB. Tema abu-abu, biru muda, putih, tanpa sudut lengkung.
Empat peran: **Admin, Owner (pemilik), Pengajar, Siswa**. Aplikasi mobile (Flutter) memakai **REST API** di folder `api/`.

## Cara pasang (XAMPP / Laragon)

1. Salin folder ini ke `htdocs/bimbel` (XAMPP) atau `www/bimbel` (Laragon).
2. Nyalakan Apache dan MySQL.
3. phpMyAdmin -> **Import** `database.sql` (membuat database `db_bimbel`, semua tabel, akun admin & owner).
4. *(Opsional)* Import `database_demo.sql` untuk data contoh.
5. Bila user/password MySQL bukan `root` tanpa password, ubah di `koneksi.php`.
6. Buka `http://localhost/bimbel/`.

> Sudah punya database lama (dari versi sebelumnya)? Jangan import `database.sql` lagi (data hilang). Cukup jalankan `migrasi_v2.sql` sekali di tab SQL phpMyAdmin.

## Akun

| Peran    | Username            | Password    |
|----------|---------------------|-------------|
| Admin    | `admin`             | `admin123`  |
| Owner    | `owner`             | `owner123`  |
| Pengajar | `guru1` – `guru3`   | `bimbel123` |
| Siswa    | `siswa1` – `siswa8` | `bimbel123` |

Pengajar & siswa contoh hanya ada jika `database_demo.sql` diimport. Siswa baru bisa daftar sendiri di `register.php`. **Ganti semua password** lewat menu *Akun Saya*.

## Kesesuaian dengan dokumen guru

**Admin** — login; kelola data siswa, pengajar, **mata pelajaran**, kelas & kelompok belajar (cek bentrok jadwal), jadwal bimbingan; verifikasi pendaftaran; catat pembayaran; **laporan pendaftaran & pembayaran**; pengumuman.

**Siswa** — **registrasi akun sendiri** (lalu diverifikasi admin); lihat **daftar mata pelajaran**, jadwal, **info pengajar**, riwayat kehadiran, status pembayaran; terima **informasi perubahan jadwal** (pengumuman otomatis saat admin mengubah jadwal).

**Pengajar** — lihat jadwal mengajar; lihat **daftar nama siswa per kelas**; **mencatat kehadiran siswa**; **mengisi catatan kegiatan belajar**; lihat riwayat pertemuan.

**Owner** — dashboard keuangan/siswa/akademik dan semua laporan (hanya-baca).

**Contoh penerapan (langkah 1-7)**: siswa daftar -> admin verifikasi -> admin tentukan kelas & jadwal -> siswa lihat jadwal (web/API) -> pengajar catat kehadiran -> admin perbarui status pembayaran -> sistem menghasilkan **laporan bulanan** (tampil, cetak, atau unduh CSV).

## REST API untuk aplikasi mobile

Alamat dasar: `http://localhost/bimbel/api/` (bila mod_rewrite mati: `api/index.php?r=siswa/jadwal`). Respons JSON. Login mengembalikan token; kirim di header `Authorization: Bearer <token>`.
Lihat daftar endpoint di komentar bagian atas `api/index.php`. Aplikasi Flutter-nya sendiri **tidak termasuk** paket ini; API siap dipanggil dari Flutter (`http`/`dio`).

## Keamanan

Prepared statement, escape output, token CSRF pada form POST, bcrypt, regenerasi sesi saat login, batas login gagal (web & API), sesi habis 2 jam, honeypot + batas registrasi, token API disimpan dalam bentuk hash. Saat dipublikasikan ubah `APP_DEBUG` di `koneksi.php` menjadi `false`.

## Daftar file

| File | Fungsi |
|------|--------|
| `database.sql`, `database_demo.sql`, `migrasi_v2.sql` | Skema + akun, data contoh, migrasi database lama |
| `koneksi.php`, `auth.php`, `helpers.php`, `services.php` | Koneksi, sesi & akses 4 peran, fungsi bantu, logika bersama web & API |
| `login.php`, `register.php`, `logout.php`, `profil.php` | Autentikasi & akun |
| `dashboard_admin.php`, `dashboard_owner.php`, `dashboard_user.php` | Dashboard per peran |
| `siswa*.php`, `pengajar*.php`, `mapel*.php` | Data master (admin) |
| `jadwal*.php`, `kelas_form.php`, `pendaftaran*.php`, `absensi.php`, `pembayaran.php`, `pengumuman.php` | Modul admin |
| `laporan.php` | Laporan pendaftaran, pembayaran, bulanan (admin & owner) |
| `jadwal_user.php`, `mapel_user.php`, `pengajar_info.php`, `absensi_user.php`, `pembayaran_user.php`, `pengumuman_user.php` | Portal siswa/pengajar |
| `absensi_catat.php`, `siswa_kelas.php` | Pengajar: catat kehadiran & kegiatan, daftar siswa per kelas |
| `api/index.php`, `api/.htaccess` | REST API (JSON) |
