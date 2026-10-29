<?php
/**
 * dashboard_user.php — Dashboard Siswa & Pengajar (portal informasi).
 * Menampilkan jadwal kelas yang dibuat admin. Admin dialihkan ke dashboard admin.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_user();

$user     = current_user();
$is_siswa = $user['role'] === 'siswa';
$id_siswa    = (int) ($user['id_siswa'] ?? 0);
$id_pengajar = (int) ($user['id_pengajar'] ?? 0);

$hari_opsi = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$hari_ini  = hari_indo();

// ---------------------------------------------------------------
// DATA JADWAL & KELAS (sesuai peran)
// ---------------------------------------------------------------
if ($is_siswa) {
    // Jadwal dari kelas yang pendaftarannya sudah aktif
    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar
           FROM pendaftaran pd
           JOIN kelas k          ON k.id_kelas = pd.id_kelas
           JOIN jadwal j         ON j.id_kelas = k.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE pd.id_siswa = :id AND pd.status = 'aktif' AND k.status = 'buka'
       ORDER BY j.jam_mulai"
    );
    $stmt->execute([':id' => $id_siswa]);
    $semua_jadwal = $stmt->fetchAll();

    // Kelas saya (aktif + menunggu persetujuan)
    $stmt = $pdo->prepare(
        "SELECT k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar, pd.status
           FROM pendaftaran pd
           JOIN kelas k          ON k.id_kelas = pd.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE pd.id_siswa = :id AND pd.status IN ('aktif', 'pending')
       ORDER BY pd.status = 'aktif' DESC, k.nama_kelas"
    );
    $stmt->execute([':id' => $id_siswa]);
    $kelas_saya = $stmt->fetchAll();

    // Kehadiran
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(status = 'hadir'), 0) AS hadir, COUNT(*) AS total FROM absensi WHERE id_siswa = :id");
    $stmt->execute([':id' => $id_siswa]);
    $abs = $stmt->fetch();
    $persen_hadir = (int) $abs['total'] > 0 ? round($abs['hadir'] / $abs['total'] * 100) . '%' : '—';

    // Pembayaran terbaru
    $stmt = $pdo->prepare(
        "SELECT pb.periode_bulan, pb.periode_tahun, pb.jumlah, pb.status, k.nama_kelas
           FROM pembayaran pb
           JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
           JOIN kelas k        ON k.id_kelas = pd.id_kelas
          WHERE pd.id_siswa = :id
       ORDER BY pb.periode_tahun DESC, pb.periode_bulan DESC
          LIMIT 5"
    );
    $stmt->execute([':id' => $id_siswa]);
    $pembayaran = $stmt->fetchAll();
} else {
    // Pengajar: jadwal kelas yang diampu
    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.nama_kelas, m.nama_mapel,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS jml_siswa
           FROM jadwal j
           JOIN kelas k          ON k.id_kelas = j.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          WHERE k.id_pengajar = :id AND k.status = 'buka'
       ORDER BY j.jam_mulai"
    );
    $stmt->execute([':id' => $id_pengajar]);
    $semua_jadwal = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT k.nama_kelas, m.nama_mapel, k.tingkat,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS jml_siswa
           FROM kelas k
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          WHERE k.id_pengajar = :id AND k.status = 'buka'
       ORDER BY k.nama_kelas"
    );
    $stmt->execute([':id' => $id_pengajar]);
    $kelas_saya = $stmt->fetchAll();

    $total_siswa = array_sum(array_column($kelas_saya, 'jml_siswa'));
}

// Pengumuman & info perubahan jadwal terbaru
$pengumuman = pengumuman_untuk($pdo, $is_siswa ? 'siswa' : 'pengajar', $is_siswa ? $id_siswa : $id_pengajar, 4);

// Siswa hasil registrasi mandiri yang belum diverifikasi admin
$status_siswa = $is_siswa ? (string) db_scalar($pdo, 'SELECT status FROM siswa WHERE id_siswa = :id', [':id' => $id_siswa]) : '';

// Kelompokkan jadwal per hari
$mingguan = array_fill_keys($hari_opsi, []);
foreach ($semua_jadwal as $j) {
    $mingguan[$j['hari']][] = $j;
}
$jadwal_hari_ini = $mingguan[$hari_ini];

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = 'Dashboard';
$sub_judul     = 'Halo, ' . $user['nama'] . '. ' . ($is_siswa
    ? 'Berikut jadwal belajar dan informasi kelas Anda.'
    : 'Berikut jadwal mengajar dan kelas yang Anda ampu.');
$menu_aktif    = 'dashboard';
include __DIR__ . '/header.php';
?>

<?php if ($status_siswa === 'menunggu'): ?>
    <div class="alert alert--info" role="status">Akun Anda sedang menunggu verifikasi admin. Jadwal dan kelas akan tampil setelah pendaftaran disetujui.</div>
<?php endif; ?>
<?php if (!$is_siswa && url_jika_ada('absensi_catat.php')): ?>
    <p style="margin-bottom:16px"><a class="btn btn--primary" href="absensi_catat.php">Catat Kehadiran &amp; Kegiatan Belajar</a></p>
<?php endif; ?>

<!-- Kartu statistik -->
<section class="stats">
    <?php if ($is_siswa): ?>
        <article class="stat">
            <p class="stat__label">Kelas Aktif</p>
            <p class="stat__value"><?= count(array_filter($kelas_saya, fn($k) => $k['status'] === 'aktif')) ?></p>
            <p class="stat__meta">kelas yang sedang diikuti</p>
        </article>
        <article class="stat">
            <p class="stat__label">Kelas Hari Ini</p>
            <p class="stat__value"><?= count($jadwal_hari_ini) ?></p>
            <p class="stat__meta"><?= e($hari_ini) ?></p>
        </article>
        <article class="stat stat--accent">
            <p class="stat__label">Kehadiran</p>
            <p class="stat__value"><?= e($persen_hadir) ?></p>
            <p class="stat__meta"><?= (int) $abs['hadir'] ?> hadir dari <?= (int) $abs['total'] ?> pertemuan</p>
        </article>
    <?php else: ?>
        <article class="stat">
            <p class="stat__label">Kelas Diampu</p>
            <p class="stat__value"><?= count($kelas_saya) ?></p>
            <p class="stat__meta">kelas berstatus buka</p>
        </article>
        <article class="stat">
            <p class="stat__label">Siswa Aktif</p>
            <p class="stat__value"><?= (int) $total_siswa ?></p>
            <p class="stat__meta">di seluruh kelas Anda</p>
        </article>
        <article class="stat stat--accent">
            <p class="stat__label">Mengajar Hari Ini</p>
            <p class="stat__value"><?= count($jadwal_hari_ini) ?></p>
            <p class="stat__meta"><?= e($hari_ini) ?></p>
        </article>
    <?php endif; ?>
</section>

<!-- Jadwal hari ini + kelas saya -->
<section class="grid-2">
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Jadwal Hari Ini &middot; <?= e($hari_ini) ?></h2>
            <?php if ($l = url_jika_ada('jadwal_user.php')): ?>
                <a class="panel__link" href="<?= e($l) ?>">Lihat semua</a>
            <?php endif; ?>
        </div>

        <?php if ($jadwal_hari_ini): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Kelas</th>
                            <th><?= $is_siswa ? 'Pengajar' : 'Siswa' ?></th>
                            <th>Ruang</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jadwal_hari_ini as $j): ?>
                        <tr>
                            <td class="table-time"><?= e(substr($j['jam_mulai'], 0, 5)) ?> &ndash; <?= e(substr($j['jam_selesai'], 0, 5)) ?></td>
                            <td>
                                <?= e($j['nama_kelas']) ?>
                                <span class="cell-sub"><?= e($j['nama_mapel']) ?></span>
                            </td>
                            <td><?= $is_siswa ? e($j['pengajar'] ?? '—') : (int) $j['jml_siswa'] . ' siswa' ?></td>
                            <td><?= e($j['ruangan'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Tidak ada kelas yang dijadwalkan hari ini.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title"><?= $is_siswa ? 'Kelas Saya' : 'Kelas yang Diampu' ?></h2>
        </div>

        <?php if ($kelas_saya): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kelas</th>
                            <th><?= $is_siswa ? 'Status' : 'Siswa' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kelas_saya as $k): ?>
                        <tr>
                            <td>
                                <?= e($k['nama_kelas']) ?>
                                <span class="cell-sub">
                                    <?= e($k['nama_mapel']) ?>
                                    <?= $is_siswa ? '&middot; ' . e($k['pengajar'] ?? 'Pengajar belum ditentukan') : '&middot; ' . e($k['tingkat']) ?>
                                </span>
                            </td>
                            <td><?= $is_siswa ? badge_status($k['status']) : (int) $k['jml_siswa'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty"><?= $is_siswa ? 'Anda belum terdaftar di kelas mana pun. Hubungi admin bimbel.' : 'Belum ada kelas yang Anda ampu.' ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- Jadwal mingguan -->
<div class="panel" style="margin-bottom:24px">
    <div class="panel__head">
        <h2 class="panel__title">Jadwal Mingguan</h2>
    </div>
    <div class="week-wrap" style="border:0;margin-bottom:0">
        <div class="week">
            <?php foreach ($hari_opsi as $h): ?>
                <div class="week__day<?= $h === $hari_ini ? ' is-today' : '' ?>">
                    <div class="week__head"><span><?= e($h) ?></span><span><?= count($mingguan[$h]) ?></span></div>
                    <?php if ($mingguan[$h]): ?>
                        <?php foreach ($mingguan[$h] as $s): ?>
                            <div class="week__slot">
                                <span class="week__time"><?= e(substr($s['jam_mulai'], 0, 5)) ?>&ndash;<?= e(substr($s['jam_selesai'], 0, 5)) ?></span>
                                <strong><?= e($s['nama_kelas']) ?></strong>
                                <?= e($s['ruangan'] ?? '') ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="week__empty">Kosong</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($is_siswa): ?>
<!-- Status pembayaran (khusus siswa) -->
<div class="panel">
    <div class="panel__head">
        <h2 class="panel__title">Status Pembayaran</h2>
        <?php if ($l = url_jika_ada('pembayaran_user.php')): ?>
            <a class="panel__link" href="<?= e($l) ?>">Lihat semua</a>
        <?php endif; ?>
    </div>

    <?php if ($pembayaran): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Periode</th><th>Kelas</th><th>Jumlah</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pembayaran as $b): ?>
                    <tr>
                        <td><?= e(bulan_indo((int) $b['periode_bulan']) . ' ' . $b['periode_tahun']) ?></td>
                        <td><?= e($b['nama_kelas']) ?></td>
                        <td class="table-time"><?= e(rupiah($b['jumlah'])) ?></td>
                        <td><?= badge_status($b['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty">Belum ada tagihan pembayaran.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pengumuman & perubahan jadwal -->
<section class="panel" style="margin-top:24px">
    <div class="panel__head">
        <h2 class="panel__title">Pengumuman &amp; Perubahan Jadwal</h2>
        <a class="panel__link" href="pengumuman_user.php">Lihat semua</a>
    </div>
    <?php if ($pengumuman): ?>
        <ul class="news">
            <?php foreach ($pengumuman as $g): ?>
                <li>
                    <span class="news__title"><?= e($g['judul']) ?></span>
                    <?php if ($g['tipe'] === 'jadwal'): ?> <span class="badge badge--dark">Perubahan jadwal</span><?php endif; ?>
                    <span class="news__meta"><?= e(tanggal_indo(substr($g['created_at'], 0, 10), false)) ?> &middot; <?= e($g['nama_kelas'] ?? 'Semua kelas') ?></span>
                    <p class="news__body"><?= e($g['isi']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="empty">Belum ada pengumuman.</p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>
