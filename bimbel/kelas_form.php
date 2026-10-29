<?php
/**
 * kelas_form.php — Tambah / Edit kelas (Admin).
 *   kelas_form.php        -> tambah
 *   kelas_form.php?id=3   -> edit
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;

$tingkat_opsi = ['SD', 'SMP', 'SMA', 'SMK', 'Umum'];
$status_opsi  = ['buka', 'tutup'];

$mapel    = $pdo->query('SELECT id_mapel, nama_mapel FROM mata_pelajaran ORDER BY nama_mapel')->fetchAll();
$pengajar = $pdo->query("SELECT id_pengajar, nama_lengkap FROM pengajar WHERE status = 'aktif' ORDER BY nama_lengkap")->fetchAll();

$d = [
    'nama_kelas' => '', 'id_mapel' => '', 'id_pengajar' => '', 'tingkat' => 'SMA',
    'kuota' => '20', 'biaya_bulanan' => '', 'tahun_ajaran' => tahun_ajaran_sekarang(), 'status' => 'buka',
];

if ($edit) {
    $stmt = $pdo->prepare('SELECT * FROM kelas WHERE id_kelas = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Kelas tidak ditemukan.');
        redirect('jadwal.php');
    }
    foreach ($d as $k => $_) {
        $d[$k] = (string) ($row[$k] ?? '');
    }
    $d['biaya_bulanan'] = (string) (int) $row['biaya_bulanan'];
}

// ---------------------------------------------------------------
// SIMPAN (POST)
// ---------------------------------------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        if ($d['nama_kelas'] === '' || mb_strlen($d['nama_kelas']) > 100) {
            $errors[] = 'Nama kelas wajib diisi (maksimal 100 karakter).';
        }
        if (!in_array((int) $d['id_mapel'], array_column($mapel, 'id_mapel'), true)) {
            $errors[] = 'Pilih mata pelajaran.';
        }
        if ($d['id_pengajar'] !== '' && !in_array((int) $d['id_pengajar'], array_column($pengajar, 'id_pengajar'), true)) {
            $errors[] = 'Pengajar tidak valid.';
        }
        if (!in_array($d['tingkat'], $tingkat_opsi, true)) {
            $errors[] = 'Tingkat tidak valid.';
        }
        if (!ctype_digit($d['kuota']) || (int) $d['kuota'] < 1 || (int) $d['kuota'] > 500) {
            $errors[] = 'Kuota harus angka antara 1 dan 500.';
        }
        if (!ctype_digit($d['biaya_bulanan']) || (float) $d['biaya_bulanan'] > 9999999999) {
            $errors[] = 'Biaya bulanan harus berupa angka bulat (tanpa titik atau koma).';
        }
        if (!preg_match('/^\d{4}\/\d{4}$/', $d['tahun_ajaran'])) {
            $errors[] = 'Tahun ajaran harus berformat 2026/2027.';
        }
        if (!in_array($d['status'], $status_opsi, true)) {
            $errors[] = 'Status tidak valid.';
        }

        // Kuota tidak boleh lebih kecil dari jumlah siswa yang sudah terdaftar
        if (!$errors && $edit) {
            $terisi = (int) db_scalar($pdo,
                "SELECT COUNT(*) FROM pendaftaran WHERE id_kelas = :id AND status IN ('pending', 'aktif')",
                [':id' => $id]);
            if ((int) $d['kuota'] < $terisi) {
                $errors[] = "Kuota tidak boleh kurang dari jumlah siswa terdaftar ($terisi).";
            }
        }
    }

    if (!$errors) {
        $data = [
            ':nama'     => $d['nama_kelas'],
            ':mapel'    => (int) $d['id_mapel'],
            ':pengajar' => $d['id_pengajar'] === '' ? null : (int) $d['id_pengajar'],
            ':tingkat'  => $d['tingkat'],
            ':kuota'    => (int) $d['kuota'],
            ':biaya'    => $d['biaya_bulanan'],
            ':tahun'    => $d['tahun_ajaran'],
            ':status'   => $d['status'],
        ];

        try {
            if ($edit) {
                $data[':id'] = $id;
                $pdo->prepare(
                    'UPDATE kelas SET nama_kelas = :nama, id_mapel = :mapel, id_pengajar = :pengajar,
                            tingkat = :tingkat, kuota = :kuota, biaya_bulanan = :biaya,
                            tahun_ajaran = :tahun, status = :status
                      WHERE id_kelas = :id'
                )->execute($data);
            } else {
                $pdo->prepare(
                    'INSERT INTO kelas (nama_kelas, id_mapel, id_pengajar, tingkat, kuota, biaya_bulanan, tahun_ajaran, status)
                     VALUES (:nama, :mapel, :pengajar, :tingkat, :kuota, :biaya, :tahun, :status)'
                )->execute($data);
            }
            flash_set('success', 'Kelas "' . $d['nama_kelas'] . '" berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan. Silakan tambahkan jadwalnya.'));
            redirect('jadwal.php');
        } catch (PDOException $ex) {
            $errors[] = APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.';
        }
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = $edit ? 'Edit Kelas' : 'Tambah Kelas';
$sub_judul     = $edit ? 'Perbarui data kelas.' : 'Buat kelas baru, lalu atur jadwalnya.';
$menu_aktif    = 'jadwal';
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

<form method="post" action="kelas_form.php<?= $edit ? '?id=' . $id : '' ?>" class="form-card" novalidate style="margin-top:24px">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title">Informasi Kelas</h2>
        <p class="form-section__desc">Mata pelajaran, pengajar, dan tingkat peserta.</p>
        <div class="form-grid">
            <div class="field field--full">
                <label for="nama_kelas">Nama Kelas <span class="req">*</span></label>
                <input class="input" type="text" id="nama_kelas" name="nama_kelas" value="<?= e($d['nama_kelas']) ?>"
                       maxlength="100" placeholder="Contoh: Matematika SMA Reguler" required>
            </div>
            <div class="field">
                <label for="id_mapel">Mata Pelajaran <span class="req">*</span></label>
                <select class="input" id="id_mapel" name="id_mapel" required>
                    <option value="">Pilih mata pelajaran</option>
                    <?php foreach ($mapel as $m): ?>
                        <option value="<?= (int) $m['id_mapel'] ?>" <?= (string) $m['id_mapel'] === $d['id_mapel'] ? 'selected' : '' ?>><?= e($m['nama_mapel']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="id_pengajar">Pengajar</label>
                <select class="input" id="id_pengajar" name="id_pengajar">
                    <option value="">Belum ditentukan</option>
                    <?php foreach ($pengajar as $p): ?>
                        <option value="<?= (int) $p['id_pengajar'] ?>" <?= (string) $p['id_pengajar'] === $d['id_pengajar'] ? 'selected' : '' ?>><?= e($p['nama_lengkap']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$pengajar): ?>
                    <p class="field__hint">Belum ada pengajar aktif. Kelas tetap bisa dibuat tanpa pengajar.</p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="tingkat">Tingkat</label>
                <select class="input" id="tingkat" name="tingkat">
                    <?php foreach ($tingkat_opsi as $t): ?>
                        <option value="<?= e($t) ?>" <?= $d['tingkat'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tahun_ajaran">Tahun Ajaran</label>
                <input class="input" type="text" id="tahun_ajaran" name="tahun_ajaran" value="<?= e($d['tahun_ajaran']) ?>" maxlength="9" placeholder="2026/2027">
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2 class="form-section__title">Kuota &amp; Biaya</h2>
        <p class="form-section__desc">Jumlah peserta maksimal dan biaya per bulan.</p>
        <div class="form-grid">
            <div class="field">
                <label for="kuota">Kuota Siswa</label>
                <input class="input" type="number" id="kuota" name="kuota" value="<?= e($d['kuota']) ?>" min="1" max="500">
            </div>
            <div class="field">
                <label for="biaya_bulanan">Biaya Bulanan (Rp)</label>
                <input class="input" type="number" id="biaya_bulanan" name="biaya_bulanan" value="<?= e($d['biaya_bulanan']) ?>" min="0" step="1000" placeholder="150000">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select class="input" id="status" name="status">
                    <option value="buka" <?= $d['status'] === 'buka' ? 'selected' : '' ?>>Buka</option>
                    <option value="tutup" <?= $d['status'] === 'tutup' ? 'selected' : '' ?>>Tutup</option>
                </select>
                <p class="field__hint">Kelas tutup tidak tampil di jadwal mingguan.</p>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="jadwal.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Kelas' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
