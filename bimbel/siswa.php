<?php
/**
 * siswa.php — Daftar Data Siswa (Admin).
 * Fitur: cari (nama/NIS), filter tingkat & status, pagination, hapus.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$tingkat_opsi = ['SD', 'SMP', 'SMA', 'SMK', 'Umum'];
$status_opsi  = ['menunggu', 'aktif', 'nonaktif', 'lulus'];

// ---------------------------------------------------------------
// HAPUS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif (($_POST['aksi'] ?? '') === 'hapus') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id_user, nama_lengkap FROM siswa WHERE id_siswa = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            // Hapus akun user -> data siswa, pendaftaran, absensi ikut terhapus (CASCADE)
            $pdo->prepare('DELETE FROM users WHERE id_user = :id')->execute([':id' => $row['id_user']]);
            flash_set('success', 'Siswa "' . $row['nama_lengkap'] . '" berhasil dihapus.');
        } else {
            flash_set('error', 'Data siswa tidak ditemukan.');
        }
    }
    redirect('siswa.php' . query_url());
}

// ---------------------------------------------------------------
// FILTER & DATA
// ---------------------------------------------------------------
$q       = trim($_GET['q'] ?? '');
$tingkat = in_array($_GET['tingkat'] ?? '', $tingkat_opsi, true) ? $_GET['tingkat'] : '';
$status  = in_array($_GET['status'] ?? '', $status_opsi, true) ? $_GET['status'] : '';

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(s.nama_lengkap LIKE :q1 OR s.nis LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($tingkat !== '') {
    $where[] = 's.tingkat = :tingkat';
    $params[':tingkat'] = $tingkat;
}
if ($status !== '') {
    $where[] = 's.status = :status';
    $params[':status'] = $status;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$per     = 10;
$total   = (int) db_scalar($pdo, "SELECT COUNT(*) FROM siswa s $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT s.id_siswa, s.nis, s.nama_lengkap, s.jenis_kelamin, s.tingkat, s.no_telp, s.email, s.status,
            u.username,
            (SELECT COUNT(*) FROM pendaftaran pd
              WHERE pd.id_siswa = s.id_siswa AND pd.status IN ('pending', 'aktif')) AS jml_kelas
       FROM siswa s
       JOIN users u ON u.id_user = s.id_user
       $sql_where
   ORDER BY s.nama_lengkap
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
$judul_halaman = 'Data Siswa';
$sub_judul     = 'Kelola akun dan profil seluruh siswa bimbel.';
$menu_aktif    = 'siswa';
include __DIR__ . '/header.php';
?>

<div class="toolbar">
    <form method="get" action="siswa.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama atau NIS">

        <select class="input" name="tingkat" aria-label="Filter tingkat">
            <option value="">Semua tingkat</option>
            <?php foreach ($tingkat_opsi as $t): ?>
                <option value="<?= e($t) ?>" <?= $tingkat === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="input" name="status" aria-label="Filter status">
            <option value="">Semua status</option>
            <?php foreach ($status_opsi as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--dark btn--sm">Terapkan</button>
        <?php if ($q !== '' || $tingkat !== '' || $status !== ''): ?>
            <a href="siswa.php" class="btn btn--sm">Reset</a>
        <?php endif; ?>
    </form>

    <a href="siswa_form.php" class="btn btn--primary">Tambah Siswa</a>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIS</th>
                        <th>Nama</th>
                        <th>Tingkat</th>
                        <th>Kontak</th>
                        <th>Kelas</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $s): ?>
                    <tr>
                        <td class="table-time"><?= e($s['nis']) ?></td>
                        <td>
                            <?= e($s['nama_lengkap']) ?>
                            <span class="cell-sub">@<?= e($s['username']) ?> &middot; <?= $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></span>
                        </td>
                        <td><?= e($s['tingkat']) ?></td>
                        <td>
                            <?= e($s['no_telp'] ?? '—') ?>
                            <?php if ($s['email']): ?><span class="cell-sub"><?= e($s['email']) ?></span><?php endif; ?>
                        </td>
                        <td><?= (int) $s['jml_kelas'] ?> kelas</td>
                        <td><?= badge_status($s['status']) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--sm btn--outline" href="siswa_form.php?id=<?= (int) $s['id_siswa'] ?>">Edit</a>
                                <form method="post" action="siswa.php<?= e(query_url()) ?>"
                                      onsubmit="return confirm('Hapus siswa <?= e(addslashes($s['nama_lengkap'])) ?>? Pendaftaran dan absensinya ikut terhapus.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id" value="<?= (int) $s['id_siswa'] ?>">
                                    <button type="submit" class="btn btn--sm btn--danger-ghost">Hapus</button>
                                </form>
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
            <?= ($q !== '' || $tingkat !== '' || $status !== '') ? 'Tidak ada siswa yang cocok dengan filter.' : 'Belum ada data siswa. Klik "Tambah Siswa" untuk memulai.' ?>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
