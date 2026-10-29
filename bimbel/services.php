<?php
/**
 * services.php
 * Logika bisnis yang dipakai BERSAMA oleh halaman web dan REST API,
 * supaya aturan (validasi, kuota, absensi, dll.) hanya ada di satu tempat.
 *
 * Butuh: koneksi.php (variabel $pdo, fungsi e()) dan helpers.php.
 */
require_once __DIR__ . '/helpers.php';

/** Pengajar hanya boleh mengisi / mengubah absensi sampai sekian hari ke belakang. */
const BATAS_HARI_ABSENSI_PENGAJAR = 30;

function status_kehadiran(): array
{
    return ['hadir', 'izin', 'sakit', 'alpha'];
}

function daftar_tingkat(): array
{
    return ['SD', 'SMP', 'SMA', 'SMK', 'Umum'];
}

// =====================================================================
// JADWAL, KELAS, SISWA
// =====================================================================

/** Satu slot jadwal lengkap dengan kelas, mapel, dan pengajarnya. */
function slot_jadwal(PDO $pdo, int $id_jadwal): array|false
{
    $st = $pdo->prepare(
        'SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.id_kelas, k.nama_kelas, k.id_pengajar, m.nama_mapel, p.nama_lengkap AS pengajar
           FROM jadwal j
           JOIN kelas k          ON k.id_kelas = j.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE j.id_jadwal = :id'
    );
    $st->execute([':id' => $id_jadwal]);
    return $st->fetch();
}

/** Slot jadwal kelas-kelas yang dibuka dan diampu seorang pengajar. */
function slot_pengajar(PDO $pdo, int $id_pengajar): array
{
    $st = $pdo->prepare(
        "SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan, k.id_kelas, k.nama_kelas
           FROM jadwal j JOIN kelas k ON k.id_kelas = j.id_kelas
          WHERE k.id_pengajar = :p AND k.status = 'buka'
       ORDER BY FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.jam_mulai"
    );
    $st->execute([':p' => $id_pengajar]);
    return $st->fetchAll();
}

/** Siswa dengan pendaftaran aktif di sebuah kelas. */
function siswa_aktif_kelas(PDO $pdo, int $id_kelas): array
{
    $st = $pdo->prepare(
        "SELECT s.id_siswa, s.nis, s.nama_lengkap, s.jenis_kelamin, s.tingkat, s.sekolah_asal
           FROM pendaftaran pd JOIN siswa s ON s.id_siswa = pd.id_siswa
          WHERE pd.id_kelas = :k AND pd.status = 'aktif'
       ORDER BY s.nama_lengkap"
    );
    $st->execute([':k' => $id_kelas]);
    return $st->fetchAll();
}

/** Kelas-kelas yang diampu pengajar (semua status), dengan jumlah siswa aktif. */
function kelas_pengajar(PDO $pdo, int $id_pengajar): array
{
    $st = $pdo->prepare(
        "SELECT k.id_kelas, k.nama_kelas, k.tingkat, k.status, m.nama_mapel,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS jml_siswa
           FROM kelas k JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          WHERE k.id_pengajar = :p
       ORDER BY (k.status = 'buka') DESC, k.nama_kelas"
    );
    $st->execute([':p' => $id_pengajar]);
    return $st->fetchAll();
}

