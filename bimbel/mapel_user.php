<?php
/**
 * mapel_user.php — Daftar mata pelajaran & kelas yang dibuka (Siswa).
 * Menampilkan mapel beserta kelas, pengajar, jadwal, biaya, dan sisa kuota.
 * Siswa dapat mengajukan diri ke sebuah kelas (menunggu persetujuan admin).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_siswa();

$id_siswa = (int) current_user()['id_siswa'];

// ---------------------------------------------------------------
// AJUKAN KELAS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    } else {
        [$ok, $pesan] = ajukan_kelas($pdo, $id_siswa, (int) ($_POST['id_kelas'] ?? 0));
        flash_set($ok ? 'success' : 'error', $pesan);
    }
    redirect('mapel_user.php');
}

$kelas_list = kelas_terbuka($pdo, $id_siswa);

// Kelompokkan per mata pelajaran
$per_mapel = [];
foreach ($kelas_list as $k) {
    $per_mapel[$k['nama_mapel']]['deskripsi'] = $k['deskripsi'];
    $per_mapel[$k['nama_mapel']]['kelas'][]   = $k;
}

// Mapel yang belum punya kelas dibuka tetap ditampilkan (informasi saja)
$semua_mapel = $pdo->query('SELECT nama_mapel, deskripsi FROM mata_pelajaran ORDER BY nama_mapel')->fetchAll();
foreach ($semua_mapel as $m) {
    if (!isset($per_mapel[$m['nama_mapel']])) {
        $per_mapel[$m['nama_mapel']] = ['deskripsi' => $m['deskripsi'], 'kelas' => []];
    }
}
ksort($per_mapel);

$label_status = ['aktif' => 'Anda terdaftar', 'pending' => 'Menunggu persetujuan'];

$judul_halaman = 'Mata Pelajaran';
$sub_judul     = 'Daftar mata pelajaran dan kelas yang tersedia. Ajukan kelas yang ingin Anda ikuti.';
$menu_aktif    = 'mapel';
include __DIR__ . '/header.php';
?>

<?php foreach ($per_mapel as $nama => $m): ?>
    <section style="margin-bottom:28px">
        <h2 style="font-size:20px"><?= e($nama) ?></h2>
        <p class="muted" style="margin:2px 0 14px"><?= e($m['deskripsi'] ?: 'Tanpa deskripsi.') ?></p>

        <?php if ($m['kelas']): ?>
            <div class="cards">
                <?php foreach ($m['kelas'] as $k): $st = $k['status_saya']; $mine = in_array($st, ['aktif', 'pending'], true); ?>
                    <article class="card-info<?= $mine ? ' card-info--accent' : '' ?>">
                        <h3><?= e($k['nama_kelas']) ?></h3>
                        <p class="meta"><?= e($k['tingkat']) ?> &middot; <?= e(rupiah($k['biaya_bulanan'])) ?>/bulan &middot; TA <?= e($k['tahun_ajaran']) ?></p>
                        <p style="font-size:14px"><strong>Pengajar:</strong> <?= e($k['pengajar'] ?? 'Belum ditentukan') ?></p>
                        <ul>
                            <?php foreach ($k['jadwal'] as $j): ?><li><?= e($j) ?></li><?php endforeach; ?>
                            <?php if (!$k['jadwal']): ?><li>Jadwal belum diatur</li><?php endif; ?>
                        </ul>
                        <p class="meta" style="margin-top:10px">Sisa kuota: <?= (int) $k['sisa_kuota'] ?> dari <?= (int) $k['kuota'] ?></p>

                        <?php if ($mine): ?>
                            <?= badge_status($st) ?> <span class="muted" style="font-size:13px"><?= e($label_status[$st]) ?></span>
                        <?php elseif ((int) $k['sisa_kuota'] < 1): ?>
                            <span class="badge badge--danger">Kuota penuh</span>
                        <?php else: ?>
                            <form method="post" action="mapel_user.php" onsubmit="return confirm('Ajukan pendaftaran ke kelas ini?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id_kelas" value="<?= (int) $k['id_kelas'] ?>">
                                <button type="submit" class="btn btn--sm btn--primary">Ajukan Kelas</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Belum ada kelas dibuka untuk mata pelajaran ini.</p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if (!$per_mapel): ?>
    <div class="panel"><p class="empty">Belum ada mata pelajaran.</p></div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
