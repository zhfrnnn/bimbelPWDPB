<?php
/**
 * pendaftaran.php — Pendaftaran siswa ke kelas (Admin).
 * Fitur: cari, filter status, pagination, setujui / selesai / batal / hapus.
 * Saat pendaftaran disetujui (aktif), tagihan SPP bulan berjalan dibuat otomatis.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_admin();

$status_opsi = ['pending', 'aktif', 'selesai', 'batal'];

// ---------------------------------------------------------------
// AKSI (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $id   = (int) ($_POST['id'] ?? 0);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } else {
        $stmt = $pdo->prepare(
            'SELECT pd.id_pendaftaran, pd.id_siswa, pd.id_kelas, pd.status, s.nama_lengkap, k.nama_kelas
               FROM pendaftaran pd
               JOIN siswa s ON s.id_siswa = pd.id_siswa
               JOIN kelas k ON k.id_kelas = pd.id_kelas
              WHERE pd.id_pendaftaran = :id'
        );
        $stmt->execute([':id' => $id]);
        $pd = $stmt->fetch();

        if (!$pd) {
            flash_set('error', 'Data pendaftaran tidak ditemukan.');
        } elseif ($aksi === 'status') {
            $baru = $_POST['status'] ?? '';
            if (!in_array($baru, $status_opsi, true)) {
                flash_set('error', 'Status tidak valid.');
            } elseif (in_array($baru, ['pending', 'aktif'], true)
                      && in_array($pd['status'], ['selesai', 'batal'], true)
                      && kuota_tersisa($pdo, (int) $pd['id_kelas'], $id) < 1) {
                flash_set('error', 'Kuota kelas "' . $pd['nama_kelas'] . '" sudah penuh.');
            } else {
                $pdo->prepare('UPDATE pendaftaran SET status = :s WHERE id_pendaftaran = :id')
                    ->execute([':s' => $baru, ':id' => $id]);

                $pesan = 'Pendaftaran ' . $pd['nama_lengkap'] . ' di ' . $pd['nama_kelas'] . ' diubah menjadi ' . $baru . '.';
                if ($baru === 'aktif') {
                    aktifkan_siswa_jika_menunggu($pdo, (int) $pd['id_siswa']);   // akun hasil registrasi mandiri jadi aktif
                }
                if ($baru === 'aktif' && buat_tagihan($pdo, $id, (int) date('n'), (int) date('Y'))) {
                    $pesan .= ' Tagihan bulan ' . bulan_indo((int) date('n')) . ' dibuat otomatis.';
                }
                flash_set('success', $pesan);
            }
        } elseif ($aksi === 'hapus') {
            $pdo->prepare('DELETE FROM pendaftaran WHERE id_pendaftaran = :id')->execute([':id' => $id]);
            flash_set('success', 'Pendaftaran ' . $pd['nama_lengkap'] . ' di ' . $pd['nama_kelas'] . ' dihapus beserta tagihannya.');
        }
    }
    redirect('pendaftaran.php' . query_url());
}

// ---------------------------------------------------------------
// FILTER & DATA
// ---------------------------------------------------------------
$q      = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', $status_opsi, true) ? $_GET['status'] : '';

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(s.nama_lengkap LIKE :q1 OR s.nis LIKE :q2 OR k.nama_kelas LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'pd.status = :status';
    $params[':status'] = $status;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$per     = 10;
$total   = (int) db_scalar($pdo,
    "SELECT COUNT(*) FROM pendaftaran pd
       JOIN siswa s ON s.id_siswa = pd.id_siswa
       JOIN kelas k ON k.id_kelas = pd.id_kelas $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT pd.id_pendaftaran, pd.tanggal_daftar, pd.status, pd.catatan,
            s.nama_lengkap, s.nis, k.nama_kelas, k.biaya_bulanan, m.nama_mapel
       FROM pendaftaran pd
       JOIN siswa s          ON s.id_siswa = pd.id_siswa
       JOIN kelas k          ON k.id_kelas = pd.id_kelas
       JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
       $sql_where
   ORDER BY (pd.status = 'pending') DESC, pd.tanggal_daftar DESC, pd.id_pendaftaran DESC
      LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = 'Pendaftaran';
$sub_judul     = 'Kelola pendaftaran siswa ke kelas. Pendaftaran pending menunggu persetujuan Anda.';
$menu_aktif    = 'pendaftaran';
include __DIR__ . '/header.php';

/** Form tombol aksi kecil (POST). */
$tombol = function (int $id, string $aksi, string $label, string $kelas, string $status = '', string $konfirmasi = '') {
    $h  = '<form method="post" action="pendaftaran.php' . e(query_url()) . '"'
        . ($konfirmasi !== '' ? ' onsubmit="return confirm(\'' . e(addslashes($konfirmasi)) . '\');"' : '') . '>';
    $h .= csrf_field();
    $h .= '<input type="hidden" name="aksi" value="' . e($aksi) . '">';
    $h .= '<input type="hidden" name="id" value="' . $id . '">';
    if ($status !== '') {
        $h .= '<input type="hidden" name="status" value="' . e($status) . '">';
    }
    return $h . '<button type="submit" class="btn btn--sm ' . $kelas . '">' . e($label) . '</button></form>';
};
?>

