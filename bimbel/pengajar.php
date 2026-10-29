<?php
/**
 * pengajar.php — Daftar Data Pengajar (Admin).
 * Fitur: cari (nama/NIP/keahlian), filter status, pagination, hapus.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$status_opsi = ['aktif', 'nonaktif'];

// ---------------------------------------------------------------
// HAPUS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif (($_POST['aksi'] ?? '') === 'hapus') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id_user, nama_lengkap FROM pengajar WHERE id_pengajar = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            // Hapus akun -> profil pengajar ikut terhapus; kelas yang diampu menjadi "tanpa pengajar" (SET NULL)
            $pdo->prepare('DELETE FROM users WHERE id_user = :id')->execute([':id' => $row['id_user']]);
            flash_set('success', 'Pengajar "' . $row['nama_lengkap'] . '" berhasil dihapus.');
        } else {
            flash_set('error', 'Data pengajar tidak ditemukan.');
        }
    }
    redirect('pengajar.php' . query_url());
}

// ---------------------------------------------------------------
// FILTER & DATA
// ---------------------------------------------------------------
$q      = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', $status_opsi, true) ? $_GET['status'] : '';

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.nama_lengkap LIKE :q1 OR p.nip LIKE :q2 OR p.keahlian LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = $status;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$per     = 10;
$total   = (int) db_scalar($pdo, "SELECT COUNT(*) FROM pengajar p $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT p.id_pengajar, p.nip, p.nama_lengkap, p.jenis_kelamin, p.no_telp, p.email,
            p.keahlian, p.status, u.username,
            (SELECT COUNT(*) FROM kelas k WHERE k.id_pengajar = p.id_pengajar AND k.status = 'buka') AS jml_kelas
       FROM pengajar p
       JOIN users u ON u.id_user = p.id_user
       $sql_where
   ORDER BY p.nama_lengkap
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
$judul_halaman = 'Data Pengajar';
$sub_judul     = 'Kelola akun dan profil seluruh pengajar bimbel.';
$menu_aktif    = 'pengajar';
include __DIR__ . '/header.php';
?>

<div class="toolbar">
    <form method="get" action="pengajar.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama, NIP, atau keahlian">

        <select class="input" name="status" aria-label="Filter status">
            <option value="">Semua status</option>
            <?php foreach ($status_opsi as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--dark btn--sm">Terapkan</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="pengajar.php" class="btn btn--sm">Reset</a>
        <?php endif; ?>
    </form>

    <a href="pengajar_form.php" class="btn btn--primary">Tambah Pengajar</a>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Keahlian</th>
                        <th>Kontak</th>
                        <th>Kelas</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $p): ?>
                    <tr>
                        <td class="table-time"><?= e($p['nip']) ?></td>
                        <td>
                            <?= e($p['nama_lengkap']) ?>
                            <span class="cell-sub">@<?= e($p['username']) ?> &middot; <?= $p['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></span>
                        </td>
                        <td><?= e($p['keahlian'] ?? '—') ?></td>
                        <td>
                            <?= e($p['no_telp'] ?? '—') ?>
                            <?php if ($p['email']): ?><span class="cell-sub"><?= e($p['email']) ?></span><?php endif; ?>
                        </td>
                        <td><?= (int) $p['jml_kelas'] ?> kelas</td>
                        <td><?= badge_status($p['status']) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--sm btn--outline" href="pengajar_form.php?id=<?= (int) $p['id_pengajar'] ?>">Edit</a>
                                <form method="post" action="pengajar.php<?= e(query_url()) ?>"
                                      onsubmit="return confirm('Hapus pengajar <?= e(addslashes($p['nama_lengkap'])) ?>? Kelas yang diampu akan menjadi tanpa pengajar.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id" value="<?= (int) $p['id_pengajar'] ?>">
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
            <?= ($q !== '' || $status !== '') ? 'Tidak ada pengajar yang cocok dengan filter.' : 'Belum ada data pengajar. Klik "Tambah Pengajar" untuk memulai.' ?>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
