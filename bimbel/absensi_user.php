<?php
/**
 * absensi_user.php — Riwayat Absensi (Siswa & Pengajar).
 * Siswa   : rekap kehadiran pribadi + riwayat tiap pertemuan.
 * Pengajar: riwayat pertemuan kelas yang diampu + kehadiran siswa.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_user();

$user        = current_user();
$is_siswa    = $user['role'] === 'siswa';
$id_siswa    = (int) ($user['id_siswa'] ?? 0);
$id_pengajar = (int) ($user['id_pengajar'] ?? 0);

$per = 12;

if ($is_siswa) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'hadir'), 0) AS hadir,
                COALESCE(SUM(status = 'izin'), 0)  AS izin,
                COALESCE(SUM(status = 'sakit'), 0) AS sakit,
                COALESCE(SUM(status = 'alpha'), 0) AS alpha
           FROM absensi WHERE id_siswa = :id"
    );
    $stmt->execute([':id' => $id_siswa]);
    $rekap  = $stmt->fetch();
    $persen = (int) $rekap['total'] > 0 ? round($rekap['hadir'] / $rekap['total'] * 100) . '%' : '—';

    $total = (int) $rekap['total'];
} else {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pertemuan pt
           JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k  ON k.id_kelas = j.id_kelas
          WHERE k.id_pengajar = :id"
    );
    $stmt->execute([':id' => $id_pengajar]);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pt.kehadiran_pengajar = 'hadir'), 0) AS hadir
           FROM pertemuan pt
           JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k  ON k.id_kelas = j.id_kelas
          WHERE k.id_pengajar = :id"
    );
    $stmt->execute([':id' => $id_pengajar]);
    $mengajar_hadir = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(a.status = 'hadir'), 0) AS hadir, COUNT(a.id_absensi) AS jml
           FROM absensi a
           JOIN pertemuan pt ON pt.id_pertemuan = a.id_pertemuan
           JOIN jadwal j     ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k      ON k.id_kelas = j.id_kelas
          WHERE k.id_pengajar = :id"
    );
    $stmt->execute([':id' => $id_pengajar]);
    $sis = $stmt->fetch();
    $persen_siswa = (int) $sis['jml'] > 0 ? round($sis['hadir'] / $sis['jml'] * 100) . '%' : '—';
}

$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);
$offset  = ($halaman - 1) * $per;

if ($is_siswa) {
    $stmt = $pdo->prepare(
        "SELECT pt.tanggal, pt.pertemuan_ke, pt.materi, a.status, a.keterangan,
                k.nama_kelas, j.jam_mulai
           FROM absensi a
           JOIN pertemuan pt ON pt.id_pertemuan = a.id_pertemuan
           JOIN jadwal j     ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k      ON k.id_kelas = j.id_kelas
          WHERE a.id_siswa = :id
       ORDER BY pt.tanggal DESC, j.jam_mulai DESC
          LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue(':id', $id_siswa, PDO::PARAM_INT);
} else {
    $stmt = $pdo->prepare(
        "SELECT pt.tanggal, pt.pertemuan_ke, pt.materi, pt.kehadiran_pengajar,
                k.nama_kelas, j.jam_mulai,
                COALESCE(SUM(a.status = 'hadir'), 0) AS hadir, COUNT(a.id_absensi) AS jml
           FROM pertemuan pt
           JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
           JOIN kelas k  ON k.id_kelas = j.id_kelas
      LEFT JOIN absensi a ON a.id_pertemuan = pt.id_pertemuan
          WHERE k.id_pengajar = :id
       GROUP BY pt.id_pertemuan, pt.tanggal, pt.pertemuan_ke, pt.materi, pt.kehadiran_pengajar, k.nama_kelas, j.jam_mulai
       ORDER BY pt.tanggal DESC, j.jam_mulai DESC
          LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue(':id', $id_pengajar, PDO::PARAM_INT);
}
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$riwayat = $stmt->fetchAll();

$judul_halaman = 'Absensi';
$sub_judul     = $is_siswa ? 'Rekap dan riwayat kehadiran Anda di setiap pertemuan.' : 'Riwayat pertemuan dan kehadiran siswa di kelas Anda.';
$menu_aktif    = 'absensi';
include __DIR__ . '/header.php';
?>

<section class="stats">
    <?php if ($is_siswa): ?>
        <article class="stat stat--accent">
            <p class="stat__label">Kehadiran</p>
            <p class="stat__value"><?= e($persen) ?></p>
            <p class="stat__meta"><?= (int) $rekap['hadir'] ?> hadir dari <?= (int) $rekap['total'] ?> pertemuan</p>
        </article>
        <article class="stat">
            <p class="stat__label">Izin</p>
            <p class="stat__value"><?= (int) $rekap['izin'] ?></p>
            <p class="stat__meta">pertemuan</p>
        </article>
        <article class="stat">
            <p class="stat__label">Sakit</p>
            <p class="stat__value"><?= (int) $rekap['sakit'] ?></p>
            <p class="stat__meta">pertemuan</p>
        </article>
        <article class="stat">
            <p class="stat__label">Alpha</p>
            <p class="stat__value"><?= (int) $rekap['alpha'] ?></p>
            <p class="stat__meta">tanpa keterangan</p>
        </article>
    <?php else: ?>
        <article class="stat">
            <p class="stat__label">Pertemuan Tercatat</p>
            <p class="stat__value"><?= $total ?></p>
            <p class="stat__meta">di seluruh kelas Anda</p>
        </article>
        <article class="stat">
            <p class="stat__label">Kehadiran Mengajar</p>
            <p class="stat__value"><?= $mengajar_hadir ?></p>
            <p class="stat__meta">dari <?= $total ?> pertemuan</p>
        </article>
        <article class="stat stat--accent">
            <p class="stat__label">Kehadiran Siswa</p>
            <p class="stat__value"><?= e($persen_siswa) ?></p>
            <p class="stat__meta">rata-rata seluruh kelas</p>
        </article>
    <?php endif; ?>
</section>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title">Riwayat Pertemuan</h2></div>
    <?php if ($riwayat): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal</th><th>Kelas</th><th>Materi</th>
                        <?php if ($is_siswa): ?><th>Status</th><th>Keterangan</th>
                        <?php else: ?><th>Siswa Hadir</th><th>Pengajar</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($riwayat as $r): ?>
                    <tr>
                        <td class="table-time"><?= e(tanggal_indo($r['tanggal'], false)) ?></td>
                        <td>
                            <?= e($r['nama_kelas']) ?>
                            <?php if ($r['pertemuan_ke']): ?><span class="cell-sub">Pertemuan ke-<?= (int) $r['pertemuan_ke'] ?></span><?php endif; ?>
                        </td>
                        <td><?= e($r['materi'] ?? '—') ?></td>
                        <?php if ($is_siswa): ?>
                            <td><?= badge_status($r['status']) ?></td>
                            <td><?= e($r['keterangan'] ?? '—') ?></td>
                        <?php else: ?>
                            <td class="table-time"><?= (int) $r['hadir'] ?> / <?= (int) $r['jml'] ?></td>
                            <td><?= badge_status($r['kehadiran_pengajar']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginasi($total, $per, $halaman) ?>
    <?php else: ?>
        <p class="empty">Belum ada data absensi.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
