<?php
/**
 * siswa_form.php — Tambah / Edit siswa (Admin).
 *   siswa_form.php          -> tambah
 *   siswa_form.php?id=5     -> edit
 * Satu siswa = satu akun users (role siswa) + satu baris tabel siswa.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;

$tingkat_opsi = ['SD', 'SMP', 'SMA', 'SMK', 'Umum'];
$status_opsi  = ['menunggu', 'aktif', 'nonaktif', 'lulus'];

// Nilai awal form
$d = [
    'username' => '', 'nis' => '', 'nama_lengkap' => '', 'jenis_kelamin' => 'L',
    'tanggal_lahir' => '', 'alamat' => '', 'no_telp' => '', 'email' => '',
    'sekolah_asal' => '', 'tingkat' => 'SMA', 'nama_wali' => '', 'no_telp_wali' => '',
    'tanggal_daftar' => date('Y-m-d'), 'status' => 'aktif',
];
$id_user = null;

if ($edit) {
    $stmt = $pdo->prepare(
        'SELECT s.*, u.username
           FROM siswa s JOIN users u ON u.id_user = s.id_user
          WHERE s.id_siswa = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Data siswa tidak ditemukan.');
        redirect('siswa.php');
    }
    $id_user = (int) $row['id_user'];
    foreach ($d as $k => $_) {
        $d[$k] = (string) ($row[$k] ?? '');
    }
}

// ---------------------------------------------------------------
// SIMPAN (POST)
// ---------------------------------------------------------------
$errors   = [];
$password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }
    $password = $_POST['password'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        // --- Akun
        if (!preg_match('/^[A-Za-z0-9_.]{4,50}$/', $d['username'])) {
            $errors[] = 'Username 4-50 karakter, hanya huruf, angka, titik, dan garis bawah.';
        }
        if (!$edit && strlen($password) < 6) {
            $errors[] = 'Password wajib diisi, minimal 6 karakter.';
        } elseif ($edit && $password !== '' && strlen($password) < 6) {
            $errors[] = 'Password baru minimal 6 karakter.';
        }

        // --- Profil
        if ($d['nis'] === '' || mb_strlen($d['nis']) > 20) {
            $errors[] = 'NIS wajib diisi (maksimal 20 karakter).';
        }
        if ($d['nama_lengkap'] === '' || mb_strlen($d['nama_lengkap']) > 100) {
            $errors[] = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
        }
        if (!in_array($d['jenis_kelamin'], ['L', 'P'], true)) {
            $errors[] = 'Jenis kelamin tidak valid.';
        }
        if ($d['tanggal_lahir'] !== '' && (!tanggal_valid($d['tanggal_lahir']) || $d['tanggal_lahir'] > date('Y-m-d'))) {
            $errors[] = 'Tanggal lahir tidak valid.';
        }
        if (!in_array($d['tingkat'], $tingkat_opsi, true)) {
            $errors[] = 'Tingkat tidak valid.';
        }
        if (!in_array($d['status'], $status_opsi, true)) {
            $errors[] = 'Status tidak valid.';
        }
        if (!tanggal_valid($d['tanggal_daftar'])) {
            $errors[] = 'Tanggal daftar tidak valid.';
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }
        foreach (['no_telp' => 'No. telepon', 'no_telp_wali' => 'No. telepon wali'] as $f => $label) {
            if ($d[$f] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $d[$f])) {
                $errors[] = $label . ' tidak valid (6-20 digit angka).';
            }
        }

        // --- Keunikan username & NIS
        if (!$errors) {
            $ada = db_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE username = :u AND id_user <> :id',
                [':u' => $d['username'], ':id' => $id_user ?? 0]);
            if ($ada) {
                $errors[] = 'Username sudah dipakai akun lain.';
            }
            $ada = db_scalar($pdo, 'SELECT COUNT(*) FROM siswa WHERE nis = :n AND id_siswa <> :id',
                [':n' => $d['nis'], ':id' => $id]);
            if ($ada) {
                $errors[] = 'NIS sudah dipakai siswa lain.';
            }
        }
    }

    if (!$errors) {
        $nul = fn(string $v) => $v === '' ? null : $v;
        $status_akun = $d['status'] === 'nonaktif' ? 'nonaktif' : 'aktif';

        try {
            $pdo->beginTransaction();

            if ($edit) {
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET username = :u, password = :p, status = :s WHERE id_user = :id')
                        ->execute([':u' => $d['username'], ':p' => password_hash($password, PASSWORD_DEFAULT),
                                   ':s' => $status_akun, ':id' => $id_user]);
                } else {
                    $pdo->prepare('UPDATE users SET username = :u, status = :s WHERE id_user = :id')
                        ->execute([':u' => $d['username'], ':s' => $status_akun, ':id' => $id_user]);
                }
            } else {
                $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (:u, :p, 'siswa', :s)")
                    ->execute([':u' => $d['username'], ':p' => password_hash($password, PASSWORD_DEFAULT), ':s' => $status_akun]);
                $id_user = (int) $pdo->lastInsertId();
            }

            $data = [
                ':nis' => $d['nis'], ':nama' => $d['nama_lengkap'], ':jk' => $d['jenis_kelamin'],
                ':lahir' => $nul($d['tanggal_lahir']), ':alamat' => $nul($d['alamat']),
                ':telp' => $nul($d['no_telp']), ':email' => $nul($d['email']),
                ':sekolah' => $nul($d['sekolah_asal']), ':tingkat' => $d['tingkat'],
                ':wali' => $nul($d['nama_wali']), ':telp_wali' => $nul($d['no_telp_wali']),
                ':daftar' => $d['tanggal_daftar'], ':status' => $d['status'],
            ];

            if ($edit) {
                $data[':id'] = $id;
                $pdo->prepare(
                    'UPDATE siswa SET nis = :nis, nama_lengkap = :nama, jenis_kelamin = :jk,
                            tanggal_lahir = :lahir, alamat = :alamat, no_telp = :telp, email = :email,
                            sekolah_asal = :sekolah, tingkat = :tingkat, nama_wali = :wali,
                            no_telp_wali = :telp_wali, tanggal_daftar = :daftar, status = :status
                      WHERE id_siswa = :id'
                )->execute($data);
            } else {
                $data[':user'] = $id_user;
                $pdo->prepare(
                    'INSERT INTO siswa (id_user, nis, nama_lengkap, jenis_kelamin, tanggal_lahir, alamat, no_telp,
                                        email, sekolah_asal, tingkat, nama_wali, no_telp_wali, tanggal_daftar, status)
                     VALUES (:user, :nis, :nama, :jk, :lahir, :alamat, :telp, :email, :sekolah, :tingkat,
                             :wali, :telp_wali, :daftar, :status)'
                )->execute($data);
            }

            $pdo->commit();
            flash_set('success', 'Siswa "' . $d['nama_lengkap'] . '" berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan.'));
            redirect('siswa.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getCode() === '23000'
                ? 'Username atau NIS sudah dipakai.'
                : (APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.');
        }
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = $edit ? 'Edit Siswa' : 'Tambah Siswa';
$sub_judul     = $edit ? 'Perbarui akun dan profil siswa.' : 'Buat akun login dan profil siswa baru.';
$menu_aktif    = 'siswa';
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

<form method="post" action="siswa_form.php<?= $edit ? '?id=' . $id : '' ?>" class="form-card" novalidate style="margin-top:24px">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title">Akun Login</h2>
        <p class="form-section__desc">Dipakai siswa untuk masuk ke portal.</p>
        <div class="form-grid">
            <div class="field">
                <label for="username">Username <span class="req">*</span></label>
                <input class="input" type="text" id="username" name="username" value="<?= e($d['username']) ?>" maxlength="50" required>
            </div>
            <div class="field">
                <label for="password">Password <?= $edit ? '' : '<span class="req">*</span>' ?></label>
                <input class="input" type="password" id="password" name="password" autocomplete="new-password"
                       placeholder="<?= $edit ? 'Kosongkan jika tidak diubah' : 'Minimal 6 karakter' ?>">
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2 class="form-section__title">Profil Siswa</h2>
        <p class="form-section__desc">Data identitas dan akademik.</p>
        <div class="form-grid">
            <div class="field">
                <label for="nis">NIS <span class="req">*</span></label>
                <input class="input" type="text" id="nis" name="nis" value="<?= e($d['nis']) ?>" maxlength="20" required>
            </div>
            <div class="field">
                <label for="nama_lengkap">Nama Lengkap <span class="req">*</span></label>
                <input class="input" type="text" id="nama_lengkap" name="nama_lengkap" value="<?= e($d['nama_lengkap']) ?>" maxlength="100" required>
            </div>
            <div class="field">
                <label for="jenis_kelamin">Jenis Kelamin</label>
                <select class="input" id="jenis_kelamin" name="jenis_kelamin">
                    <option value="L" <?= $d['jenis_kelamin'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                    <option value="P" <?= $d['jenis_kelamin'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                </select>
            </div>
            <div class="field">
                <label for="tanggal_lahir">Tanggal Lahir</label>
                <input class="input" type="date" id="tanggal_lahir" name="tanggal_lahir" value="<?= e($d['tanggal_lahir']) ?>" max="<?= date('Y-m-d') ?>">
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
                <label for="sekolah_asal">Sekolah Asal</label>
                <input class="input" type="text" id="sekolah_asal" name="sekolah_asal" value="<?= e($d['sekolah_asal']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label for="no_telp">No. Telepon</label>
                <input class="input" type="text" id="no_telp" name="no_telp" value="<?= e($d['no_telp']) ?>" maxlength="20">
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input class="input" type="email" id="email" name="email" value="<?= e($d['email']) ?>" maxlength="100">
            </div>
            <div class="field field--full">
                <label for="alamat">Alamat</label>
                <textarea class="input" id="alamat" name="alamat"><?= e($d['alamat']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2 class="form-section__title">Wali &amp; Status</h2>
        <p class="form-section__desc">Kontak wali dan status keanggotaan. Status "Nonaktif" otomatis menonaktifkan akun login.</p>
        <div class="form-grid">
            <div class="field">
                <label for="nama_wali">Nama Wali</label>
                <input class="input" type="text" id="nama_wali" name="nama_wali" value="<?= e($d['nama_wali']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label for="no_telp_wali">No. Telepon Wali</label>
                <input class="input" type="text" id="no_telp_wali" name="no_telp_wali" value="<?= e($d['no_telp_wali']) ?>" maxlength="20">
            </div>
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
        </div>
    </div>

    <div class="form-actions">
        <a href="siswa.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Siswa' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
