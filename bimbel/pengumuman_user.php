<?php
/**
 * pengumuman_user.php — Pengumuman & informasi perubahan jadwal (Siswa & Pengajar).
 * Hanya menampilkan pengumuman yang ditujukan ke peran ini dan ke kelas yang
 * diikuti (siswa) / diampu (pengajar), ditambah pengumuman untuk semua kelas.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_user();

$user      = current_user();
$role      = $user['role'];
$id_profil = (int) ($role === 'siswa' ? $user['id_siswa'] : $user['id_pengajar']);

$per     = 10;
$total   = jumlah_pengumuman_untuk($pdo, $role, $id_profil);
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);
$daftar  = pengumuman_untuk($pdo, $role, $id_profil, $per, ($halaman - 1) * $per);

$judul_halaman = 'Pengumuman';
$sub_judul     = 'Informasi dari admin, termasuk perubahan jadwal kelas.';
$menu_aktif    = 'pengumuman';
include __DIR__ . '/header.php';
?>

<div class="panel">
    <?php if ($daftar): ?>
        <ul class="news">
            <?php foreach ($daftar as $g): ?>
                <li>
                    <span class="news__title"><?= e($g['judul']) ?></span>
                    <?php if ($g['tipe'] === 'jadwal'): ?> <span class="badge badge--dark">Perubahan jadwal</span><?php endif; ?>
                    <span class="news__meta">
                        <?= e(tanggal_indo(substr($g['created_at'], 0, 10), false)) ?>, <?= e(substr($g['created_at'], 11, 5)) ?>
                        &middot; <?= e($g['nama_kelas'] ?? 'Semua kelas') ?>
                    </span>
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
