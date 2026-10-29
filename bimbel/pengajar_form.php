<?php
/**
 * pengajar_form.php — Tambah / Edit pengajar (Admin).
 *   pengajar_form.php        -> tambah
 *   pengajar_form.php?id=2   -> edit
 * Satu pengajar = satu akun users (role pengajar) + satu baris tabel pengajar.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;

$status_opsi = ['aktif', 'nonaktif'];

$d = [
    'username' => '', 'nip' => '', 'nama_lengkap' => '', 'jenis_kelamin' => 'L',
    'tanggal_lahir' => '', 'alamat' => '', 'no_telp' => '', 'email' => '',
    'pendidikan_terakhir' => '', 'keahlian' => '',
    'tanggal_bergabung' => date('Y-m-d'), 'status' => 'aktif',
];
$id_user = null;

if ($edit) {
    $stmt = $pdo->prepare(
        'SELECT p.*, u.username
           FROM pengajar p JOIN users u ON u.id_user = p.id_user
          WHERE p.id_pengajar = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Data pengajar tidak ditemukan.');
        redirect('pengajar.php');
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
        if (!preg_match('/^[A-Za-z0-9_.]{4,50}$/', $d['username'])) {
            $errors[] = 'Username 4-50 karakter, hanya huruf, angka, titik, dan garis bawah.';
        }
        if (!$edit && strlen($password) < 6) {
            $errors[] = 'Password wajib diisi, minimal 6 karakter.';
        } elseif ($edit && $password !== '' && strlen($password) < 6) {
            $errors[] = 'Password baru minimal 6 karakter.';
        }

        if ($d['nip'] === '' || mb_strlen($d['nip']) > 25) {
            $errors[] = 'NIP wajib diisi (maksimal 25 karakter).';
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
        if (!tanggal_valid($d['tanggal_bergabung'])) {
            $errors[] = 'Tanggal bergabung tidak valid.';
        }
        if (!in_array($d['status'], $status_opsi, true)) {
            $errors[] = 'Status tidak valid.';
        }
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }
        if ($d['no_telp'] !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $d['no_telp'])) {
            $errors[] = 'No. telepon tidak valid (6-20 digit angka).';
        }
        if (mb_strlen($d['pendidikan_terakhir']) > 50) {
            $errors[] = 'Pendidikan terakhir maksimal 50 karakter.';
        }
        if (mb_strlen($d['keahlian']) > 150) {
            $errors[] = 'Keahlian maksimal 150 karakter.';
        }

        if (!$errors) {
            $ada = db_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE username = :u AND id_user <> :id',
                [':u' => $d['username'], ':id' => $id_user ?? 0]);
            if ($ada) {
                $errors[] = 'Username sudah dipakai akun lain.';
            }
            $ada = db_scalar($pdo, 'SELECT COUNT(*) FROM pengajar WHERE nip = :n AND id_pengajar <> :id',
                [':n' => $d['nip'], ':id' => $id]);
            if ($ada) {
                $errors[] = 'NIP sudah dipakai pengajar lain.';
            }
        }
    }

    if (!$errors) {
        $nul = fn(string $v) => $v === '' ? null : $v;

        try {
            $pdo->beginTransaction();

            if ($edit) {
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET username = :u, password = :p, status = :s WHERE id_user = :id')
                        ->execute([':u' => $d['username'], ':p' => password_hash($password, PASSWORD_DEFAULT),
                                   ':s' => $d['status'], ':id' => $id_user]);
                } else {
                    $pdo->prepare('UPDATE users SET username = :u, status = :s WHERE id_user = :id')
                        ->execute([':u' => $d['username'], ':s' => $d['status'], ':id' => $id_user]);
                }
            } else {
                $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (:u, :p, 'pengajar', :s)")
                    ->execute([':u' => $d['username'], ':p' => password_hash($password, PASSWORD_DEFAULT), ':s' => $d['status']]);
                $id_user = (int) $pdo->lastInsertId();
            }

            $data = [
                ':nip' => $d['nip'], ':nama' => $d['nama_lengkap'], ':jk' => $d['jenis_kelamin'],
                ':lahir' => $nul($d['tanggal_lahir']), ':alamat' => $nul($d['alamat']),
                ':telp' => $nul($d['no_telp']), ':email' => $nul($d['email']),
                ':pendidikan' => $nul($d['pendidikan_terakhir']), ':keahlian' => $nul($d['keahlian']),
                ':gabung' => $d['tanggal_bergabung'], ':status' => $d['status'],
            ];

            if ($edit) {
                $data[':id'] = $id;
                $pdo->prepare(
                    'UPDATE pengajar SET nip = :nip, nama_lengkap = :nama, jenis_kelamin = :jk,
                            tanggal_lahir = :lahir, alamat = :alamat, no_telp = :telp, email = :email,
                            pendidikan_terakhir = :pendidikan, keahlian = :keahlian,
                            tanggal_bergabung = :gabung, status = :status
                      WHERE id_pengajar = :id'
                )->execute($data);
            } else {
                $data[':user'] = $id_user;
                $pdo->prepare(
                    'INSERT INTO pengajar (id_user, nip, nama_lengkap, jenis_kelamin, tanggal_lahir, alamat, no_telp,
                                           email, pendidikan_terakhir, keahlian, tanggal_bergabung, status)
                     VALUES (:user, :nip, :nama, :jk, :lahir, :alamat, :telp, :email, :pendidikan, :keahlian, :gabung, :status)'
                )->execute($data);
            }

            $pdo->commit();
            flash_set('success', 'Pengajar "' . $d['nama_lengkap'] . '" berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan.'));
            redirect('pengajar.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getCode() === '23000'
                ? 'Username atau NIP sudah dipakai.'
                : (APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.');
        }
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = $edit ? 'Edit Pengajar' : 'Tambah Pengajar';
$sub_judul     = $edit ? 'Perbarui akun dan profil pengajar.' : 'Buat akun login dan profil pengajar baru.';
$menu_aktif    = 'pengajar';
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

<form method="post" action="pengajar_form.php<?= $edit ? '?id=' . $id : '' ?>" class="form-card" novalidate style="margin-top:24px">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title">Akun Login</h2>
        <p class="form-section__desc">Dipakai pengajar untuk masuk ke portal.</p>
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
        <h2 class="form-section__title">Profil Pengajar</h2>
        <p class="form-section__desc">Data identitas dan latar belakang mengajar.</p>
        <div class="form-grid">
            <div class="field">
                <label for="nip">NIP <span class="req">*</span></label>
                <input class="input" type="text" id="nip" name="nip" value="<?= e($d['nip']) ?>" maxlength="25" required>
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
                <label for="pendidikan_terakhir">Pendidikan Terakhir</label>
                <input class="input" type="text" id="pendidikan_terakhir" name="pendidikan_terakhir" value="<?= e($d['pendidikan_terakhir']) ?>" maxlength="50" placeholder="Contoh: S1 Pendidikan Matematika">
            </div>
            <div class="field">
                <label for="keahlian">Keahlian</label>
                <input class="input" type="text" id="keahlian" name="keahlian" value="<?= e($d['keahlian']) ?>" maxlength="150" placeholder="Contoh: Matematika, Fisika">
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
        <h2 class="form-section__title">Status</h2>
        <p class="form-section__desc">Status "Nonaktif" otomatis menonaktifkan akun login.</p>
        <div class="form-grid">
            <div class="field">
                <label for="tanggal_bergabung">Tanggal Bergabung</label>
                <input class="input" type="date" id="tanggal_bergabung" name="tanggal_bergabung" value="<?= e($d['tanggal_bergabung']) ?>">
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
        <a href="pengajar.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Pengajar' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
