<?php
/**
 * jadwal.php — Kelas & Jadwal (Admin).
 * Menampilkan jadwal mingguan semua kelas yang dibuka + daftar kelas
 * lengkap dengan slot jadwalnya. Hapus kelas / hapus slot lewat POST.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_admin();

$hari_opsi   = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$status_opsi = ['buka', 'tutup'];

// ---------------------------------------------------------------
// AKSI HAPUS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } elseif ($aksi === 'hapus_slot') {
        $id = (int) ($_POST['id'] ?? 0);
        $slot = slot_jadwal($pdo, $id);
        $pdo->prepare('DELETE FROM jadwal WHERE id_jadwal = :id')->execute([':id' => $id]);
        if ($slot) {
            catat_perubahan_jadwal($pdo, (int) $slot['id_kelas'], $slot['nama_kelas'], 'Jadwal ' . teks_slot($slot) . ' dihapus.', (int) current_user()['id_user']);
        }
        flash_set('success', 'Slot jadwal berhasil dihapus.');
    } elseif ($aksi === 'hapus_kelas') {
        $id   = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT nama_kelas FROM kelas WHERE id_kelas = :id');
        $stmt->execute([':id' => $id]);
        $kelas = $stmt->fetch();

        if (!$kelas) {
            flash_set('error', 'Kelas tidak ditemukan.');
        } else {
            $jml = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM pendaftaran WHERE id_kelas = :id', [':id' => $id]);
            if ($jml > 0) {
                flash_set('error', 'Kelas "' . $kelas['nama_kelas'] . '" masih punya ' . $jml
                    . ' pendaftaran dan tidak bisa dihapus. Ubah statusnya menjadi Tutup jika sudah tidak dibuka.');
            } else {
                $pdo->prepare('DELETE FROM kelas WHERE id_kelas = :id')->execute([':id' => $id]);
                flash_set('success', 'Kelas "' . $kelas['nama_kelas'] . '" berhasil dihapus.');
            }
        }
    }
    redirect('jadwal.php' . query_url());
}

// ---------------------------------------------------------------
// JADWAL MINGGUAN (kelas yang dibuka)
// ---------------------------------------------------------------
$mingguan = array_fill_keys($hari_opsi, []);
$rows = $pdo->query(
    "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan, k.nama_kelas
       FROM jadwal j
       JOIN kelas k ON k.id_kelas = j.id_kelas
      WHERE k.status = 'buka'
   ORDER BY j.jam_mulai"
)->fetchAll();
foreach ($rows as $r) {
    $mingguan[$r['hari']][] = $r;
}
$hari_ini = hari_indo();

// ---------------------------------------------------------------
// DAFTAR KELAS (filter + pagination)
// ---------------------------------------------------------------
$q      = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', $status_opsi, true) ? $_GET['status'] : '';

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(k.nama_kelas LIKE :q1 OR m.nama_mapel LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'k.status = :status';
    $params[':status'] = $status;
}
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$per     = 8;
$total   = (int) db_scalar($pdo,
    "SELECT COUNT(*) FROM kelas k JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel $sql_where", $params);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$stmt = $pdo->prepare(
    "SELECT k.id_kelas, k.nama_kelas, k.tingkat, k.kuota, k.biaya_bulanan, k.tahun_ajaran, k.status,
            m.nama_mapel, p.nama_lengkap AS pengajar,
            (SELECT COUNT(*) FROM pendaftaran pd
              WHERE pd.id_kelas = k.id_kelas AND pd.status IN ('pending', 'aktif')) AS terisi
       FROM kelas k
       JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
  LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
       $sql_where
   ORDER BY k.status = 'buka' DESC, k.nama_kelas
      LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

// Slot jadwal untuk kelas yang tampil di halaman ini
$slot = [];
if ($daftar) {
    $ids = array_column($daftar, 'id_kelas');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT id_jadwal, id_kelas, hari, jam_mulai, jam_selesai, ruangan
           FROM jadwal WHERE id_kelas IN ($in)
       ORDER BY FIELD(hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), jam_mulai"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $s) {
        $slot[$s['id_kelas']][] = $s;
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = 'Kelas & Jadwal';
$sub_judul     = 'Atur kelas bimbel dan jadwal mingguannya.';
$menu_aktif    = 'jadwal';
include __DIR__ . '/header.php';
?>

<!-- Jadwal mingguan -->
<div class="panel" style="margin-bottom:24px">
    <div class="panel__head">
        <h2 class="panel__title">Jadwal Mingguan</h2>
        <span class="muted" style="font-size:13px">Hanya kelas berstatus buka</span>
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

<!-- Daftar kelas -->
<div class="toolbar">
    <form method="get" action="jadwal.php" class="toolbar__filters">
        <input class="input input--search" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari kelas atau mata pelajaran">
        <select class="input" name="status" aria-label="Filter status">
            <option value="">Semua status</option>
            <option value="buka" <?= $status === 'buka' ? 'selected' : '' ?>>Buka</option>
            <option value="tutup" <?= $status === 'tutup' ? 'selected' : '' ?>>Tutup</option>
        </select>
        <button type="submit" class="btn btn--dark btn--sm">Terapkan</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="jadwal.php" class="btn btn--sm">Reset</a>
        <?php endif; ?>
    </form>
    <a href="kelas_form.php" class="btn btn--primary">Tambah Kelas</a>
</div>

<div class="panel">
    <?php if ($daftar): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Pengajar</th>
                        <th>Terisi</th>
                        <th>Biaya / Bulan</th>
                        <th>Jadwal</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $k): ?>
                    <tr>
                        <td>
                            <?= e($k['nama_kelas']) ?>
                            <span class="cell-sub"><?= e($k['nama_mapel']) ?> &middot; <?= e($k['tingkat']) ?> &middot; <?= e($k['tahun_ajaran']) ?></span>
                        </td>
                        <td><?= e($k['pengajar'] ?? '—') ?></td>
                        <td class="table-time"><?= (int) $k['terisi'] ?> / <?= (int) $k['kuota'] ?></td>
                        <td class="table-time"><?= e(rupiah($k['biaya_bulanan'])) ?></td>
                        <td>
                            <?php if (!empty($slot[$k['id_kelas']])): ?>
                                <ul class="slot-list">
                                <?php foreach ($slot[$k['id_kelas']] as $s): ?>
                                    <li>
                                        <span class="table-time"><?= e($s['hari']) ?> <?= e(substr($s['jam_mulai'], 0, 5)) ?>&ndash;<?= e(substr($s['jam_selesai'], 0, 5)) ?></span>
                                        <?php if ($s['ruangan']): ?><span class="muted"><?= e($s['ruangan']) ?></span><?php endif; ?>
                                        <a href="jadwal_form.php?id=<?= (int) $s['id_jadwal'] ?>">Ubah</a>
                                        <form method="post" action="jadwal.php<?= e(query_url()) ?>"
                                              onsubmit="return confirm('Hapus slot jadwal ini?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="aksi" value="hapus_slot">
                                            <input type="hidden" name="id" value="<?= (int) $s['id_jadwal'] ?>">
                                            <button type="submit" class="link-btn">Hapus</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="muted">Belum dijadwalkan</span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge_status($k['status']) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--sm btn--primary" href="jadwal_form.php?kelas=<?= (int) $k['id_kelas'] ?>">+ Jadwal</a>
                                <a class="btn btn--sm btn--outline" href="kelas_form.php?id=<?= (int) $k['id_kelas'] ?>">Edit</a>
                                <form method="post" action="jadwal.php<?= e(query_url()) ?>"
                                      onsubmit="return confirm('Hapus kelas <?= e(addslashes($k['nama_kelas'])) ?> beserta seluruh jadwalnya?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="aksi" value="hapus_kelas">
                                    <input type="hidden" name="id" value="<?= (int) $k['id_kelas'] ?>">
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
            <?= ($q !== '' || $status !== '') ? 'Tidak ada kelas yang cocok dengan filter.' : 'Belum ada kelas. Klik "Tambah Kelas" untuk memulai.' ?>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
