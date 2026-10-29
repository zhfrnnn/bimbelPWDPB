<?php
/**
 * mapel_form.php — Tambah / Edit mata pelajaran (Admin).
 *   mapel_form.php        -> tambah
 *   mapel_form.php?id=3   -> edit
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;
$d    = ['nama_mapel' => '', 'deskripsi' => ''];

if ($edit) {
    $stmt = $pdo->prepare('SELECT nama_mapel, deskripsi FROM mata_pelajaran WHERE id_mapel = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Mata pelajaran tidak ditemukan.');
        redirect('mapel.php');
    }
    $d = ['nama_mapel' => (string) $row['nama_mapel'], 'deskripsi' => (string) ($row['deskripsi'] ?? '')];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        if ($d['nama_mapel'] === '' || mb_strlen($d['nama_mapel']) > 100) {
            $errors[] = 'Nama mata pelajaran wajib diisi (maksimal 100 karakter).';
        }
        if (mb_strlen($d['deskripsi']) > 255) {
            $errors[] = 'Deskripsi maksimal 255 karakter.';
        }
        if (!$errors && db_scalar($pdo, 'SELECT COUNT(*) FROM mata_pelajaran WHERE nama_mapel = :n AND id_mapel <> :id',
                [':n' => $d['nama_mapel'], ':id' => $id])) {
            $errors[] = 'Nama mata pelajaran sudah ada.';
        }
    }

    if (!$errors) {
        $desk = $d['deskripsi'] === '' ? null : $d['deskripsi'];
        try {
            if ($edit) {
                $pdo->prepare('UPDATE mata_pelajaran SET nama_mapel = :n, deskripsi = :d WHERE id_mapel = :id')
                    ->execute([':n' => $d['nama_mapel'], ':d' => $desk, ':id' => $id]);
            } else {
                $pdo->prepare('INSERT INTO mata_pelajaran (nama_mapel, deskripsi) VALUES (:n, :d)')
                    ->execute([':n' => $d['nama_mapel'], ':d' => $desk]);
            }
            flash_set('success', 'Mata pelajaran "' . $d['nama_mapel'] . '" berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan.'));
            redirect('mapel.php');
        } catch (PDOException $ex) {
            $errors[] = $ex->getCode() === '23000' ? 'Nama mata pelajaran sudah ada.'
                : (APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.');
        }
    }
}

$judul_halaman = $edit ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran';
$sub_judul     = 'Nama dan deskripsi singkat mata pelajaran.';
$menu_aktif    = 'mapel';
include __DIR__ . '/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert">
        <strong>Periksa kembali isian Anda:</strong>
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="mapel_form.php<?= $edit ? '?id=' . $id : '' ?>" class="form-card" novalidate style="margin-top:24px">
    <?= csrf_field() ?>
    <div class="form-section">
        <h2 class="form-section__title">Data Mata Pelajaran</h2>
        <p class="form-section__desc">Mapel dipilih saat membuat kelas dan tampil di portal siswa.</p>
        <div class="form-grid">
            <div class="field field--full">
                <label for="nama_mapel">Nama Mata Pelajaran <span class="req">*</span></label>
                <input class="input" type="text" id="nama_mapel" name="nama_mapel" value="<?= e($d['nama_mapel']) ?>" maxlength="100" required>
            </div>
            <div class="field field--full">
                <label for="deskripsi">Deskripsi</label>
                <input class="input" type="text" id="deskripsi" name="deskripsi" value="<?= e($d['deskripsi']) ?>" maxlength="255" placeholder="Contoh: Aljabar, geometri, dan trigonometri">
            </div>
        </div>
    </div>
    <div class="form-actions">
        <a href="mapel.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Mapel' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