<div class="toolbar">
    <form method="get" action="pendaftaran.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari siswa, NIS, atau kelas">

        <select class="input" name="status" aria-label="Filter status">
            <option value="">Semua status</option>
            <?php foreach ($status_opsi as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--dark btn--sm">Terapkan</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="pendaftaran.php" class="btn btn--sm">Reset</a>
        <?php endif; ?>
    </form>

    <a href="pendaftaran_form.php" class="btn btn--primary">Tambah Pendaftaran</a>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Siswa</th>
                        <th>Kelas</th>
                        <th>Biaya / Bulan</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $p): $id = (int) $p['id_pendaftaran']; ?>
                    <tr>
                        <td>
                            <?= e($p['nama_lengkap']) ?>
                            <span class="cell-sub"><?= e($p['nis']) ?></span>
                        </td>
                        <td>
                            <?= e($p['nama_kelas']) ?>
                            <span class="cell-sub"><?= e($p['nama_mapel']) ?></span>
                        </td>
                        <td class="table-time"><?= e(rupiah($p['biaya_bulanan'])) ?></td>
                        <td>
                            <?= e(tanggal_indo($p['tanggal_daftar'], false)) ?>
                            <?php if ($p['catatan']): ?><span class="cell-sub"><?= e($p['catatan']) ?></span><?php endif; ?>
                        </td>
                        <td><?= badge_status($p['status']) ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($p['status'] === 'pending'): ?>
                                    <?= $tombol($id, 'status', 'Setujui', 'btn--primary', 'aktif') ?>
                                    <?= $tombol($id, 'status', 'Tolak', 'btn--outline', 'batal', 'Tolak pendaftaran ini?') ?>
                                <?php elseif ($p['status'] === 'aktif'): ?>
                                    <?= $tombol($id, 'status', 'Selesai', 'btn--outline', 'selesai', 'Tandai pendaftaran ini selesai?') ?>
                                    <?= $tombol($id, 'status', 'Batalkan', 'btn--danger-ghost', 'batal', 'Batalkan pendaftaran ini?') ?>
                                <?php else: ?>
                                    <?= $tombol($id, 'status', 'Aktifkan lagi', 'btn--outline', 'aktif') ?>
                                <?php endif; ?>
                                <a class="btn btn--sm" href="pendaftaran_form.php?id=<?= $id ?>">Edit</a>
                                <?= $tombol($id, 'hapus', 'Hapus', 'btn--danger-ghost', '', 'Hapus pendaftaran ini? Tagihannya ikut terhapus.') ?>
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
            <?= ($q !== '' || $status !== '') ? 'Tidak ada pendaftaran yang cocok dengan filter.' : 'Belum ada pendaftaran. Klik "Tambah Pendaftaran" untuk memulai.' ?>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
