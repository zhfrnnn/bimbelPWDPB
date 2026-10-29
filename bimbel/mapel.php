<?php
/**
 * mapel.php — Daftar Mata Pelajaran (Admin): cari, tambah, ubah, hapus.
 * Mapel yang sudah dipakai kelas tidak bisa dihapus.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

// ---------------------------------------------------------------
// HAPUS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif (($_POST['aksi'] ?? '') === 'hapus') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT nama_mapel FROM mata_pelajaran WHERE id_mapel = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            flash_set('error', 'Mata pelajaran tidak ditemukan.');
        } else {
            $dipakai = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM kelas WHERE id_mapel = :id', [':id' => $id]);
            if ($dipakai > 0) {
                flash_set('error', 'Mata pelajaran "' . $row['nama_mapel'] . '" dipakai ' . $dipakai . ' kelas dan tidak bisa dihapus.');
            } else {
                $pdo->prepare('DELETE FROM mata_pelajaran WHERE id_mapel = :id')->execute([':id' => $id]);
                flash_set('success', 'Mata pelajaran "' . $row['nama_mapel'] . '" berhasil dihapus.');
            }
        }
    }
    redirect('mapel.php' . query_url());
}

// ---------------------------------------------------------------
// DATA
// ---------------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$params = [];
$sql_where = '';
if ($q !== '') {
    $sql_where = 'WHERE m.nama_mapel LIKE :q1 OR m.deskripsi LIKE :q2';
    $params = [':q1' => '%' . $q . '%', ':q2' => '%' . $q . '%'];
}

$per     = 10;
$total   = (int) db_scalar($pdo, "SELECT COUNT(*) FROM mata_pelajaran m $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT m.id_mapel, m.nama_mapel, m.deskripsi,
            (SELECT COUNT(*) FROM kelas k WHERE k.id_mapel = m.id_mapel) AS jml_kelas
       FROM mata_pelajaran m $sql_where
   ORDER BY m.nama_mapel
      LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

$judul_halaman = 'Mata Pelajaran';
$sub_judul     = 'Kelola daftar mata pelajaran yang diajarkan di bimbel.';
$menu_aktif    = 'mapel';
include __DIR__ . '/header.php';
?>

<div class="toolbar">
    <form method="get" action="mapel.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama atau deskripsi mapel">
        <button type="submit" class="btn btn--dark btn--sm">Cari</button>
        <?php if ($q !== ''): ?><a href="mapel.php" class="btn btn--sm">Reset</a><?php endif; ?>
    </form>
    <a href="mapel_form.php" class="btn btn--primary">Tambah Mapel</a>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Mata Pelajaran</th><th>Deskripsi</th><th>Kelas</th><th class="text-right">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($daftar as $m): ?>
                    <tr>
                        <td><strong><?= e($m['nama_mapel']) ?></strong></td>
                        <td><?= e($m['deskripsi'] ?? '—') ?></td>
                        <td><?= (int) $m['jml_kelas'] ?> kelas</td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--sm btn--outline" href="mapel_form.php?id=<?= (int) $m['id_mapel'] ?>">Edit</a>
                                <form method="post" action="mapel.php<?= e(query_url()) ?>"
                                      onsubmit="return confirm('Hapus mata pelajaran <?= e(addslashes($m['nama_mapel'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id" value="<?= (int) $m['id_mapel'] ?>">
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
        <p class="empty"><?= $q !== '' ? 'Tidak ada mapel yang cocok.' : 'Belum ada mata pelajaran. Klik "Tambah Mapel" untuk memulai.' ?></p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
