<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';

// Sudah login? langsung arahkan sesuai peran.
if (is_logged_in()) {
    redirect_by_role();
}

// Hash pembanding agar waktu respons tetap sama walau username tidak ada.
const HASH_PEMBANDING = '$2y$10$AwvcSawZaR.0wilQykK/kuGiHUGKpj9Dy2bbe16h9ld9rQtTOzfvi';

$error    = '';
$info     = '';
$username = '';

if (isset($_GET['logout']))  { $info = 'Anda telah keluar dari sistem.'; }
if (isset($_GET['registered'])) { $info = 'Registrasi berhasil. Silakan masuk; admin akan memverifikasi pendaftaran kelas Anda.'; }
if (isset($_GET['expired'])) { $info = 'Sesi berakhir karena tidak aktif. Silakan masuk kembali.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (($sisa = login_sisa_kunci()) > 0) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($sisa / 60) . ' menit.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id_user, username, password, role, status
               FROM users WHERE username = :u LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        $cocok = password_verify($password, $user['password'] ?? HASH_PEMBANDING) && $user !== false;

        if (!$cocok) {
            login_catat_gagal();
            $error = 'Username atau password salah.';
        } elseif ($user['status'] !== 'aktif') {
            $error = 'Akun Anda dinonaktifkan. Silakan hubungi admin.';
        } else {
            // Ambil nama & id profil sesuai peran
            $profil = ['nama' => $user['username']];

            if ($user['role'] === 'admin') {
                $profil['nama'] = 'Administrator';
            } elseif ($user['role'] === 'owner') {
                $profil['nama'] = 'Pemilik Bimbel';
            } elseif ($user['role'] === 'siswa') {
                $q = $pdo->prepare('SELECT id_siswa, nama_lengkap FROM siswa WHERE id_user = :id');
                $q->execute([':id' => $user['id_user']]);
                if ($r = $q->fetch()) {
                    $profil['nama']     = $r['nama_lengkap'];
                    $profil['id_siswa'] = (int) $r['id_siswa'];
                }
            } elseif ($user['role'] === 'pengajar') {
                $q = $pdo->prepare('SELECT id_pengajar, nama_lengkap FROM pengajar WHERE id_user = :id');
                $q->execute([':id' => $user['id_user']]);
                if ($r = $q->fetch()) {
                    $profil['nama']        = $r['nama_lengkap'];
                    $profil['id_pengajar'] = (int) $r['id_pengajar'];
                }
            }

            // Perbarui hash bila algoritma default PHP berubah
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password = :p WHERE id_user = :id')
                    ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id_user']]);
            }

            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id_user = :id')
                ->execute([':id' => $user['id_user']]);

            login_user($user, $profil);
            redirect_by_role();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="auth-body">

<main class="auth">

    <!-- Panel kiri: identitas -->
    <aside class="auth__aside">
        <div class="auth__grid" aria-hidden="true"></div>
        <div class="auth__block" aria-hidden="true"></div>

        <div class="brand">
            <span class="brand__mark">B</span>
            <span class="brand__text">Bimbel<small>Tatap Muka</small></span>
        </div>

        <div class="auth__hero">
            <p class="eyebrow">Sistem Informasi Bimbingan Belajar</p>
            <h1>Belajar lebih<br>terarah, tertata,<br>terpantau.</h1>
            <ul class="auth__list">
                <li><span>01</span> Jadwal kelas</li>
                <li><span>02</span> Absensi pertemuan</li>
                <li><span>03</span> Status pembayaran</li>
            </ul>
        </div>

        <p class="auth__copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
    </aside>

    <!-- Panel kanan: form -->
    <section class="auth__main">
        <div class="auth__panel">
            <p class="eyebrow">Selamat datang</p>
            <h2>Masuk ke akun Anda</h2>
            <p class="muted">Gunakan akun yang diberikan oleh admin bimbel.</p>

            <?php if ($error): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php elseif ($info): ?>
                <div class="alert alert--info" role="status"><?= e($info) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" autocomplete="on" novalidate>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="username">Username</label>
                    <input class="input" type="text" id="username" name="username"
                           value="<?= e($username) ?>" autocomplete="username"
                           placeholder="Masukkan username" required autofocus>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <input class="input" type="password" id="password" name="password"
                               autocomplete="current-password" placeholder="Masukkan password" required>
                        <button type="button" class="input-group__btn" id="togglePw" aria-controls="password">Lihat</button>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--block">Masuk</button>
            </form>

            <p class="muted" style="margin-top:1rem">Siswa baru? <a href="register.php"><strong>Daftar akun siswa</strong></a></p>
        </div>
    </section>

</main>

<script>
    (function () {
        var pw  = document.getElementById('password');
        var btn = document.getElementById('togglePw');
        btn.addEventListener('click', function () {
            var tampil = pw.type === 'password';
            pw.type = tampil ? 'text' : 'password';
            btn.textContent = tampil ? 'Sembunyi' : 'Lihat';
        });
    })();
</script>
</body>
</html>
