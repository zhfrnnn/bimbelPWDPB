<?php
/**
 * dashboard_owner.php — Dashboard Owner (pemilik bimbel).
 * Hanya-baca: ringkasan keuangan, siswa, dan kegiatan akademik + pintasan ke laporan.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_owner();

$bulan = (int) date('n');
$tahun = (int) date('Y');
$ini   = ringkasan_bulanan($pdo, $bulan, $tahun);

$siswa_aktif    = (int) db_scalar($pdo, "SELECT COUNT(*) FROM siswa WHERE status = 'aktif'");
$siswa_menunggu = (int) db_scalar($pdo, "SELECT COUNT(*) FROM siswa WHERE status = 'menunggu'");
$pengajar_aktif = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pengajar WHERE status = 'aktif'");
$kelas_buka     = (int) db_scalar($pdo, "SELECT COUNT(*) FROM kelas WHERE status = 'buka'");
$tunggakan_all  = (float) db_scalar($pdo, "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE status = 'belum_bayar'");

// Grafik pemasukan 6 bulan terakhir
$rows = $pdo->query(
    "SELECT DATE_FORMAT(tanggal_bayar, '%Y-%m') AS ym, SUM(jumlah) AS total
       FROM pembayaran
      WHERE status = 'lunas'
        AND tanggal_bayar >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
   GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$grafik = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $grafik[] = ['label' => bulan_indo((int) $d->format('n'), true), 'nilai' => (float) ($rows[$d->format('Y-m')] ?? 0), 'now' => $i === 0];
}
$grafik_max = max(1.0, max(array_column($grafik, 'nilai')));

$per_kelas = laporan_bulanan_per_kelas($pdo, $bulan, $tahun);

$judul_halaman = 'Dashboard Owner';
$sub_judul     = 'Ringkasan bimbel bulan ' . bulan_indo($bulan) . ' ' . $tahun . '. Tampilan hanya-baca.';
$menu_aktif    = 'dashboard';
include __DIR__ . '/header.php';
?>

<section class="stats">
    <article class="stat stat--accent">
        <p class="stat__label">Pemasukan <?= e(bulan_indo($bulan)) ?></p>
        <p class="stat__value stat__value--sm"><?= e(rupiah($ini['pemasukan'])) ?></p>
        <p class="stat__meta">dari pembayaran lunas</p>
    </article>
    <article class="stat">
        <p class="stat__label">Tunggakan Tagihan</p>
        <p class="stat__value stat__value--sm"><?= e(rupiah($tunggakan_all)) ?></p>
        <p class="stat__meta">seluruh tagihan belum dibayar</p>
    </article>
    <article class="stat">
        <p class="stat__label">Siswa Aktif</p>
        <p class="stat__value"><?= $siswa_aktif ?></p>
        <p class="stat__meta"><?= $siswa_menunggu ?> menunggu verifikasi &middot; <?= $ini['pendaftaran_baru'] ?> pendaftar baru bulan ini</p>
    </article>
    <article class="stat">
        <p class="stat__label">Kehadiran Siswa</p>
        <p class="stat__value"><?= $ini['persen_hadir'] === null ? '—' : $ini['persen_hadir'] . '%' ?></p>
        <p class="stat__meta"><?= $ini['pertemuan'] ?> pertemuan terlaksana bulan ini</p>
    </article>
    <article class="stat">
        <p class="stat__label">Pengajar Aktif</p>
        <p class="stat__value"><?= $pengajar_aktif ?></p>
        <p class="stat__meta"><?= $kelas_buka ?> kelas dibuka</p>
    </article>
    <article class="stat">
        <p class="stat__label">Tagihan <?= e(bulan_indo($bulan)) ?></p>
        <p class="stat__value"><?= $ini['tagihan_lunas'] ?> / <?= $ini['tagihan_jumlah'] ?></p>
        <p class="stat__meta">sudah lunas</p>
    </article>
</section>

<section class="grid-2">
    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Rincian per Kelas &middot; <?= e(bulan_indo($bulan)) ?></h2>
            <a class="panel__link" href="laporan.php?jenis=bulanan">Laporan bulanan</a>
        </div>
        <?php if ($per_kelas): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Kelas</th><th>Siswa</th><th>Pertemuan</th><th>Pemasukan</th></tr></thead>
                    <tbody>
                    <?php foreach ($per_kelas as $k): ?>
                        <tr>
                            <td><?= e($k['nama_kelas']) ?><span class="cell-sub"><?= e($k['pengajar'] ?? 'Tanpa pengajar') ?></span></td>
                            <td><?= (int) $k['siswa_aktif'] ?></td>
                            <td><?= (int) $k['pertemuan'] ?></td>
                            <td class="table-time"><?= e(rupiah($k['pemasukan'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Belum ada kelas.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head"><h2 class="panel__title">Pemasukan 6 Bulan Terakhir</h2></div>
        <div class="panel__body">
            <div class="bars" role="img" aria-label="Grafik batang pemasukan per bulan">
                <?php foreach ($grafik as $g): ?>
                    <div class="bars__col<?= $g['now'] ? ' is-now' : '' ?>">
                        <span class="bars__val"><?= $g['nilai'] > 0 ? e(number_format($g['nilai'] / 1000, 0, ',', '.')) . 'rb' : '0' ?></span>
                        <div class="bars__bar" style="height: <?= round($g['nilai'] / $grafik_max * 80) ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bars__labels">
                <?php foreach ($grafik as $g): ?><span><?= e($g['label']) ?></span><?php endforeach; ?>
            </div>
            <p class="field__hint" style="margin-top:14px">Angka dalam ribu rupiah (rb).</p>
        </div>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