/** Kelas berstatus buka + mapel + pengajar + sisa kuota + ringkasan jadwal (untuk siswa). */
function kelas_terbuka(PDO $pdo, int $id_siswa = 0): array
{
    $st = $pdo->prepare(
        "SELECT k.id_kelas, k.nama_kelas, k.tingkat, k.kuota, k.biaya_bulanan, k.tahun_ajaran,
                m.id_mapel, m.nama_mapel, m.deskripsi, p.id_pengajar, p.nama_lengkap AS pengajar,
                (SELECT COUNT(*) FROM pendaftaran x
                  WHERE x.id_kelas = k.id_kelas AND x.status IN ('pending','aktif')) AS terisi,
                (SELECT pd.status FROM pendaftaran pd
                  WHERE pd.id_kelas = k.id_kelas AND pd.id_siswa = :s) AS status_saya
           FROM kelas k
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE k.status = 'buka'
       ORDER BY m.nama_mapel, k.nama_kelas"
    );
    $st->execute([':s' => $id_siswa]);
    $kelas = $st->fetchAll();

    $jd = $pdo->query(
        "SELECT j.id_kelas, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan FROM jadwal j
       ORDER BY FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.jam_mulai"
    )->fetchAll();
    $per_kelas = [];
    foreach ($jd as $j) {
        $per_kelas[(int) $j['id_kelas']][] = $j['hari'] . ' ' . substr($j['jam_mulai'], 0, 5) . '–' . substr($j['jam_selesai'], 0, 5)
            . ($j['ruangan'] ? ' (' . $j['ruangan'] . ')' : '');
    }
    foreach ($kelas as &$k) {
        $k['sisa_kuota'] = max(0, (int) $k['kuota'] - (int) $k['terisi']);
        $k['jadwal']     = $per_kelas[(int) $k['id_kelas']] ?? [];
    }
    unset($k);
    return $kelas;
}

// =====================================================================
// ABSENSI & CATATAN KEGIATAN BELAJAR (diisi pengajar)
// =====================================================================

/** Pertemuan + nilai absensi tiap siswa untuk jadwal & tanggal tertentu. */
function data_pertemuan(PDO $pdo, int $id_jadwal, string $tanggal): array
{
    $st = $pdo->prepare('SELECT * FROM pertemuan WHERE id_jadwal = :j AND tanggal = :t');
    $st->execute([':j' => $id_jadwal, ':t' => $tanggal]);
    $pertemuan = $st->fetch() ?: null;

    $nilai = [];   // id_siswa => [status, keterangan]
    if ($pertemuan) {
        $st = $pdo->prepare('SELECT id_siswa, status, keterangan FROM absensi WHERE id_pertemuan = :p');
        $st->execute([':p' => $pertemuan['id_pertemuan']]);
        foreach ($st->fetchAll() as $a) {
            $nilai[(int) $a['id_siswa']] = [$a['status'], (string) $a['keterangan']];
        }
    }
    return [$pertemuan, $nilai];
}

/**
 * Simpan satu pertemuan: catatan kegiatan (materi + catatan), kehadiran pengajar,
 * dan kehadiran tiap siswa aktif. Dipakai halaman admin, halaman pengajar, dan API.
 *
 * $in : pertemuan_ke, kehadiran_pengajar, materi, catatan,
 *       status[id_siswa] => hadir|izin|sakit|alpha, ket[id_siswa] => keterangan
 * $maks_mundur_hari : batas tanggal ke belakang (null = tanpa batas, untuk admin).
 *
 * @return array{errors: string[], id_pertemuan: int|null}
 */
