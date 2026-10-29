<?php
/**
 * profil.php — Akun Saya (semua peran): lihat data akun & ganti password.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_login();

$user   = current_user();
$errors = [];

// Data profil sesuai peran
$profil = [];
if ($user['role'] === 'siswa') {
    $st = $pdo->prepare('SELECT nis, email, no_telp, tingkat, sekolah_asal FROM siswa WHERE id_siswa = :id');
    $st->execute([':id' => (int) $user['id_siswa']]);
    $r = $st->fetch() ?: [];
    $profil = ['NIS' => $r['nis'] ?? '—', 'Tingkat' => $r['tingkat'] ?? '—', 'Sekolah Asal' => $r['sekolah_asal'] ?? '—',
               'Email' => $r['email'] ?? '—', 'No. Telepon' => $r['no_telp'] ?? '—'];
} elseif ($user['role'] === 'pengajar') {
    $st = $pdo->prepare('SELECT nip, email, no_telp, keahlian FROM pengajar WHERE id_pengajar = :id');
    $st->execute([':id' => (int) $user['id_pengajar']]);
    $r = $st->fetch() ?: [];
    $profil = ['NIP' => $r['nip'] ?? '—', 'Keahlian' => $r['keahlian'] ?? '—',
               'Email' => $r['email'] ?? '—', 'No. Telepon' => $r['no_telp'] ?? '—'];
}

// ---------------------------------------------------------------
// GANTI PASSWORD (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lama    = $_POST['password_lama'] ?? '';
    $baru    = $_POST['password_baru'] ?? '';
    $konfirm = $_POST['password_konfirmasi'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $hash = (string) db_scalar($pdo, 'SELECT password FROM users WHERE id_user = :id', [':id' => (int) $user['id_user']]);

        if (!password_verify($lama, $hash)) {
            $errors[] = 'Password lama salah.';
        }
        if (strlen($baru) < 6) {
            $errors[] = 'Password baru minimal 6 karakter.';
        }
        if ($baru !== $konfirm) {
            $errors[] = 'Konfirmasi password tidak cocok.';
        }
        if (!$errors && $baru === $lama) {
            $errors[] = 'Password baru harus berbeda dari password lama.';
        }
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET password = :p WHERE id_user = :id')
            ->execute([':p' => password_hash($baru, PASSWORD_DEFAULT), ':id' => (int) $user['id_user']]);
        session_regenerate_id(true);
        flash_set('success', 'Password berhasil diubah.');
        redirect('profil.php');
    }
}

$label_peran   = ['admin' => 'Admin', 'owner' => 'Owner', 'pengajar' => 'Pengajar', 'siswa' => 'Siswa'][$user['role']] ?? '';
$judul_halaman = 'Akun Saya';
$sub_judul     = 'Informasi akun dan pengaturan keamanan.';
$menu_aktif    = 'profil';
include __DIR__ . '/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert">
        <strong>Password belum bisa diubah:</strong>
        <ul>
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="grid-2 grid-2--even" style="margin-top:24px">
    <div class="panel">
        <div class="panel__head"><h2 class="panel__title">Informasi Akun</h2></div>
        <div class="panel__body">
            <dl class="dl">
                <dt>Nama</dt><dd><?= e($user['nama']) ?></dd>
                <dt>Username</dt><dd><?= e($user['username']) ?></dd>
                <dt>Peran</dt><dd><?= e($label_peran) ?></dd>
                <?php foreach ($profil as $k => $v): ?>
                    <dt><?= e($k) ?></dt><dd><?= e($v === '' || $v === null ? '—' : $v) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if (!in_array($user['role'], ['admin', 'owner'], true)): ?>
                <p class="field__hint" style="margin-top:16px">Untuk mengubah data profil, hubungi admin bimbel.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2 class="panel__title">Ganti Password</h2></div>
        <div class="panel__body">
            <form method="post" action="profil.php" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="password_lama">Password Lama</label>
                    <input class="input" type="password" id="password_lama" name="password_lama" autocomplete="current-password" required>
                </div>
                <div class="field">
                    <label for="password_baru">Password Baru</label>
                    <input class="input" type="password" id="password_baru" name="password_baru" autocomplete="new-password" placeholder="Minimal 6 karakter" required>
                </div>
                <div class="field">
                    <label for="password_konfirmasi">Konfirmasi Password Baru</label>
                    <input class="input" type="password" id="password_konfirmasi" name="password_konfirmasi" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn--primary">Simpan Password</button>
            </form>
        </div>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
