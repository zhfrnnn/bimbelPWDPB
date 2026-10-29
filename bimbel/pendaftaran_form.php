<?php
/**
 * pendaftaran_form.php — Tambah / Edit pendaftaran (Admin).
 *   pendaftaran_form.php          -> tambah
 *   pendaftaran_form.php?id=4     -> edit (siswa & kelas tidak bisa diganti)
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;

$status_opsi = ['pending', 'aktif', 'selesai', 'batal'];

$d = [
    'id_siswa' => '', 'id_kelas' => '', 'tanggal_daftar' => date('Y-m-d'),
    'status' => 'aktif', 'catatan' => '',
];
$info = null;   // siswa & kelas saat mode edit

if ($edit) {
    $stmt = $pdo->prepare(
        'SELECT pd.*, s.nama_lengkap, s.nis, k.nama_kelas
           FROM pendaftaran pd
           JOIN siswa s ON s.id_siswa = pd.id_siswa
           JOIN kelas k ON k.id_kelas = pd.id_kelas
          WHERE pd.id_pendaftaran = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Data pendaftaran tidak ditemukan.');
        redirect('pendaftaran.php');
    }
    $info = $row;
    foreach ($d as $k => $_) {
        $d[$k] = (string) ($row[$k] ?? '');
    }
} else {
    $siswa_list = $pdo->query("SELECT id_siswa, nis, nama_lengkap FROM siswa WHERE status = 'aktif' ORDER BY nama_lengkap")->fetchAll();
    $kelas_list = $pdo->query(
        "SELECT k.id_kelas, k.nama_kelas, k.kuota,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status IN ('pending','aktif')) AS terisi
           FROM kelas k WHERE k.status = 'buka' ORDER BY k.nama_kelas"
    )->fetchAll();
}

// ---------------------------------------------------------------
// SIMPAN (POST)
// ---------------------------------------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        if ($edit && in_array($k, ['id_siswa', 'id_kelas'], true)) {
            continue;
        }
        $d[$k] = trim($_POST[$k] ?? '');
    }

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        if (!in_array($d['status'], $status_opsi, true)) {
            $errors[] = 'Status tidak valid.';
        }
        if (!tanggal_valid($d['tanggal_daftar'])) {
            $errors[] = 'Tanggal daftar tidak valid.';
        }
        if (mb_strlen($d['catatan']) > 255) {
            $errors[] = 'Catatan maksimal 255 karakter.';
        }

        if (!$edit) {
            if (!in_array((int) $d['id_siswa'], array_column($siswa_list, 'id_siswa'), true)) {
                $errors[] = 'Pilih siswa.';
            }
            if (!in_array((int) $d['id_kelas'], array_column($kelas_list, 'id_kelas'), true)) {
                $errors[] = 'Pilih kelas yang berstatus buka.';
            }
            if (!$errors && db_scalar($pdo, 'SELECT COUNT(*) FROM pendaftaran WHERE id_siswa = :s AND id_kelas = :k',
                    [':s' => (int) $d['id_siswa'], ':k' => (int) $d['id_kelas']])) {
                $errors[] = 'Siswa ini sudah pernah terdaftar di kelas tersebut. Ubah status lewat daftar pendaftaran.';
            }
        }

        // Kuota: hanya dicek bila pendaftaran akan menempati kursi
        $id_kelas = $edit ? (int) $info['id_kelas'] : (int) $d['id_kelas'];
        $butuh_kursi = in_array($d['status'], ['pending', 'aktif'], true)
            && (!$edit || in_array($info['status'], ['selesai', 'batal'], true));
        if (!$errors && $butuh_kursi && kuota_tersisa($pdo, $id_kelas, $id) < 1) {
            $errors[] = 'Kuota kelas sudah penuh.';
        }
    }

    if (!$errors) {
        try {
            $catatan = $d['catatan'] === '' ? null : $d['catatan'];
            if ($edit) {
                $pdo->prepare('UPDATE pendaftaran SET tanggal_daftar = :t, status = :s, catatan = :c WHERE id_pendaftaran = :id')
                    ->execute([':t' => $d['tanggal_daftar'], ':s' => $d['status'], ':c' => $catatan, ':id' => $id]);
                $id_pd = $id;
            } else {
                $pdo->prepare('INSERT INTO pendaftaran (id_siswa, id_kelas, tanggal_daftar, status, catatan) VALUES (:s, :k, :t, :st, :c)')
                    ->execute([':s' => (int) $d['id_siswa'], ':k' => (int) $d['id_kelas'], ':t' => $d['tanggal_daftar'],
                               ':st' => $d['status'], ':c' => $catatan]);
                $id_pd = (int) $pdo->lastInsertId();
            }

            $pesan = 'Pendaftaran berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan.');
            if ($d['status'] === 'aktif' && buat_tagihan($pdo, $id_pd, (int) date('n'), (int) date('Y'))) {
                $pesan .= ' Tagihan bulan ' . bulan_indo((int) date('n')) . ' dibuat otomatis.';
            }
            flash_set('success', $pesan);
            redirect('pendaftaran.php');
        } catch (PDOException $ex) {
            $errors[] = APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.';
        }
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = $edit ? 'Edit Pendaftaran' : 'Tambah Pendaftaran';
$sub_judul     = $edit ? 'Perbarui status dan catatan pendaftaran.' : 'Daftarkan siswa ke sebuah kelas.';
$menu_aktif    = 'pendaftaran';
include __DIR__ . '/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert">
        <strong>Periksa kembali isian Anda:</strong>
        <ul>
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($edit): ?>
    <div class="info-strip" style="margin-top:24px">
        <div><span>Siswa</span> <strong><?= e($info['nama_lengkap']) ?></strong> (<?= e($info['nis']) ?>)</div>
        <div><span>Kelas</span> <strong><?= e($info['nama_kelas']) ?></strong></div>
    </div>
<?php endif; ?>

<form method="post" action="pendaftaran_form.php<?= $edit ? '?id=' . $id : '' ?>" class="form-card" novalidate style="margin-top:<?= $edit ? '0' : '24px' ?>">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title">Data Pendaftaran</h2>
        <p class="form-section__desc">Status "Aktif" langsung membuat tagihan SPP bulan ini.</p>
        <div class="form-grid">
            <?php if (!$edit): ?>
                <div class="field">
                    <label for="id_siswa">Siswa <span class="req">*</span></label>
                    <select class="input" id="id_siswa" name="id_siswa" required>
                        <option value="">— Pilih siswa —</option>
                        <?php foreach ($siswa_list as $s): ?>
                            <option value="<?= (int) $s['id_siswa'] ?>" <?= (string) $s['id_siswa'] === $d['id_siswa'] ? 'selected' : '' ?>>
                                <?= e($s['nama_lengkap']) ?> (<?= e($s['nis']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="id_kelas">Kelas <span class="req">*</span></label>
                    <select class="input" id="id_kelas" name="id_kelas" required>
                        <option value="">— Pilih kelas —</option>
                        <?php foreach ($kelas_list as $k): ?>
                            <option value="<?= (int) $k['id_kelas'] ?>" <?= (string) $k['id_kelas'] === $d['id_kelas'] ? 'selected' : '' ?>>
                                <?= e($k['nama_kelas']) ?> (<?= (int) $k['terisi'] ?>/<?= (int) $k['kuota'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="field">
                <label for="tanggal_daftar">Tanggal Daftar</label>
                <input class="input" type="date" id="tanggal_daftar" name="tanggal_daftar" value="<?= e($d['tanggal_daftar']) ?>">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select class="input" id="status" name="status">
                    <?php foreach ($status_opsi as $s): ?>
                        <option value="<?= e($s) ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--full">
                <label for="catatan">Catatan</label>
                <input class="input" type="text" id="catatan" name="catatan" value="<?= e($d['catatan']) ?>" maxlength="255">
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="pendaftaran.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Pendaftaran' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
