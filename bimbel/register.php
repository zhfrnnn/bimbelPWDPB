<?php
/**
 * register.php — Registrasi akun siswa (dilakukan siswa sendiri).
 * Setelah mendaftar, akun bisa login tetapi berstatus "menunggu" sampai admin
 * memverifikasi pendaftaran kelas yang dipilih.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

if (is_logged_in()) {
    redirect_by_role();
}

const REG_MAKS_PER_JAM = 5;   // batas registrasi per sesi browser per jam (anti-spam sederhana)

$d = array_fill_keys(field_registrasi_siswa(), '');
$d['jenis_kelamin'] = 'L';
$d['tingkat']       = 'SMA';
$kelas_dipilih = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $password  = (string) ($_POST['password'] ?? '');
    $konfirmasi = (string) ($_POST['password_konfirmasi'] ?? '');
    $kelas_dipilih = array_map('intval', (array) ($_POST['kelas'] ?? []));

    // Bersihkan catatan registrasi lama (> 1 jam)
    $_SESSION['reg_waktu'] = array_values(array_filter($_SESSION['reg_waktu'] ?? [], fn($t) => $t > time() - 3600));

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // honeypot terisi -> kemungkinan bot; tampilkan seolah gagal biasa
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (count($_SESSION['reg_waktu']) >= REG_MAKS_PER_JAM) {
        $errors[] = 'Terlalu banyak registrasi dari perangkat ini. Coba lagi nanti.';
    } elseif ($password !== $konfirmasi) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    } else {
        $hasil = registrasi_siswa($pdo, $d, $password, $kelas_dipilih);
        if ($hasil['errors']) {
            $errors = $hasil['errors'];
        } else {
            $_SESSION['reg_waktu'][] = time();
            foreach ($hasil['kelas_ditolak'] as $m) {
                flash_set('info', $m);
            }
            redirect(HALAMAN_LOGIN . '?registered=1');
        }
    }
}

$kelas_list = kelas_terbuka($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Siswa — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="auth-body">
<main class="auth">

    <aside class="auth__aside">
        <div class="auth__grid" aria-hidden="true"></div>
        <div class="auth__block" aria-hidden="true"></div>

        <div class="brand">
            <span class="brand__mark">B</span>
            <span class="brand__text">Bimbel<small>Tatap Muka</small></span>
        </div>

        <div class="auth__hero">
            <p class="eyebrow">Registrasi Siswa</p>
            <h1>Daftar,<br>pilih kelas,<br>mulai belajar.</h1>
            <ul class="auth__list">
                <li><span>01</span> Isi data diri</li>
                <li><span>02</span> Pilih kelas bimbel</li>
                <li><span>03</span> Admin memverifikasi</li>
            </ul>
        </div>

        <p class="auth__copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
    </aside>

    <section class="auth__main auth__main--top">
        <div class="auth__panel auth__panel--wide">
            <p class="eyebrow">Siswa baru</p>
            <h2>Buat akun siswa</h2>
            <p class="muted">Setelah mendaftar, admin akan memverifikasi pendaftaran Anda. Anda tetap bisa masuk untuk melihat statusnya.</p>

            <?php if ($errors): ?>
                <div class="alert alert--error" role="alert">
                    <strong>Periksa kembali isian Anda:</strong>
                    <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php" novalidate>
                <?= csrf_field() ?>
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <h3 class="reg-title">Akun</h3>
                <div class="form-grid">
                    <div class="field">
                        <label for="username">Username <span class="req">*</span></label>
                        <input class="input" type="text" id="username" name="username" value="<?= e($d['username']) ?>" maxlength="50" autocomplete="username" required>
                    </div>
                    <div class="field"></div>
                    <div class="field">
                        <label for="password">Password <span class="req">*</span></label>
                        <input class="input" type="password" id="password" name="password" autocomplete="new-password" placeholder="Minimal 6 karakter" required>
                    </div>
                    <div class="field">
                        <label for="password_konfirmasi">Ulangi Password <span class="req">*</span></label>
                        <input class="input" type="password" id="password_konfirmasi" name="password_konfirmasi" autocomplete="new-password" required>
                    </div>
                </div>

                <h3 class="reg-title">Data Diri</h3>
                <div class="form-grid">
                    <div class="field field--full">
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
                        <label for="tingkat">Jenjang</label>
                        <select class="input" id="tingkat" name="tingkat">
                            <?php foreach (daftar_tingkat() as $t): ?>
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
                    <div class="field">
                        <label for="nama_wali">Nama Wali</label>
                        <input class="input" type="text" id="nama_wali" name="nama_wali" value="<?= e($d['nama_wali']) ?>" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="no_telp_wali">No. Telepon Wali</label>
                        <input class="input" type="text" id="no_telp_wali" name="no_telp_wali" value="<?= e($d['no_telp_wali']) ?>" maxlength="20">
                    </div>
                    <div class="field field--full">
                        <label for="alamat">Alamat</label>
                        <textarea class="input" id="alamat" name="alamat"><?= e($d['alamat']) ?></textarea>
                    </div>
                </div>

                <h3 class="reg-title">Pilih Kelas <span class="muted" style="font-weight:400;font-size:13px">(opsional, bisa memilih lebih dari satu)</span></h3>
                <?php if ($kelas_list): ?>
                    <div class="kelas-pilih">
                        <?php foreach ($kelas_list as $k): $penuh = (int) $k['sisa_kuota'] < 1; ?>
                            <label class="kelas-pilih__item<?= $penuh ? ' is-disabled' : '' ?>">
                                <input type="checkbox" name="kelas[]" value="<?= (int) $k['id_kelas'] ?>"
                                       <?= $penuh ? 'disabled' : '' ?> <?= in_array((int) $k['id_kelas'], $kelas_dipilih, true) ? 'checked' : '' ?>>
                                <span>
                                    <strong><?= e($k['nama_kelas']) ?></strong>
                                    <small><?= e($k['nama_mapel']) ?> &middot; <?= e($k['pengajar'] ?? 'Pengajar belum ditentukan') ?> &middot; <?= e(rupiah($k['biaya_bulanan'])) ?>/bulan</small>
                                    <small><?= $k['jadwal'] ? e(implode(' · ', $k['jadwal'])) : 'Jadwal belum diatur' ?></small>
                                    <small><?= $penuh ? 'Kuota penuh' : 'Sisa kuota: ' . (int) $k['sisa_kuota'] ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Belum ada kelas yang dibuka. Anda tetap bisa mendaftar akun dan memilih kelas nanti.</p>
                <?php endif; ?>

                <button type="submit" class="btn btn--primary btn--block" style="margin-top:24px">Daftar</button>
                <p class="muted" style="margin-top:16px;font-size:14px">Sudah punya akun? <a href="login.php">Masuk</a></p>
            </form>
        </div>
    </section>

</main>
</body>
</html>
