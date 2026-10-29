<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

// ---------------------------------------------------------------
// DATA RINGKASAN
// ---------------------------------------------------------------
$total_siswa    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM siswa");
$siswa_aktif    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM siswa WHERE status = 'aktif'");
$total_pengajar = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pengajar");
$pengajar_aktif = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pengajar WHERE status = 'aktif'");
$total_kelas    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM kelas");
$kelas_buka     = (int) db_scalar($pdo, "SELECT COUNT(*) FROM kelas WHERE status = 'buka'");
$daftar_pending = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'pending'");
$bayar_verif    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pembayaran WHERE status = 'menunggu_verifikasi'");
$bayar_belum    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pembayaran WHERE status = 'belum_bayar'");
$pendapatan     = (float) db_scalar($pdo,
    "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran
      WHERE status = 'lunas'
        AND tanggal_bayar >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND tanggal_bayar <  DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)");

// Jadwal kelas hari ini
$hari_ini = hari_indo();
$stmt = $pdo->prepare(
    "SELECT j.jam_mulai, j.jam_selesai, j.ruangan,
            k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar
       FROM jadwal j
       JOIN kelas k          ON k.id_kelas = j.id_kelas
       JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
  LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
      WHERE j.hari = :hari AND k.status = 'buka'
   ORDER BY j.jam_mulai"
);
$stmt->execute([':hari' => $hari_ini]);
$jadwal_hari_ini = $stmt->fetchAll();

// Pendaftaran terbaru
$pendaftaran_baru = $pdo->query(
    "SELECT pd.tanggal_daftar, pd.status, s.nama_lengkap, k.nama_kelas
       FROM pendaftaran pd
       JOIN siswa s ON s.id_siswa = pd.id_siswa
       JOIN kelas k ON k.id_kelas = pd.id_kelas
   ORDER BY pd.created_at DESC, pd.id_pendaftaran DESC
      LIMIT 5"
)->fetchAll();

// Pembayaran yang butuh tindakan
$pembayaran_tindakan = $pdo->query(
    "SELECT pb.no_invoice, pb.periode_bulan, pb.periode_tahun, pb.jumlah, pb.status, s.nama_lengkap
       FROM pembayaran pb
       JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
       JOIN siswa s        ON s.id_siswa = pd.id_siswa
      WHERE pb.status IN ('menunggu_verifikasi', 'belum_bayar')
   ORDER BY (pb.status = 'menunggu_verifikasi') DESC, pb.created_at DESC
      LIMIT 5"
)->fetchAll();