function simpan_pertemuan(PDO $pdo, array $slot, string $tanggal, array $in, int $id_user, ?int $maks_mundur_hari = null): array
{
    $err = [];
    if (!tanggal_valid($tanggal)) {
        return ['errors' => ['Tanggal tidak valid.'], 'id_pertemuan' => null];
    }
    if ($tanggal > date('Y-m-d')) {
        $err[] = 'Tanggal pertemuan tidak boleh di masa depan.';
    }
    if ($maks_mundur_hari !== null && $tanggal < date('Y-m-d', strtotime('-' . $maks_mundur_hari . ' days'))) {
        $err[] = 'Absensi hanya bisa diisi sampai ' . $maks_mundur_hari . ' hari ke belakang. Hubungi admin untuk koreksi.';
    }

    $materi  = trim((string) ($in['materi'] ?? ''));
    $catatan = trim((string) ($in['catatan'] ?? ''));
    $hadir_p = (string) ($in['kehadiran_pengajar'] ?? 'hadir');
    $ke      = trim((string) ($in['pertemuan_ke'] ?? ''));
    $siswa   = siswa_aktif_kelas($pdo, (int) $slot['id_kelas']);

    if (!in_array($hadir_p, status_kehadiran(), true)) {
        $err[] = 'Status kehadiran pengajar tidak valid.';
    }
    if ($ke !== '' && (!ctype_digit($ke) || (int) $ke < 1 || (int) $ke > 999)) {
        $err[] = 'Pertemuan ke- harus angka 1-999.';
    }
    if (mb_strlen($materi) > 255 || mb_strlen($catatan) > 255) {
        $err[] = 'Materi dan catatan maksimal 255 karakter.';
    }
    if (!$siswa) {
        $err[] = 'Belum ada siswa aktif di kelas ini.';
    }
    if ($err) {
        return ['errors' => $err, 'id_pertemuan' => null];
    }

    try {
        $pdo->beginTransaction();

        if ($ke === '') {
            $ke = 1 + (int) db_scalar($pdo,
                'SELECT COUNT(*) FROM pertemuan WHERE id_jadwal = :j AND tanggal < :t',
                [':j' => $slot['id_jadwal'], ':t' => $tanggal]);
        }

        $pdo->prepare(
            'INSERT INTO pertemuan (id_jadwal, tanggal, pertemuan_ke, materi, kehadiran_pengajar, catatan, dicatat_oleh)
             VALUES (:j, :t, :ke, :m, :h, :c, :u)
             ON DUPLICATE KEY UPDATE pertemuan_ke = VALUES(pertemuan_ke), materi = VALUES(materi),
                                     kehadiran_pengajar = VALUES(kehadiran_pengajar), catatan = VALUES(catatan),
                                     dicatat_oleh = VALUES(dicatat_oleh)'
        )->execute([
            ':j' => $slot['id_jadwal'], ':t' => $tanggal, ':ke' => (int) $ke,
            ':m' => $materi === '' ? null : $materi, ':h' => $hadir_p,
            ':c' => $catatan === '' ? null : $catatan, ':u' => $id_user,
        ]);

        $id_pertemuan = (int) db_scalar($pdo,
            'SELECT id_pertemuan FROM pertemuan WHERE id_jadwal = :j AND tanggal = :t',
            [':j' => $slot['id_jadwal'], ':t' => $tanggal]);

        $up = $pdo->prepare(
            'INSERT INTO absensi (id_pertemuan, id_siswa, status, keterangan)
             VALUES (:p, :s, :st, :k)
             ON DUPLICATE KEY UPDATE status = VALUES(status), keterangan = VALUES(keterangan)'
        );
        foreach ($siswa as $s) {
            $sid = (int) $s['id_siswa'];
            $st  = $in['status'][$sid] ?? 'alpha';
            $ket = trim((string) ($in['ket'][$sid] ?? ''));
            if (!in_array($st, status_kehadiran(), true)) {
                $st = 'alpha';
            }
            $up->execute([':p' => $id_pertemuan, ':s' => $sid, ':st' => $st,
                          ':k' => $ket === '' ? null : mb_substr($ket, 0, 255)]);
        }

        $pdo->commit();
        return ['errors' => [], 'id_pertemuan' => $id_pertemuan];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['errors' => [APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan absensi. Silakan coba lagi.'],
                'id_pertemuan' => null];
    }
}

/** Riwayat pertemuan kelas-kelas pengajar (dengan catatan kegiatan belajar). */
function riwayat_pertemuan_pengajar(PDO $pdo, int $id_pengajar, int $limit, int $offset = 0): array
{
    $st = $pdo->prepare(
        "SELECT pt.id_pertemuan, pt.id_jadwal, pt.tanggal, pt.pertemuan_ke, pt.materi, pt.catatan, pt.kehadiran_pengajar,
                k.nama_kelas, j.jam_mulai, j.jam_selesai,
                COALESCE(SUM(a.status = 'hadir'), 0) AS hadir, COUNT(a.id_absensi) AS jml
           FROM pertemuan pt
           JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k  ON k.id_kelas = j.id_kelas
      LEFT JOIN absensi a ON a.id_pertemuan = pt.id_pertemuan
          WHERE k.id_pengajar = :id
       GROUP BY pt.id_pertemuan, pt.id_jadwal, pt.tanggal, pt.pertemuan_ke, pt.materi, pt.catatan,
                pt.kehadiran_pengajar, k.nama_kelas, j.jam_mulai, j.jam_selesai
       ORDER BY pt.tanggal DESC, j.jam_mulai DESC
          LIMIT :lim OFFSET :off"
    );
    $st->bindValue(':id', $id_pengajar, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

// =====================================================================
// PENGUMUMAN & INFO PERUBAHAN JADWAL
// =====================================================================

function buat_pengumuman(PDO $pdo, string $judul, string $isi, string $tipe, string $target, ?int $id_kelas, ?int $id_user): void
{
    $pdo->prepare(
        'INSERT INTO pengumuman (judul, isi, tipe, target, id_kelas, dibuat_oleh)
         VALUES (:j, :i, :t, :tg, :k, :u)'
    )->execute([':j' => mb_substr($judul, 0, 150), ':i' => $isi, ':t' => $tipe, ':tg' => $target, ':k' => $id_kelas, ':u' => $id_user]);
}

/** Teks ringkas satu slot, contoh: "Senin 15:00–16:30, Ruang A". */
function teks_slot(array $s): string
{
    return $s['hari'] . ' ' . substr($s['jam_mulai'], 0, 5) . '–' . substr($s['jam_selesai'], 0, 5)
        . (!empty($s['ruangan']) ? ', ' . $s['ruangan'] : '');
}

/** Buat pengumuman otomatis untuk siswa & pengajar kelas ketika jadwal berubah. */
function catat_perubahan_jadwal(PDO $pdo, int $id_kelas, string $nama_kelas, string $isi, ?int $id_user): void
{
    buat_pengumuman($pdo, 'Perubahan jadwal ' . $nama_kelas, $isi, 'jadwal', 'semua', $id_kelas, $id_user);
}

/**
 * Pengumuman yang boleh dilihat seorang siswa / pengajar:
 * target sesuai peran DAN (untuk semua kelas ATAU kelas yang ia ikuti / ampu).
 */
function pengumuman_untuk(PDO $pdo, string $role, int $id_profil, int $limit = 20, int $offset = 0): array
{
    if ($role === 'siswa') {
        $kelas_sql = "SELECT id_kelas FROM pendaftaran WHERE id_siswa = :p AND status IN ('aktif','pending')";
        $target    = "('semua','siswa')";
    } else {
        $kelas_sql = "SELECT id_kelas FROM kelas WHERE id_pengajar = :p";
        $target    = "('semua','pengajar')";
    }
    $st = $pdo->prepare(
        "SELECT g.id_pengumuman, g.judul, g.isi, g.tipe, g.created_at, k.nama_kelas
           FROM pengumuman g
      LEFT JOIN kelas k ON k.id_kelas = g.id_kelas
          WHERE g.target IN $target
            AND (g.id_kelas IS NULL OR g.id_kelas IN ($kelas_sql))
       ORDER BY g.created_at DESC, g.id_pengumuman DESC
          LIMIT :lim OFFSET :off"
    );
    $st->bindValue(':p', $id_profil, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function jumlah_pengumuman_untuk(PDO $pdo, string $role, int $id_profil): int
{
    if ($role === 'siswa') {
        $kelas_sql = "SELECT id_kelas FROM pendaftaran WHERE id_siswa = :p AND status IN ('aktif','pending')";
        $target    = "('semua','siswa')";
    } else {
        $kelas_sql = "SELECT id_kelas FROM kelas WHERE id_pengajar = :p";
        $target    = "('semua','pengajar')";
    }
    return (int) db_scalar($pdo,
        "SELECT COUNT(*) FROM pengumuman g
          WHERE g.target IN $target AND (g.id_kelas IS NULL OR g.id_kelas IN ($kelas_sql))",
        [':p' => $id_profil]);
}

// =====================================================================
// REGISTRASI SISWA MANDIRI & PENDAFTARAN KELAS
// =====================================================================

/** Validasi data profil siswa (dipakai form registrasi & API). Mengembalikan daftar pesan error. */
function validasi_registrasi_siswa(array $d, string $password): array
{
    $err = [];
    if (!preg_match('/^[A-Za-z0-9_.]{4,50}$/', $d['username'])) {
        $err[] = 'Username 4-50 karakter, hanya huruf, angka, titik, dan garis bawah.';
    }
    if (strlen($password) < 6) {
        $err[] = 'Password minimal 6 karakter.';
    }
    if ($d['nama_lengkap'] === '' || mb_strlen($d['nama_lengkap']) > 100) {
        $err[] = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
    }
    if (!in_array($d['jenis_kelamin'], ['L', 'P'], true)) {
        $err[] = 'Jenis kelamin tidak valid.';
    }
    if ($d['tanggal_lahir'] !== '' && (!tanggal_valid($d['tanggal_lahir']) || $d['tanggal_lahir'] > date('Y-m-d'))) {
        $err[] = 'Tanggal lahir tidak valid.';
    }
    if (!in_array($d['tingkat'], daftar_tingkat(), true)) {
        $err[] = 'Tingkat tidak valid.';
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Format email tidak valid.';
    }
    foreach (['no_telp' => 'No. telepon', 'no_telp_wali' => 'No. telepon wali'] as $f => $label) {
        if ($d[$f] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $d[$f])) {
            $err[] = $label . ' tidak valid (6-20 digit angka).';
        }
    }
    foreach (['sekolah_asal' => 100, 'nama_wali' => 100, 'email' => 100] as $f => $maks) {
        if (mb_strlen($d[$f]) > $maks) {
            $err[] = 'Isian ' . str_replace('_', ' ', $f) . ' maksimal ' . $maks . ' karakter.';
        }
    }
    if (mb_strlen($d['alamat']) > 1000) {
        $err[] = 'Alamat terlalu panjang (maksimal 1000 karakter).';
    }
    return $err;
}

/** Nama field profil siswa yang diterima saat registrasi. */
function field_registrasi_siswa(): array
{
    return ['username', 'nama_lengkap', 'jenis_kelamin', 'tanggal_lahir', 'tingkat', 'sekolah_asal',
            'no_telp', 'email', 'nama_wali', 'no_telp_wali', 'alamat'];
}

/**
 * Registrasi akun siswa oleh siswa sendiri.
 * Akun langsung bisa login, tetapi status siswa = 'menunggu' sampai admin memverifikasi.
 * Kelas yang dipilih masuk sebagai pendaftaran 'pending' (menunggu persetujuan admin).
 *
 * @param int[] $kelas_ids
 * @return array{errors: string[], id_siswa: int|null, kelas_ditolak: string[]}
 */
function registrasi_siswa(PDO $pdo, array $d, string $password, array $kelas_ids): array
{
    $err = validasi_registrasi_siswa($d, $password);
    if (!$err && db_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE username = :u', [':u' => $d['username']])) {
        $err[] = 'Username sudah dipakai. Pilih username lain.';
    }
    if ($err) {
        return ['errors' => $err, 'id_siswa' => null, 'kelas_ditolak' => []];
    }

    $nul = fn(string $v) => $v === '' ? null : $v;
    $ditolak = [];

    try {
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (:u, :p, 'siswa', 'aktif')")
            ->execute([':u' => $d['username'], ':p' => password_hash($password, PASSWORD_DEFAULT)]);
        $id_user = (int) $pdo->lastInsertId();

        // NIS otomatis: BM + 2 digit tahun + id akun 4 digit (unik karena id akun unik)
        $nis = sprintf('BM%s%04d', date('y'), $id_user);
        $n = 0;
        while (db_scalar($pdo, 'SELECT COUNT(*) FROM siswa WHERE nis = :n', [':n' => $nis])) {
            $nis = sprintf('BM%s%04d-%d', date('y'), $id_user, ++$n);
        }

        $pdo->prepare(
            "INSERT INTO siswa (id_user, nis, nama_lengkap, jenis_kelamin, tanggal_lahir, alamat, no_telp, email,
                                sekolah_asal, tingkat, nama_wali, no_telp_wali, tanggal_daftar, status)
             VALUES (:u, :nis, :nama, :jk, :lahir, :alamat, :telp, :email, :sekolah, :tingkat, :wali, :telp_wali, CURDATE(), 'menunggu')"
        )->execute([
            ':u' => $id_user, ':nis' => $nis, ':nama' => $d['nama_lengkap'], ':jk' => $d['jenis_kelamin'],
            ':lahir' => $nul($d['tanggal_lahir']), ':alamat' => $nul($d['alamat']), ':telp' => $nul($d['no_telp']),
            ':email' => $nul($d['email']), ':sekolah' => $nul($d['sekolah_asal']), ':tingkat' => $d['tingkat'],
            ':wali' => $nul($d['nama_wali']), ':telp_wali' => $nul($d['no_telp_wali']),
        ]);
        $id_siswa = (int) $pdo->lastInsertId();

        foreach (array_unique(array_map('intval', $kelas_ids)) as $id_kelas) {
            if ($id_kelas < 1) {
                continue;
            }
            $r = ajukan_kelas($pdo, $id_siswa, $id_kelas);
            if (!$r[0]) {
                $ditolak[] = $r[1];
            }
        }

        $pdo->commit();
        return ['errors' => [], 'id_siswa' => $id_siswa, 'kelas_ditolak' => $ditolak];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $ex->getCode() === '23000'
            ? 'Username sudah dipakai. Pilih username lain.'
            : (APP_DEBUG ? 'Gagal mendaftar: ' . $ex->getMessage() : 'Gagal mendaftar. Silakan coba lagi.');
        return ['errors' => [$msg], 'id_siswa' => null, 'kelas_ditolak' => []];
    }
}

/**
 * Siswa mengajukan diri ke sebuah kelas -> pendaftaran 'pending' (admin yang menyetujui).
 * @return array{0: bool, 1: string} [berhasil, pesan]
 */
function ajukan_kelas(PDO $pdo, int $id_siswa, int $id_kelas): array
{
    $st = $pdo->prepare("SELECT nama_kelas, status FROM kelas WHERE id_kelas = :id");
    $st->execute([':id' => $id_kelas]);
    $kelas = $st->fetch();
    if (!$kelas || $kelas['status'] !== 'buka') {
        return [false, 'Kelas tidak ditemukan atau sudah ditutup.'];
    }

    $st = $pdo->prepare('SELECT id_pendaftaran, status FROM pendaftaran WHERE id_siswa = :s AND id_kelas = :k');
    $st->execute([':s' => $id_siswa, ':k' => $id_kelas]);
    $ada = $st->fetch();

    if ($ada && in_array($ada['status'], ['pending', 'aktif'], true)) {
        return [false, 'Anda sudah terdaftar / sedang menunggu persetujuan di kelas "' . $kelas['nama_kelas'] . '".'];
    }
    if (kuota_tersisa($pdo, $id_kelas) < 1) {
        return [false, 'Kuota kelas "' . $kelas['nama_kelas'] . '" sudah penuh.'];
    }

    if ($ada) {   // pernah batal / selesai -> ajukan ulang
        $pdo->prepare("UPDATE pendaftaran SET status = 'pending', tanggal_daftar = CURDATE() WHERE id_pendaftaran = :id")
            ->execute([':id' => $ada['id_pendaftaran']]);
    } else {
        $pdo->prepare("INSERT INTO pendaftaran (id_siswa, id_kelas, tanggal_daftar, status) VALUES (:s, :k, CURDATE(), 'pending')")
            ->execute([':s' => $id_siswa, ':k' => $id_kelas]);
    }
    return [true, 'Pendaftaran kelas "' . $kelas['nama_kelas'] . '" terkirim, menunggu persetujuan admin.'];
}

/** Admin menyetujui pendaftaran -> siswa yang masih 'menunggu' otomatis menjadi 'aktif'. */
function aktifkan_siswa_jika_menunggu(PDO $pdo, int $id_siswa): void
{
    $pdo->prepare("UPDATE siswa SET status = 'aktif' WHERE id_siswa = :id AND status = 'menunggu'")
        ->execute([':id' => $id_siswa]);
}

// =====================================================================
// PEMBAYARAN (sisi siswa)
// =====================================================================

/** Siswa melaporkan sudah bayar. true bila berhasil (tagihan miliknya & masih belum dibayar). */
function lapor_bayar(PDO $pdo, int $id_siswa, int $id_pembayaran, string $metode): bool
{
    if (!in_array($metode, ['tunai', 'transfer', 'qris'], true)) {
        return false;
    }
    $st = $pdo->prepare(
        "UPDATE pembayaran pb
           JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
            SET pb.status = 'menunggu_verifikasi', pb.metode = :m, pb.tanggal_bayar = CURDATE()
          WHERE pb.id_pembayaran = :id AND pd.id_siswa = :s AND pb.status = 'belum_bayar'"
    );
    $st->execute([':m' => $metode, ':id' => $id_pembayaran, ':s' => $id_siswa]);
    return $st->rowCount() > 0;
}

// =====================================================================
// PENGAJAR (sisi siswa: info profil pengajar)
// =====================================================================

/**
 * Daftar pengajar aktif yang mengampu kelas buka. Kontak (email/telepon) hanya
 * disertakan untuk pengajar dari kelas yang sedang diikuti siswa (pendaftaran aktif).
 */
function info_pengajar_untuk_siswa(PDO $pdo, int $id_siswa): array
{
    $st = $pdo->prepare(
        "SELECT p.id_pengajar, p.nama_lengkap, p.pendidikan_terakhir, p.keahlian, p.tanggal_bergabung,
                p.email, p.no_telp,
                GROUP_CONCAT(DISTINCT k.nama_kelas ORDER BY k.nama_kelas SEPARATOR '||') AS kelas_diampu,
                MAX(EXISTS (SELECT 1 FROM pendaftaran pd
                             WHERE pd.id_kelas = k.id_kelas AND pd.id_siswa = :s AND pd.status = 'aktif')) AS pengajar_saya
           FROM pengajar p
           JOIN kelas k ON k.id_pengajar = p.id_pengajar AND k.status = 'buka'
          WHERE p.status = 'aktif'
       GROUP BY p.id_pengajar, p.nama_lengkap, p.pendidikan_terakhir, p.keahlian, p.tanggal_bergabung, p.email, p.no_telp
       ORDER BY pengajar_saya DESC, p.nama_lengkap"
    );
    $st->execute([':s' => $id_siswa]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['kelas_diampu'] = $r['kelas_diampu'] !== null ? explode('||', $r['kelas_diampu']) : [];
        if (!(int) $r['pengajar_saya']) {
            $r['email'] = null;
            $r['no_telp'] = null;
        }
    }
    unset($r);
    return $rows;
}

// =====================================================================
// STATISTIK & LAPORAN (Admin & Owner)
// =====================================================================

/** Ringkasan satu bulan: pendaftaran baru, pemasukan, tunggakan, kehadiran, pertemuan. */
function ringkasan_bulanan(PDO $pdo, int $bulan, int $tahun): array
{
    $awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
    $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));
    $p     = [':a' => $awal, ':b' => $akhir];

    $abs = $pdo->prepare(
        "SELECT COUNT(DISTINCT pt.id_pertemuan) AS pertemuan,
                COUNT(a.id_absensi) AS absen,
                COALESCE(SUM(a.status = 'hadir'), 0) AS hadir
           FROM pertemuan pt LEFT JOIN absensi a ON a.id_pertemuan = pt.id_pertemuan
          WHERE pt.tanggal >= :a AND pt.tanggal < :b"
    );
    $abs->execute($p);
    $a = $abs->fetch();

    return [
        'pendaftaran_baru' => (int) db_scalar($pdo, 'SELECT COUNT(*) FROM pendaftaran WHERE tanggal_daftar >= :a AND tanggal_daftar < :b', $p),
        'pendaftaran_aktif' => (int) db_scalar($pdo, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'aktif'"),
        'pemasukan' => (float) db_scalar($pdo,
            "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE status = 'lunas' AND tanggal_bayar >= :a AND tanggal_bayar < :b", $p),
        'tagihan_jumlah' => (int) db_scalar($pdo,
            'SELECT COUNT(*) FROM pembayaran WHERE periode_bulan = :m AND periode_tahun = :y', [':m' => $bulan, ':y' => $tahun]),
        'tagihan_lunas' => (int) db_scalar($pdo,
            "SELECT COUNT(*) FROM pembayaran WHERE periode_bulan = :m AND periode_tahun = :y AND status = 'lunas'", [':m' => $bulan, ':y' => $tahun]),
        'tunggakan' => (float) db_scalar($pdo,
            "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE periode_bulan = :m AND periode_tahun = :y AND status <> 'lunas'",
            [':m' => $bulan, ':y' => $tahun]),
        'pertemuan' => (int) $a['pertemuan'],
        'absen_total' => (int) $a['absen'],
        'absen_hadir' => (int) $a['hadir'],
        'persen_hadir' => (int) $a['absen'] > 0 ? round($a['hadir'] / $a['absen'] * 100) : null,
    ];
}

/** Rincian per kelas untuk satu bulan (laporan bulanan). */
function laporan_bulanan_per_kelas(PDO $pdo, int $bulan, int $tahun): array
{
    $awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
    $akhir = date('Y-m-d', strtotime($awal . ' +1 month'));

    $st = $pdo->prepare(
        "SELECT k.id_kelas, k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS siswa_aktif,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas
                    AND pd.tanggal_daftar >= :a1 AND pd.tanggal_daftar < :b1) AS daftar_baru,
                (SELECT COUNT(*) FROM pertemuan pt JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
                  WHERE j.id_kelas = k.id_kelas AND pt.tanggal >= :a2 AND pt.tanggal < :b2) AS pertemuan,
                (SELECT COUNT(*) FROM absensi ab JOIN pertemuan pt ON pt.id_pertemuan = ab.id_pertemuan
                   JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
                  WHERE j.id_kelas = k.id_kelas AND pt.tanggal >= :a3 AND pt.tanggal < :b3) AS absen_total,
                (SELECT COUNT(*) FROM absensi ab JOIN pertemuan pt ON pt.id_pertemuan = ab.id_pertemuan
                   JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
                  WHERE j.id_kelas = k.id_kelas AND ab.status = 'hadir' AND pt.tanggal >= :a4 AND pt.tanggal < :b4) AS absen_hadir,
                (SELECT COALESCE(SUM(pb.jumlah), 0) FROM pembayaran pb JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
                  WHERE pd.id_kelas = k.id_kelas AND pb.status = 'lunas'
                    AND pb.tanggal_bayar >= :a5 AND pb.tanggal_bayar < :b5) AS pemasukan
           FROM kelas k
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
       ORDER BY k.nama_kelas"
    );
    $par = [];
    foreach (range(1, 5) as $i) {
        $par[':a' . $i] = $awal;
        $par[':b' . $i] = $akhir;
    }
    $st->execute($par);
    return $st->fetchAll();
}

/** Opsi tahun untuk filter laporan (dari data terlama sampai tahun depan). */
function opsi_tahun_laporan(PDO $pdo): array
{
    $min = (int) db_scalar($pdo, 'SELECT MIN(YEAR(tanggal_daftar)) FROM pendaftaran');
    $min = $min > 2000 ? min($min, (int) date('Y')) : (int) date('Y');
    return range((int) date('Y') + 1, $min);
}

/** Kirim baris-baris ke browser sebagai file CSV (Excel Indonesia: pemisah titik koma). */
function kirim_csv(string $nama_file, array $header, array $baris): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nama_file . '"');
    echo "\xEF\xBB\xBF";            // BOM agar Excel membaca UTF-8
    echo "sep=;\r\n";
    $out = fopen('php://output', 'w');
    fputcsv($out, $header, ';', '"', '');
    foreach ($baris as $r) {
        // cegah formula injection di Excel
        $r = array_map(fn($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'" . $v : $v, $r);
        fputcsv($out, $r, ';', '"', '');
    }
    fclose($out);
    exit;
}
