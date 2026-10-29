<?php
/**
 * pengajar_info.php — Profil pengajar (Siswa).
 * Menampilkan pengajar aktif beserta keahlian, pendidikan, dan kelas yang diampu.
 * Kontak (email / telepon) hanya tampil untuk pengajar dari kelas yang diikuti siswa.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_siswa();

$daftar = info_pengajar_untuk_siswa($pdo, (int) current_user()['id_siswa']);

$judul_halaman = 'Info Pengajar';
$sub_judul     = 'Kenali pengajar di bimbel. Pengajar kelas Anda ditandai dan menampilkan kontak.';
$menu_aktif    = 'pengajar_info';
include __DIR__ . '/header.php';
?>

<?php if ($daftar): ?>
    <div class="cards">
        <?php foreach ($daftar as $p): $saya = (int) $p['pengajar_saya'] === 1; ?>
            <article class="card-info<?= $saya ? ' card-info--accent' : '' ?>">
                <h3><?= e($p['nama_lengkap']) ?></h3>
                <p class="meta"><?= e($p['pendidikan_terakhir'] ?? 'Pendidikan belum diisi') ?></p>
                <p style="font-size:14px"><strong>Keahlian:</strong> <?= e($p['keahlian'] ?? '—') ?></p>
                <p style="font-size:14px"><strong>Bergabung:</strong> <?= e(tanggal_indo($p['tanggal_bergabung'], false)) ?></p>
                <?php if ($p['kelas_diampu']): ?>
                    <ul>
                        <?php foreach ($p['kelas_diampu'] as $kn): ?><li><?= e($kn) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($saya): ?>
                    <p style="margin-top:10px;font-size:13px">
                        <span class="badge badge--blue">Pengajar kelas Anda</span><br>
                        <?php if ($p['email']): ?>Email: <?= e($p['email']) ?><br><?php endif; ?>
                        <?php if ($p['no_telp']): ?>Telepon: <?= e($p['no_telp']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel"><p class="empty">Belum ada data pengajar.</p></div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