// Grafik: pendaftaran per bulan (6 bulan terakhir)
$rows = $pdo->query(
    "SELECT DATE_FORMAT(tanggal_daftar, '%Y-%m') AS ym, COUNT(*) AS jml
       FROM pendaftaran
      WHERE tanggal_daftar >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
   GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$grafik = [];
for ($i = 5; $i >= 0; $i--) {
    $d   = new DateTime('first day of this month');
    $d->modify("-$i month");
    $key = $d->format('Y-m');
    $grafik[] = [
        'label' => bulan_indo((int) $d->format('n'), true),
        'jml'   => (int) ($rows[$key] ?? 0),
        'now'   => $i === 0,
    ];
}
$grafik_max = max(1, max(array_column($grafik, 'jml')));

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = 'Dashboard';
$sub_judul     = 'Halo, ' . current_user()['nama'] . '. Berikut ringkasan operasional bimbel hari ini.';
$menu_aktif    = 'dashboard';
include __DIR__ . '/header.php';
?>

<!-- Kartu statistik -->
<section class="stats">
    <article class="stat">
        <p class="stat__label">Siswa Aktif</p>
        <p class="stat__value"><?= $siswa_aktif ?></p>
        <p class="stat__meta">dari <?= $total_siswa ?> siswa terdaftar</p>
    </article>

    <article class="stat">
        <p class="stat__label">Pengajar Aktif</p>
        <p class="stat__value"><?= $pengajar_aktif ?></p>
        <p class="stat__meta">dari <?= $total_pengajar ?> pengajar</p>
    </article>

    <article class="stat">
        <p class="stat__label">Kelas Dibuka</p>
        <p class="stat__value"><?= $kelas_buka ?></p>
        <p class="stat__meta">dari <?= $total_kelas ?> kelas</p>
    </article>

    <article class="stat">
        <p class="stat__label">Pendaftaran Pending</p>
        <p class="stat__value"><?= $daftar_pending ?></p>
        <p class="stat__meta">menunggu persetujuan admin</p>
    </article>

    <article class="stat">
        <p class="stat__label">Pembayaran Perlu Tindakan</p>
        <p class="stat__value"><?= $bayar_verif + $bayar_belum ?></p>
        <p class="stat__meta"><?= $bayar_verif ?> menunggu verifikasi &middot; <?= $bayar_belum ?> belum bayar</p>
    </article>

    <article class="stat stat--accent">
        <p class="stat__label">Pemasukan <?= e(bulan_indo((int) date('n'))) ?></p>
        <p class="stat__value stat__value--sm"><?= e(rupiah($pendapatan)) ?></p>
        <p class="stat__meta">dari pembayaran berstatus lunas</p>
    </article>
</section>

<!-- Jadwal hari ini + grafik -->
<section class="grid-2">
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Jadwal Hari Ini &middot; <?= e($hari_ini) ?></h2>
            <?php if ($l = url_jika_ada('jadwal.php')): ?>
                <a class="panel__link" href="<?= e($l) ?>">Kelola jadwal</a>
            <?php endif; ?>
        </div>

        <?php if ($jadwal_hari_ini): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Waktu</th><th>Kelas</th><th>Pengajar</th><th>Ruang</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jadwal_hari_ini as $j): ?>
                        <tr>
                            <td class="table-time"><?= e(substr($j['jam_mulai'], 0, 5)) ?> &ndash; <?= e(substr($j['jam_selesai'], 0, 5)) ?></td>
                            <td>
                                <?= e($j['nama_kelas']) ?>
                                <span class="cell-sub"><?= e($j['nama_mapel']) ?></span>
                            </td>
                            <td><?= e($j['pengajar'] ?? '—') ?></td>
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
            <h2 class="panel__title">Pendaftaran 6 Bulan Terakhir</h2>
        </div>
        <div class="panel__body">
            <div class="bars" role="img" aria-label="Grafik batang jumlah pendaftaran per bulan">
                <?php foreach ($grafik as $g): ?>
                    <div class="bars__col<?= $g['now'] ? ' is-now' : '' ?>">
                        <span class="bars__val"><?= $g['jml'] ?></span>
                        <div class="bars__bar" style="height: <?= round($g['jml'] / $grafik_max * 80) ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bars__labels">
                <?php foreach ($grafik as $g): ?>
                    <span><?= e($g['label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Pendaftaran terbaru + pembayaran -->
<section class="grid-2 grid-2--even">
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Pendaftaran Terbaru</h2>
            <?php if ($l = url_jika_ada('pendaftaran.php')): ?>
                <a class="panel__link" href="<?= e($l) ?>">Lihat semua</a>
            <?php endif; ?>
        </div>

        <?php if ($pendaftaran_baru): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Siswa</th><th>Tanggal</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendaftaran_baru as $p): ?>
                        <tr>
                            <td>
                                <?= e($p['nama_lengkap']) ?>
                                <span class="cell-sub"><?= e($p['nama_kelas']) ?></span>
                            </td>
                            <td><?= e(tanggal_indo($p['tanggal_daftar'], false)) ?></td>
                            <td><?= badge_status($p['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Belum ada data pendaftaran.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Pembayaran Perlu Tindakan</h2>
            <?php if ($l = url_jika_ada('pembayaran.php')): ?>
                <a class="panel__link" href="<?= e($l) ?>">Lihat semua</a>
            <?php endif; ?>
        </div>

        <?php if ($pembayaran_tindakan): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Siswa</th><th>Jumlah</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pembayaran_tindakan as $b): ?>
                        <tr>
                            <td>
                                <?= e($b['nama_lengkap']) ?>
                                <span class="cell-sub"><?= e(bulan_indo((int) $b['periode_bulan']) . ' ' . $b['periode_tahun']) ?></span>
                            </td>
                            <td class="table-time"><?= e(rupiah($b['jumlah'])) ?></td>
                            <td><?= badge_status($b['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Semua pembayaran sudah beres.</p>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
