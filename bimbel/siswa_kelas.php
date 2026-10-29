<?php
/**
 * siswa_kelas.php — Daftar nama siswa per kelas (Pengajar).
 * Menampilkan siswa dengan pendaftaran aktif di setiap kelas yang diampu,
 * beserta rekap kehadiran masing-masing siswa di kelas tersebut.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_pengajar();

$id_pengajar = (int) current_user()['id_pengajar'];
$kelas_list  = kelas_pengajar($pdo, $id_pengajar);

// Rekap kehadiran per siswa per kelas (hanya kelas milik pengajar ini)
$st = $pdo->prepare(
    "SELECT j.id_kelas, a.id_siswa, COUNT(*) AS total, SUM(a.status = 'hadir') AS hadir
       FROM absensi a
       JOIN pertemuan pt ON pt.id_pertemuan = a.id_pertemuan
       JOIN jadwal j     ON j.id_jadwal = pt.id_jadwal
       JOIN kelas k      ON k.id_kelas = j.id_kelas
      WHERE k.id_pengajar = :p
   GROUP BY j.id_kelas, a.id_siswa"
);
$st->execute([':p' => $id_pengajar]);
$rekap = [];
foreach ($st->fetchAll() as $r) {
    $rekap[(int) $r['id_kelas']][(int) $r['id_siswa']] = [(int) $r['hadir'], (int) $r['total']];
}

$id_pilih = (int) ($_GET['kelas'] ?? 0);

$judul_halaman = 'Daftar Siswa';
$sub_judul     = 'Nama siswa aktif pada setiap kelas yang Anda ampu.';
$menu_aktif    = 'siswa_kelas';
include __DIR__ . '/header.php';
?>

<?php if (!$kelas_list): ?>
    <div class="panel"><p class="empty">Anda belum mengampu kelas. Hubungi admin bimbel.</p></div>
<?php else: ?>
    <?php foreach ($kelas_list as $k): $idk = (int) $k['id_kelas'];
        if ($id_pilih && $id_pilih !== $idk) { continue; }
        $siswa = siswa_aktif_kelas($pdo, $idk); ?>
        <div class="panel" style="margin-bottom:24px">
            <div class="panel__head">
                <h2 class="panel__title"><?= e($k['nama_kelas']) ?></h2>
                <span class="muted" style="font-size:13px"><?= e($k['nama_mapel']) ?> &middot; <?= e($k['tingkat']) ?> &middot; <?= count($siswa) ?> siswa <?= badge_status($k['status']) ?></span>
            </div>
            <?php if ($siswa): ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th style="width:48px">No</th><th>Nama</th><th>NIS</th><th>Tingkat</th><th>Sekolah Asal</th><th>Kehadiran</th></tr></thead>
                        <tbody>
                        <?php foreach ($siswa as $i => $s):
                            $r = $rekap[$idk][(int) $s['id_siswa']] ?? null; ?>
                            <tr>
                                <td class="table-time"><?= $i + 1 ?></td>
                                <td><?= e($s['nama_lengkap']) ?><span class="cell-sub"><?= $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></span></td>
                                <td class="table-time"><?= e($s['nis']) ?></td>
                                <td><?= e($s['tingkat']) ?></td>
                                <td><?= e($s['sekolah_asal'] ?? '—') ?></td>
                                <td><?= $r ? e($r[0] . ' / ' . $r[1] . ' (' . round($r[0] / max(1, $r[1]) * 100) . '%)') : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty">Belum ada siswa aktif di kelas ini.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
