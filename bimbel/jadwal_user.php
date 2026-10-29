<?php
/**
 * jadwal_user.php — Jadwal Kelas (Siswa & Pengajar).
 * Siswa  : jadwal kelas yang pendaftarannya sudah aktif.
 * Pengajar: jadwal kelas yang diampu.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_user();

$user        = current_user();
$is_siswa    = $user['role'] === 'siswa';
$hari_opsi   = daftar_hari();
$hari_ini    = hari_indo();
$id_siswa    = (int) ($user['id_siswa'] ?? 0);
$id_pengajar = (int) ($user['id_pengajar'] ?? 0);

if ($is_siswa) {
    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar
           FROM pendaftaran pd
           JOIN kelas k          ON k.id_kelas = pd.id_kelas
           JOIN jadwal j         ON j.id_kelas = k.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE pd.id_siswa = :id AND pd.status = 'aktif' AND k.status = 'buka'
       ORDER BY j.jam_mulai"
    );
    $stmt->execute([':id' => $id_siswa]);
} else {
    $stmt = $pdo->prepare(
        "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.nama_kelas, m.nama_mapel,
                (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS jml_siswa
           FROM jadwal j
           JOIN kelas k          ON k.id_kelas = j.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          WHERE k.id_pengajar = :id AND k.status = 'buka'
       ORDER BY j.jam_mulai"
    );
    $stmt->execute([':id' => $id_pengajar]);
}
$semua = $stmt->fetchAll();

$mingguan = array_fill_keys($hari_opsi, []);
foreach ($semua as $j) {
    $mingguan[$j['hari']][] = $j;
}

$judul_halaman = 'Jadwal Kelas';
$sub_judul     = $is_siswa ? 'Jadwal belajar dari kelas yang Anda ikuti.' : 'Jadwal mengajar dari kelas yang Anda ampu.';
$menu_aktif    = 'jadwal';
include __DIR__ . '/header.php';
?>

<div class="panel" style="margin-bottom:24px">
    <div class="panel__head"><h2 class="panel__title">Jadwal Mingguan</h2></div>
    <div class="week-wrap" style="border:0;margin-bottom:0">
        <div class="week">
            <?php foreach ($hari_opsi as $h): ?>
                <div class="week__day<?= $h === $hari_ini ? ' is-today' : '' ?>">
                    <div class="week__head"><span><?= e($h) ?></span><span><?= count($mingguan[$h]) ?></span></div>
                    <?php if ($mingguan[$h]): ?>
                        <?php foreach ($mingguan[$h] as $s): ?>
                            <div class="week__slot">
                                <span class="week__time"><?= e(substr($s['jam_mulai'], 0, 5)) ?>&ndash;<?= e(substr($s['jam_selesai'], 0, 5)) ?></span>
                                <strong><?= e($s['nama_kelas']) ?></strong>
                                <?= e($s['ruangan'] ?? '') ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="week__empty">Kosong</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h2 class="panel__title">Daftar Jadwal</h2></div>
    <?php if ($semua): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Hari</th><th>Waktu</th><th>Kelas</th>
                        <th><?= $is_siswa ? 'Pengajar' : 'Siswa' ?></th><th>Ruang</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hari_opsi as $h): foreach ($mingguan[$h] as $j): ?>
                    <tr<?= $h === $hari_ini ? ' style="background:var(--sage-35)"' : '' ?>>
                        <td><?= e($h) ?><?= $h === $hari_ini ? ' <span class="badge badge--blue">Hari ini</span>' : '' ?></td>
                        <td class="table-time"><?= e(substr($j['jam_mulai'], 0, 5)) ?> &ndash; <?= e(substr($j['jam_selesai'], 0, 5)) ?></td>
                        <td><?= e($j['nama_kelas']) ?><span class="cell-sub"><?= e($j['nama_mapel']) ?></span></td>
                        <td><?= $is_siswa ? e($j['pengajar'] ?? '—') : (int) $j['jml_siswa'] . ' siswa' ?></td>
                        <td><?= e($j['ruangan'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty"><?= $is_siswa ? 'Belum ada jadwal. Pastikan pendaftaran kelas Anda sudah disetujui admin.' : 'Belum ada jadwal mengajar.' ?></p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
