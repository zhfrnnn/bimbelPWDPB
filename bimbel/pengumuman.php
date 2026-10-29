<?php
/**
 * pengumuman.php — Pusat Pengumuman (Admin).
 * Admin membuat pengumuman untuk siswa dan/atau pengajar, ke semua kelas atau satu kelas
 * (termasuk info perubahan jadwal). Pengumuman otomatis dari perubahan jadwal juga muncul di sini.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_admin();

$target_opsi = ['semua' => 'Siswa & pengajar', 'siswa' => 'Siswa saja', 'pengajar' => 'Pengajar saja'];
$tipe_opsi   = ['umum' => 'Pengumuman umum', 'jadwal' => 'Perubahan jadwal'];
$d = ['judul' => '', 'isi' => '', 'target' => 'semua', 'tipe' => 'umum', 'id_kelas' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
        redirect('pengumuman.php');
    }

    if ($aksi === 'hapus') {
        $pdo->prepare('DELETE FROM pengumuman WHERE id_pengumuman = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
        flash_set('success', 'Pengumuman dihapus.');
        redirect('pengumuman.php' . query_url());
    }

    if ($aksi === 'buat') {
        foreach ($d as $k => $_) {
            $d[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        if ($d['judul'] === '' || mb_strlen($d['judul']) > 150) {
            $errors[] = 'Judul wajib diisi (maksimal 150 karakter).';
        }
        if ($d['isi'] === '' || mb_strlen($d['isi']) > 5000) {
            $errors[] = 'Isi pengumuman wajib diisi (maksimal 5000 karakter).';
        }
        if (!isset($target_opsi[$d['target']]) || !isset($tipe_opsi[$d['tipe']])) {
            $errors[] = 'Target atau jenis pengumuman tidak valid.';
        }
        $id_kelas = null;
        if ($d['id_kelas'] !== '') {
            $id_kelas = (int) $d['id_kelas'];
            if (!db_scalar($pdo, 'SELECT COUNT(*) FROM kelas WHERE id_kelas = :id', [':id' => $id_kelas])) {
                $errors[] = 'Kelas tidak ditemukan.';
            }
        }
        if (!$errors) {
            buat_pengumuman($pdo, $d['judul'], $d['isi'], $d['tipe'], $d['target'], $id_kelas, (int) current_user()['id_user']);
            flash_set('success', 'Pengumuman terkirim ke portal ' . strtolower($target_opsi[$d['target']]) . '.');
            redirect('pengumuman.php');
        }
    }
}

$kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM kelas WHERE status = 'buka' ORDER BY nama_kelas")->fetchAll();

$per     = 10;
$total   = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM pengumuman');
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);
$stmt = $pdo->prepare(
    "SELECT g.id_pengumuman, g.judul, g.isi, g.tipe, g.target, g.created_at, k.nama_kelas
       FROM pengumuman g LEFT JOIN kelas k ON k.id_kelas = g.id_kelas
   ORDER BY g.created_at DESC, g.id_pengumuman DESC
      LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$stmt->execute();
$daftar = $stmt->fetchAll();

$judul_halaman = 'Pengumuman';
$sub_judul     = 'Kirim informasi ke siswa dan pengajar, termasuk perubahan jadwal.';
$menu_aktif    = 'pengumuman';
include __DIR__ . '/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert">
        <strong>Pengumuman belum bisa dikirim:</strong>
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="pengumuman.php" class="form-card" novalidate style="max-width:none;margin-bottom:24px">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="buat">
    <div class="form-section">
        <h2 class="form-section__title">Buat Pengumuman</h2>
        <p class="form-section__desc">Tampil di dashboard dan menu Pengumuman pada portal penerima.</p>
        <div class="form-grid">
            <div class="field field--full">
                <label for="judul">Judul <span class="req">*</span></label>
                <input class="input" type="text" id="judul" name="judul" value="<?= e($d['judul']) ?>" maxlength="150" required>
            </div>
            <div class="field field--full">
                <label for="isi">Isi <span class="req">*</span></label>
                <textarea class="input" id="isi" name="isi" rows="4" required><?= e($d['isi']) ?></textarea>
            </div>
            <div class="field">
                <label for="target">Penerima</label>
                <select class="input" id="target" name="target">
                    <?php foreach ($target_opsi as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $d['target'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="id_kelas">Kelas</label>
                <select class="input" id="id_kelas" name="id_kelas">
                    <option value="">Semua kelas</option>
                    <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= (int) $k['id_kelas'] ?>" <?= $d['id_kelas'] === (string) $k['id_kelas'] ? 'selected' : '' ?>><?= e($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tipe">Jenis</label>
                <select class="input" id="tipe" name="tipe">
                    <?php foreach ($tipe_opsi as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $d['tipe'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn--primary">Kirim Pengumuman</button></div>
</form>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title">Riwayat Pengumuman</h2></div>
    <?php if ($daftar): ?>
        <ul class="news">
            <?php foreach ($daftar as $g): ?>
                <li>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
                        <div>
                            <span class="news__title"><?= e($g['judul']) ?></span>
                            <?php if ($g['tipe'] === 'jadwal'): ?> <span class="badge badge--dark">Perubahan jadwal</span><?php endif; ?>
                            <span class="news__meta">
                                <?= e(tanggal_indo(substr($g['created_at'], 0, 10), false)) ?>, <?= e(substr($g['created_at'], 11, 5)) ?>
                                &middot; <?= e($target_opsi[$g['target']] ?? $g['target']) ?> &middot; <?= e($g['nama_kelas'] ?? 'Semua kelas') ?>
                            </span>
                        </div>
                        <form method="post" action="pengumuman.php<?= e(query_url()) ?>" onsubmit="return confirm('Hapus pengumuman ini?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aksi" value="hapus">
                            <input type="hidden" name="id" value="<?= (int) $g['id_pengumuman'] ?>">
                            <button type="submit" class="btn btn--sm btn--danger-ghost">Hapus</button>
                        </form>
                    </div>
                    <p class="news__body"><?= e($g['isi']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
        <?= paginasi($total, $per, $halaman) ?>
    <?php else: ?>
        <p class="empty">Belum ada pengumuman.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
