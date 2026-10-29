<?php
/**
 * pembayaran_user.php — Tagihan & status pembayaran (khusus Siswa).
 * Siswa dapat melaporkan sudah membayar (status -> menunggu verifikasi);
 * admin yang menyatakan lunas.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_user();

$user = current_user();
if ($user['role'] !== 'siswa') {
    redirect(HALAMAN_USER);
}
$id_siswa    = (int) $user['id_siswa'];
$metode_opsi = ['tunai', 'transfer', 'qris'];

// ---------------------------------------------------------------
// LAPOR PEMBAYARAN (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $metode = $_POST['metode'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif (!in_array($metode, $metode_opsi, true)) {
        flash_set('error', 'Pilih metode pembayaran.');
    } else {
        // Hanya tagihan milik siswa ini yang masih belum dibayar
        $stmt = $pdo->prepare(
            "UPDATE pembayaran pb
               JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
                SET pb.status = 'menunggu_verifikasi', pb.metode = :m, pb.tanggal_bayar = CURDATE()
              WHERE pb.id_pembayaran = :id AND pd.id_siswa = :s AND pb.status = 'belum_bayar'"
        );
        $stmt->execute([':m' => $metode, ':id' => $id, ':s' => $id_siswa]);

        if ($stmt->rowCount() > 0) {
            flash_set('success', 'Laporan pembayaran terkirim. Admin akan memverifikasinya.');
        } else {
            flash_set('error', 'Tagihan tidak ditemukan atau sudah dilaporkan.');
        }
    }
    redirect('pembayaran_user.php' . query_url());
}

// ---------------------------------------------------------------
// DATA
// ---------------------------------------------------------------
$per     = 12;
$total   = (int) db_scalar($pdo,
    'SELECT COUNT(*) FROM pembayaran pb JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran WHERE pd.id_siswa = :s',
    [':s' => $id_siswa]);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT pb.id_pembayaran, pb.no_invoice, pb.periode_bulan, pb.periode_tahun, pb.jumlah,
            pb.tanggal_bayar, pb.metode, pb.status, k.nama_kelas
       FROM pembayaran pb
       JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
       JOIN kelas k        ON k.id_kelas = pd.id_kelas
      WHERE pd.id_siswa = :s
   ORDER BY pb.periode_tahun DESC, pb.periode_bulan DESC, pb.id_pembayaran DESC
      LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':s', $id_siswa, PDO::PARAM_INT);
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(pb.status = 'belum_bayar'), 0)         AS belum,
            COALESCE(SUM(pb.status = 'menunggu_verifikasi'), 0) AS verif,
            COALESCE(SUM(pb.status = 'lunas'), 0)               AS lunas,
            COALESCE(SUM(CASE WHEN pb.status = 'belum_bayar' THEN pb.jumlah END), 0) AS tunggakan
       FROM pembayaran pb JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
      WHERE pd.id_siswa = :s"
);
$stmt->execute([':s' => $id_siswa]);
$r = $stmt->fetch();

$judul_halaman = 'Pembayaran';
$sub_judul     = 'Lihat tagihan SPP dan laporkan pembayaran Anda.';
$menu_aktif    = 'pembayaran';
include __DIR__ . '/header.php';
?>

<section class="stats">
    <article class="stat">
        <p class="stat__label">Belum Dibayar</p>
        <p class="stat__value"><?= (int) $r['belum'] ?></p>
        <p class="stat__meta">tunggakan <?= e(rupiah($r['tunggakan'])) ?></p>
    </article>
    <article class="stat">
        <p class="stat__label">Menunggu Verifikasi</p>
        <p class="stat__value"><?= (int) $r['verif'] ?></p>
        <p class="stat__meta">sedang dicek admin</p>
    </article>
    <article class="stat stat--accent">
        <p class="stat__label">Lunas</p>
        <p class="stat__value"><?= (int) $r['lunas'] ?></p>
        <p class="stat__meta">tagihan terbayar</p>
    </article>
</section>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title">Daftar Tagihan</h2></div>
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Invoice</th><th>Periode</th><th>Kelas</th><th>Jumlah</th><th>Status</th><th class="text-right">Aksi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $b): ?>
                    <tr>
                        <td class="table-time"><?= e($b['no_invoice']) ?></td>
                        <td><?= e(bulan_indo((int) $b['periode_bulan']) . ' ' . $b['periode_tahun']) ?></td>
                        <td><?= e($b['nama_kelas']) ?></td>
                        <td class="table-time"><?= e(rupiah($b['jumlah'])) ?></td>
                        <td>
                            <?= badge_status($b['status']) ?>
                            <?php if ($b['metode'] && $b['tanggal_bayar']): ?>
                                <span class="cell-sub"><?= e(ucfirst($b['metode'])) ?> &middot; <?= e(tanggal_indo($b['tanggal_bayar'], false)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if ($b['status'] === 'belum_bayar'): ?>
                                    <form method="post" action="pembayaran_user.php<?= e(query_url()) ?>" class="inline-pay"
                                          onsubmit="return confirm('Laporkan bahwa Anda sudah membayar tagihan ini?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $b['id_pembayaran'] ?>">
                                        <select class="input" name="metode" aria-label="Metode pembayaran">
                                            <?php foreach ($metode_opsi as $m): ?>
                                                <option value="<?= e($m) ?>"><?= e(ucfirst($m)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn--sm btn--primary">Sudah Bayar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginasi($total, $per, $halaman) ?>
    <?php else: ?>
        <p class="empty">Belum ada tagihan pembayaran.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
