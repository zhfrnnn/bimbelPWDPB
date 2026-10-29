<?php
/**
 * pembayaran.php — Tagihan & pembayaran SPP (Admin).
 * Fitur: buat tagihan bulanan massal, verifikasi bukti bayar dari siswa,
 * tandai lunas langsung, batalkan pelunasan, hapus tagihan, filter & pagination.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$status_opsi = ['belum_bayar', 'menunggu_verifikasi', 'lunas'];
$metode_opsi = ['tunai', 'transfer', 'qris'];
$admin_id    = (int) current_user()['id_user'];

// ---------------------------------------------------------------
// AKSI (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $id   = (int) ($_POST['id'] ?? 0);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif ($aksi === 'generate') {
        $bulan = (int) ($_POST['bulan'] ?? 0);
        $tahun = (int) ($_POST['tahun'] ?? 0);

        if ($bulan < 1 || $bulan > 12 || $tahun < 2020 || $tahun > 2100) {
            flash_set('error', 'Periode tagihan tidak valid.');
        } else {
            $ids = $pdo->query(
                "SELECT pd.id_pendaftaran FROM pendaftaran pd
                   JOIN kelas k ON k.id_kelas = pd.id_kelas
                  WHERE pd.status = 'aktif' AND k.biaya_bulanan > 0"
            )->fetchAll(PDO::FETCH_COLUMN);

            $baru = 0;
            foreach ($ids as $pid) {
                if (buat_tagihan($pdo, (int) $pid, $bulan, $tahun)) {
                    $baru++;
                }
            }
            flash_set($baru > 0 ? 'success' : 'info',
                $baru > 0
                    ? $baru . ' tagihan periode ' . bulan_indo($bulan) . ' ' . $tahun . ' berhasil dibuat.'
                    : 'Tidak ada tagihan baru. Semua pendaftaran aktif sudah punya tagihan periode ' . bulan_indo($bulan) . ' ' . $tahun . '.');
        }
    } else {
        $stmt = $pdo->prepare('SELECT id_pembayaran, no_invoice, status FROM pembayaran WHERE id_pembayaran = :id');
        $stmt->execute([':id' => $id]);
        $pb = $stmt->fetch();

        if (!$pb) {
            flash_set('error', 'Tagihan tidak ditemukan.');
        } elseif ($aksi === 'lunas') {
            $metode = $_POST['metode'] ?? '';
            if (!in_array($metode, $metode_opsi, true)) {
                flash_set('error', 'Pilih metode pembayaran.');
            } else {
                $pdo->prepare("UPDATE pembayaran SET status = 'lunas', metode = :m, tanggal_bayar = CURDATE(), diverifikasi_oleh = :a WHERE id_pembayaran = :id")
                    ->execute([':m' => $metode, ':a' => $admin_id, ':id' => $id]);
                flash_set('success', 'Tagihan ' . $pb['no_invoice'] . ' ditandai lunas.');
            }
        } elseif ($aksi === 'verifikasi') {
            $pdo->prepare("UPDATE pembayaran SET status = 'lunas', tanggal_bayar = COALESCE(tanggal_bayar, CURDATE()), diverifikasi_oleh = :a WHERE id_pembayaran = :id")
                ->execute([':a' => $admin_id, ':id' => $id]);
            flash_set('success', 'Pembayaran ' . $pb['no_invoice'] . ' diverifikasi dan berstatus lunas.');
        } elseif ($aksi === 'tolak' || $aksi === 'batal_lunas') {
            $pdo->prepare("UPDATE pembayaran SET status = 'belum_bayar', metode = NULL, tanggal_bayar = NULL, diverifikasi_oleh = NULL WHERE id_pembayaran = :id")
                ->execute([':id' => $id]);
            flash_set('success', $aksi === 'tolak'
                ? 'Bukti bayar ' . $pb['no_invoice'] . ' ditolak. Tagihan kembali berstatus belum bayar.'
                : 'Pelunasan ' . $pb['no_invoice'] . ' dibatalkan.');
        } elseif ($aksi === 'hapus') {
            $pdo->prepare('DELETE FROM pembayaran WHERE id_pembayaran = :id')->execute([':id' => $id]);
            flash_set('success', 'Tagihan ' . $pb['no_invoice'] . ' dihapus.');
        }
    }
    redirect('pembayaran.php' . query_url());
}

// ---------------------------------------------------------------
// FILTER & DATA
// ---------------------------------------------------------------
$q      = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', $status_opsi, true) ? $_GET['status'] : '';
$bulan  = (int) ($_GET['bulan'] ?? 0);
$bulan  = ($bulan >= 1 && $bulan <= 12) ? $bulan : 0;
$tahun  = (int) ($_GET['tahun'] ?? 0);
$tahun  = ($tahun >= 2020 && $tahun <= 2100) ? $tahun : 0;

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(s.nama_lengkap LIKE :q1 OR pb.no_invoice LIKE :q2 OR k.nama_kelas LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'pb.status = :status';
    $params[':status'] = $status;
}
if ($bulan) {
    $where[] = 'pb.periode_bulan = :bulan';
    $params[':bulan'] = $bulan;
}
if ($tahun) {
    $where[] = 'pb.periode_tahun = :tahun';
    $params[':tahun'] = $tahun;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql_from  = "FROM pembayaran pb
                JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
                JOIN siswa s        ON s.id_siswa = pd.id_siswa
                JOIN kelas k        ON k.id_kelas = pd.id_kelas";

$per     = 10;
$total   = (int) db_scalar($pdo, "SELECT COUNT(*) $sql_from $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT pb.id_pembayaran, pb.no_invoice, pb.periode_bulan, pb.periode_tahun, pb.jumlah,
            pb.tanggal_bayar, pb.metode, pb.status, s.nama_lengkap, k.nama_kelas
       $sql_from $sql_where
   ORDER BY FIELD(pb.status, 'menunggu_verifikasi', 'belum_bayar', 'lunas'),
            pb.periode_tahun DESC, pb.periode_bulan DESC, s.nama_lengkap
      LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

// Ringkasan
$r_verif   = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pembayaran WHERE status = 'menunggu_verifikasi'");
$r_belum   = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pembayaran WHERE status = 'belum_bayar'");
$r_tunggak = (float) db_scalar($pdo, "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE status = 'belum_bayar'");
$r_masuk   = (float) db_scalar($pdo,
    "SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran
      WHERE status = 'lunas'
        AND tanggal_bayar >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND tanggal_bayar <  DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)");

$judul_halaman = 'Pembayaran';
$sub_judul     = 'Pantau tagihan SPP, verifikasi pembayaran siswa, dan catat pelunasan.';
$menu_aktif    = 'pembayaran';
include __DIR__ . '/header.php';

$aksi_form = function (int $id, string $aksi, string $label, string $kelas, string $konfirmasi = '') {
    return '<form method="post" action="pembayaran.php' . e(query_url()) . '"'
        . ($konfirmasi !== '' ? ' onsubmit="return confirm(\'' . e(addslashes($konfirmasi)) . '\');"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="aksi" value="' . e($aksi) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="btn btn--sm ' . $kelas . '">' . e($label) . '</button></form>';
};
?>

<section class="stats">
    <article class="stat">
        <p class="stat__label">Menunggu Verifikasi</p>
        <p class="stat__value"><?= $r_verif ?></p>
        <p class="stat__meta">bukti bayar dari siswa</p>
    </article>
    <article class="stat">
        <p class="stat__label">Belum Bayar</p>
        <p class="stat__value"><?= $r_belum ?></p>
        <p class="stat__meta">tunggakan <?= e(rupiah($r_tunggak)) ?></p>
    </article>
    <article class="stat stat--accent">
        <p class="stat__label">Pemasukan <?= e(bulan_indo((int) date('n'))) ?></p>
        <p class="stat__value stat__value--sm"><?= e(rupiah($r_masuk)) ?></p>
        <p class="stat__meta">pembayaran lunas bulan ini</p>
    </article>
</section>

<!-- Buat tagihan massal -->
<div class="panel" style="margin-bottom:24px">
    <div class="panel__head"><h2 class="panel__title">Buat Tagihan Bulanan</h2></div>
    <div class="panel__body">
        <form method="post" action="pembayaran.php" class="toolbar__filters" style="align-items:flex-end"
              onsubmit="return confirm('Buat tagihan untuk seluruh pendaftaran aktif pada periode ini?');">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="generate">
            <div class="field" style="margin:0">
                <label for="g_bulan">Bulan</label>
                <select class="input" id="g_bulan" name="bulan">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === (int) date('n') ? 'selected' : '' ?>><?= e(bulan_indo($i)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field" style="margin:0">
                <label for="g_tahun">Tahun</label>
                <input class="input" type="number" id="g_tahun" name="tahun" min="2020" max="2100" value="<?= (int) date('Y') ?>" style="width:110px">
            </div>
            <button type="submit" class="btn btn--primary">Buat Tagihan</button>
        </form>
        <p class="field__hint" style="margin-top:12px">Hanya pendaftaran berstatus aktif pada kelas berbiaya yang ditagih. Tagihan yang sudah ada tidak dibuat ulang.</p>
    </div>
</div>

<div class="toolbar">
    <form method="get" action="pembayaran.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari siswa, kelas, atau invoice">

        <select class="input" name="status" aria-label="Filter status">
            <option value="">Semua status</option>
            <?php foreach ($status_opsi as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="input" name="bulan" aria-label="Filter bulan">
            <option value="">Semua bulan</option>
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?= $i ?>" <?= $bulan === $i ? 'selected' : '' ?>><?= e(bulan_indo($i)) ?></option>
            <?php endfor; ?>
        </select>

        <input class="input" type="number" name="tahun" min="2020" max="2100" value="<?= $tahun ?: '' ?>" placeholder="Tahun" style="min-width:100px;width:100px" aria-label="Filter tahun">

        <button type="submit" class="btn btn--dark btn--sm">Terapkan</button>
        <?php if ($q !== '' || $status !== '' || $bulan || $tahun): ?>
            <a href="pembayaran.php" class="btn btn--sm">Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Siswa</th>
                        <th>Periode</th>
                        <th>Jumlah</th>
                        <th>Pembayaran</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $b): $id = (int) $b['id_pembayaran']; ?>
                    <tr>
                        <td class="table-time"><?= e($b['no_invoice']) ?></td>
                        <td>
                            <?= e($b['nama_lengkap']) ?>
                            <span class="cell-sub"><?= e($b['nama_kelas']) ?></span>
                        </td>
                        <td><?= e(bulan_indo((int) $b['periode_bulan']) . ' ' . $b['periode_tahun']) ?></td>
                        <td class="table-time"><?= e(rupiah($b['jumlah'])) ?></td>
                        <td>
                            <?php if ($b['metode']): ?>
                                <?= e(ucfirst($b['metode'])) ?>
                                <?php if ($b['tanggal_bayar']): ?><span class="cell-sub"><?= e(tanggal_indo($b['tanggal_bayar'], false)) ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge_status($b['status']) ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($b['status'] === 'menunggu_verifikasi'): ?>
                                    <?= $aksi_form($id, 'verifikasi', 'Verifikasi', 'btn--primary') ?>
                                    <?= $aksi_form($id, 'tolak', 'Tolak', 'btn--outline', 'Tolak bukti bayar ini?') ?>
                                <?php elseif ($b['status'] === 'belum_bayar'): ?>
                                    <form method="post" action="pembayaran.php<?= e(query_url()) ?>" class="inline-pay">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="aksi" value="lunas">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <select class="input" name="metode" aria-label="Metode pembayaran">
                                            <?php foreach ($metode_opsi as $m): ?>
                                                <option value="<?= e($m) ?>"><?= e(ucfirst($m)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn--sm btn--primary">Lunas</button>
                                    </form>
                                <?php else: ?>
                                    <?= $aksi_form($id, 'batal_lunas', 'Batalkan', 'btn--outline', 'Batalkan pelunasan tagihan ini?') ?>
                                <?php endif; ?>
                                <?= $aksi_form($id, 'hapus', 'Hapus', 'btn--danger-ghost', 'Hapus tagihan ini?') ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginasi($total, $per, $halaman) ?>
    <?php else: ?>
        <p class="empty">
            <?= ($q !== '' || $status !== '' || $bulan || $tahun) ? 'Tidak ada tagihan yang cocok dengan filter.' : 'Belum ada tagihan. Setujui pendaftaran atau gunakan "Buat Tagihan".' ?>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
