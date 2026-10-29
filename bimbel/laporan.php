<?php
/**
 * laporan.php — Laporan (Admin & Owner).
 *   laporan.php?jenis=pendaftaran  -> laporan pendaftaran siswa per periode
 *   laporan.php?jenis=pembayaran   -> laporan pembayaran / tagihan per periode
 *   laporan.php?jenis=bulanan      -> laporan bulanan (ringkasan + rincian per kelas)
 * Tambah &format=csv untuk mengunduh, atau tombol Cetak untuk mencetak halaman.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_admin_atau_owner();

$jenis_opsi = ['pendaftaran' => 'Laporan Pendaftaran', 'pembayaran' => 'Laporan Pembayaran', 'bulanan' => 'Laporan Bulanan'];
$jenis = array_key_exists($_GET['jenis'] ?? '', $jenis_opsi) ? $_GET['jenis'] : 'bulanan';

$bulan = (int) ($_GET['bulan'] ?? date('n'));
$tahun = (int) ($_GET['tahun'] ?? date('Y'));
if ($bulan < 1 || $bulan > 12) { $bulan = (int) date('n'); }
if ($tahun < 2000 || $tahun > 2100) { $tahun = (int) date('Y'); }
$semua_bulan = ($jenis !== 'bulanan') && (($_GET['bulan'] ?? '') === 'semua');   // pendaftaran/pembayaran boleh tanpa filter bulan

$awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
$akhir = date('Y-m-d', strtotime($awal . ' +1 month'));
$csv   = ($_GET['format'] ?? '') === 'csv';

$status_daftar = ['pending', 'aktif', 'selesai', 'batal'];
$status_bayar  = ['belum_bayar', 'menunggu_verifikasi', 'lunas'];
$status = $_GET['status'] ?? '';

$kelas_filter = (int) ($_GET['kelas'] ?? 0);
$kelas_list   = $pdo->query('SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas')->fetchAll();

$rows = [];
$ringkas = null;
$per_kelas = [];

if ($jenis === 'pendaftaran') {
    if (!in_array($status, $status_daftar, true)) { $status = ''; }
    $where = []; $p = [];
    if (!$semua_bulan) { $where[] = 'pd.tanggal_daftar >= :a AND pd.tanggal_daftar < :b'; $p[':a'] = $awal; $p[':b'] = $akhir; }
    if ($status !== '')   { $where[] = 'pd.status = :st'; $p[':st'] = $status; }
    if ($kelas_filter)    { $where[] = 'pd.id_kelas = :k'; $p[':k'] = $kelas_filter; }
    $st = $pdo->prepare(
        "SELECT pd.tanggal_daftar, s.nis, s.nama_lengkap, s.tingkat, k.nama_kelas, m.nama_mapel, k.biaya_bulanan, pd.status
           FROM pendaftaran pd
           JOIN siswa s          ON s.id_siswa = pd.id_siswa
           JOIN kelas k          ON k.id_kelas = pd.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
       ORDER BY pd.tanggal_daftar DESC, s.nama_lengkap"
    );
    $st->execute($p);
    $rows = $st->fetchAll();

    if ($csv) {
        kirim_csv('laporan-pendaftaran-' . ($semua_bulan ? 'semua' : $tahun . '-' . sprintf('%02d', $bulan)) . '.csv',
            ['Tanggal Daftar', 'NIS', 'Nama Siswa', 'Jenjang', 'Kelas', 'Mata Pelajaran', 'Biaya Bulanan', 'Status'],
            array_map(fn($r) => [$r['tanggal_daftar'], $r['nis'], $r['nama_lengkap'], $r['tingkat'], $r['nama_kelas'],
                                 $r['nama_mapel'], (int) $r['biaya_bulanan'], $r['status']], $rows));
    }
} elseif ($jenis === 'pembayaran') {
    if (!in_array($status, $status_bayar, true)) { $status = ''; }
    $where = []; $p = [];
    if (!$semua_bulan) { $where[] = 'pb.periode_bulan = :m AND pb.periode_tahun = :y'; $p[':m'] = $bulan; $p[':y'] = $tahun; }
    if ($status !== '')   { $where[] = 'pb.status = :st'; $p[':st'] = $status; }
    if ($kelas_filter)    { $where[] = 'pd.id_kelas = :k'; $p[':k'] = $kelas_filter; }
    $st = $pdo->prepare(
        "SELECT pb.no_invoice, pb.periode_bulan, pb.periode_tahun, pb.jumlah, pb.tanggal_bayar, pb.metode, pb.status,
                s.nama_lengkap, s.nis, k.nama_kelas
           FROM pembayaran pb
           JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
           JOIN siswa s        ON s.id_siswa = pd.id_siswa
           JOIN kelas k        ON k.id_kelas = pd.id_kelas
          " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
       ORDER BY pb.periode_tahun DESC, pb.periode_bulan DESC, s.nama_lengkap"
    );
    $st->execute($p);
    $rows = $st->fetchAll();

    $ringkas = ['lunas' => 0.0, 'menunggu' => 0.0, 'belum' => 0.0];
    foreach ($rows as $r) {
        $kunci = $r['status'] === 'lunas' ? 'lunas' : ($r['status'] === 'menunggu_verifikasi' ? 'menunggu' : 'belum');
        $ringkas[$kunci] += (float) $r['jumlah'];
    }

    if ($csv) {
        kirim_csv('laporan-pembayaran-' . ($semua_bulan ? 'semua' : $tahun . '-' . sprintf('%02d', $bulan)) . '.csv',
            ['No. Invoice', 'Periode', 'NIS', 'Nama Siswa', 'Kelas', 'Jumlah', 'Tanggal Bayar', 'Metode', 'Status'],
            array_map(fn($r) => [$r['no_invoice'], bulan_indo((int) $r['periode_bulan']) . ' ' . $r['periode_tahun'], $r['nis'],
                                 $r['nama_lengkap'], $r['nama_kelas'], (int) $r['jumlah'], $r['tanggal_bayar'] ?? '', $r['metode'] ?? '', $r['status']], $rows));
    }
} else {
    $ringkas   = ringkasan_bulanan($pdo, $bulan, $tahun);
    $per_kelas = laporan_bulanan_per_kelas($pdo, $bulan, $tahun);

    if ($csv) {
        kirim_csv('laporan-bulanan-' . $tahun . '-' . sprintf('%02d', $bulan) . '.csv',
            ['Kelas', 'Mata Pelajaran', 'Pengajar', 'Siswa Aktif', 'Pendaftar Baru', 'Pertemuan', 'Kehadiran Siswa (%)', 'Pemasukan'],
            array_map(fn($r) => [$r['nama_kelas'], $r['nama_mapel'], $r['pengajar'] ?? '', (int) $r['siswa_aktif'], (int) $r['daftar_baru'],
                                 (int) $r['pertemuan'], (int) $r['absen_total'] > 0 ? round($r['absen_hadir'] / $r['absen_total'] * 100) : '', (int) $r['pemasukan']], $per_kelas));
    }
}

$tahun_opsi = opsi_tahun_laporan($pdo);
if (!in_array($tahun, $tahun_opsi, true)) { $tahun_opsi[] = $tahun; rsort($tahun_opsi); }

$judul_halaman = $jenis_opsi[$jenis];
$sub_judul     = match ($jenis) {
    'pendaftaran' => 'Daftar pendaftaran siswa ke kelas pada periode terpilih.',
    'pembayaran'  => 'Rekap tagihan dan pembayaran SPP pada periode terpilih.',
    default       => 'Ringkasan kegiatan dan keuangan bimbel selama satu bulan.',
};
$menu_aktif = 'laporan_' . $jenis;
include __DIR__ . '/header.php';

$periode_teks = $semua_bulan && $jenis !== 'bulanan' ? 'Semua periode' : bulan_indo($bulan) . ' ' . $tahun;
$url_csv = 'laporan.php' . query_url(['format' => 'csv']);
?>

<form method="get" action="laporan.php" class="filters no-print">
    <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
    <div class="field">
        <label for="bulan">Bulan</label>
        <select class="input" id="bulan" name="bulan">
            <?php if ($jenis !== 'bulanan'): ?>
                <option value="semua" <?= $semua_bulan ? 'selected' : '' ?>>Semua bulan</option>
            <?php endif; ?>
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?= $i ?>" <?= (!$semua_bulan && $bulan === $i) ? 'selected' : '' ?>><?= e(bulan_indo($i)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="field">
        <label for="tahun">Tahun</label>
        <select class="input" id="tahun" name="tahun">
            <?php foreach ($tahun_opsi as $t): ?>
                <option value="<?= (int) $t ?>" <?= $tahun === (int) $t ? 'selected' : '' ?>><?= (int) $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($jenis !== 'bulanan'): ?>
        <div class="field">
            <label for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">Semua status</option>
                <?php foreach ($jenis === 'pendaftaran' ? $status_daftar : $status_bayar as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="kelas">Kelas</label>
            <select class="input" id="kelas" name="kelas">
                <option value="0">Semua kelas</option>
                <?php foreach ($kelas_list as $k): ?>
                    <option value="<?= (int) $k['id_kelas'] ?>" <?= $kelas_filter === (int) $k['id_kelas'] ? 'selected' : '' ?>><?= e($k['nama_kelas']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn btn--dark">Tampilkan</button>
    <a class="btn" href="<?= e($url_csv) ?>">Unduh CSV</a>
    <button type="button" class="btn" onclick="window.print()">Cetak</button>
</form>

<p class="eyebrow" style="margin-bottom:14px"><?= e($jenis_opsi[$jenis]) ?> &middot; <?= e($periode_teks) ?></p>

<?php if ($jenis === 'bulanan'): $r = $ringkas; ?>
    <section class="stats">
        <article class="stat stat--accent">
            <p class="stat__label">Pemasukan</p>
            <p class="stat__value stat__value--sm"><?= e(rupiah($r['pemasukan'])) ?></p>
            <p class="stat__meta">pembayaran lunas bulan ini</p>
        </article>
        <article class="stat">
            <p class="stat__label">Tagihan Lunas</p>
            <p class="stat__value"><?= $r['tagihan_lunas'] ?> / <?= $r['tagihan_jumlah'] ?></p>
            <p class="stat__meta">tunggakan <?= e(rupiah($r['tunggakan'])) ?></p>
        </article>
        <article class="stat">
            <p class="stat__label">Pendaftar Baru</p>
            <p class="stat__value"><?= $r['pendaftaran_baru'] ?></p>
            <p class="stat__meta"><?= $r['pendaftaran_aktif'] ?> pendaftaran aktif saat ini</p>
        </article>
        <article class="stat">
            <p class="stat__label">Pertemuan Terlaksana</p>
            <p class="stat__value"><?= $r['pertemuan'] ?></p>
            <p class="stat__meta">kehadiran siswa <?= $r['persen_hadir'] === null ? '—' : $r['persen_hadir'] . '%' ?></p>
        </article>
    </section>

    <div class="panel">
        <div class="panel__head"><h2 class="panel__title">Rincian per Kelas</h2></div>
        <?php if ($per_kelas): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Kelas</th><th>Pengajar</th><th>Siswa Aktif</th><th>Pendaftar Baru</th><th>Pertemuan</th><th>Kehadiran</th><th>Pemasukan</th></tr>
                    </thead>
                    <tbody>
                    <?php $tot = ['aktif' => 0, 'baru' => 0, 'pert' => 0, 'uang' => 0.0]; foreach ($per_kelas as $k):
                        $tot['aktif'] += (int) $k['siswa_aktif']; $tot['baru'] += (int) $k['daftar_baru'];
                        $tot['pert'] += (int) $k['pertemuan']; $tot['uang'] += (float) $k['pemasukan']; ?>
                        <tr>
                            <td><?= e($k['nama_kelas']) ?><span class="cell-sub"><?= e($k['nama_mapel']) ?></span></td>
                            <td><?= e($k['pengajar'] ?? '—') ?></td>
                            <td><?= (int) $k['siswa_aktif'] ?></td>
                            <td><?= (int) $k['daftar_baru'] ?></td>
                            <td><?= (int) $k['pertemuan'] ?></td>
                            <td><?= (int) $k['absen_total'] > 0 ? round($k['absen_hadir'] / $k['absen_total'] * 100) . '%' : '—' ?></td>
                            <td class="table-time"><?= e(rupiah($k['pemasukan'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="2">Total</td><td><?= $tot['aktif'] ?></td><td><?= $tot['baru'] ?></td><td><?= $tot['pert'] ?></td>
                            <td><?= $r['persen_hadir'] === null ? '—' : $r['persen_hadir'] . '%' ?></td><td class="table-time"><?= e(rupiah($tot['uang'])) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Belum ada kelas.</p>
        <?php endif; ?>
    </div>

<?php elseif ($jenis === 'pembayaran'): ?>
    <section class="stats">
        <article class="stat stat--accent">
            <p class="stat__label">Lunas</p>
            <p class="stat__value stat__value--sm"><?= e(rupiah($ringkas['lunas'])) ?></p>
        </article>
        <article class="stat">
            <p class="stat__label">Menunggu Verifikasi</p>
            <p class="stat__value stat__value--sm"><?= e(rupiah($ringkas['menunggu'])) ?></p>
        </article>
        <article class="stat">
            <p class="stat__label">Belum Dibayar</p>
            <p class="stat__value stat__value--sm"><?= e(rupiah($ringkas['belum'])) ?></p>
        </article>
    </section>

    <div class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Siswa</th><th>Kelas</th><th>Periode</th><th>Jumlah</th><th>Dibayar</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="table-time"><?= e($r['no_invoice']) ?></td>
                            <td><?= e($r['nama_lengkap']) ?><span class="cell-sub"><?= e($r['nis']) ?></span></td>
                            <td><?= e($r['nama_kelas']) ?></td>
                            <td><?= e(bulan_indo((int) $r['periode_bulan']) . ' ' . $r['periode_tahun']) ?></td>
                            <td class="table-time"><?= e(rupiah($r['jumlah'])) ?></td>
                            <td><?= $r['tanggal_bayar'] ? e(tanggal_indo($r['tanggal_bayar'], false)) . '<span class="cell-sub">' . e(ucfirst((string) $r['metode'])) . '</span>' : '—' ?></td>
                            <td><?= badge_status($r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="4">Total <?= count($rows) ?> tagihan</td><td class="table-time" colspan="3"><?= e(rupiah(array_sum(array_column($rows, 'jumlah')))) ?></td></tr></tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Tidak ada data pembayaran pada filter ini.</p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Tanggal</th><th>Siswa</th><th>Jenjang</th><th>Kelas</th><th>Biaya / Bulan</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="table-time"><?= e(tanggal_indo($r['tanggal_daftar'], false)) ?></td>
                            <td><?= e($r['nama_lengkap']) ?><span class="cell-sub"><?= e($r['nis']) ?></span></td>
                            <td><?= e($r['tingkat']) ?></td>
                            <td><?= e($r['nama_kelas']) ?><span class="cell-sub"><?= e($r['nama_mapel']) ?></span></td>
                            <td class="table-time"><?= e(rupiah($r['biaya_bulanan'])) ?></td>
                            <td><?= badge_status($r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="6">Total <?= count($rows) ?> pendaftaran
                            (<?= count(array_filter($rows, fn($x) => $x['status'] === 'aktif')) ?> aktif,
                            <?= count(array_filter($rows, fn($x) => $x['status'] === 'pending')) ?> pending,
                            <?= count(array_filter($rows, fn($x) => $x['status'] === 'batal')) ?> batal)</td></tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Tidak ada pendaftaran pada filter ini.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
