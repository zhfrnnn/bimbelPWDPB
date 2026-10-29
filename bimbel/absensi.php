<?php
/**
 * absensi.php — Pencatatan absensi pertemuan (Admin).
 * Alur: pilih slot jadwal + tanggal -> isi materi, kehadiran pengajar,
 * dan status tiap siswa aktif di kelas tersebut -> simpan.
 * Data disimpan di tabel pertemuan (induk) dan absensi (per siswa).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_admin();

$status_hadir  = ['hadir', 'izin', 'sakit', 'alpha'];
$hari_ini_tgl  = date('Y-m-d');

/** Ambil satu slot jadwal beserta kelasnya. */
$ambil_slot = function (int $id_jadwal) use ($pdo) {
    $st = $pdo->prepare(
        'SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                k.id_kelas, k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar
           FROM jadwal j
           JOIN kelas k          ON k.id_kelas = j.id_kelas
           JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
      LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
          WHERE j.id_jadwal = :id'
    );
    $st->execute([':id' => $id_jadwal]);
    return $st->fetch();
};

/** Siswa dengan pendaftaran aktif di sebuah kelas. */
$ambil_siswa = function (int $id_kelas) use ($pdo): array {
    $st = $pdo->prepare(
        "SELECT s.id_siswa, s.nis, s.nama_lengkap
           FROM pendaftaran pd JOIN siswa s ON s.id_siswa = pd.id_siswa
          WHERE pd.id_kelas = :k AND pd.status = 'aktif'
       ORDER BY s.nama_lengkap"
    );
    $st->execute([':k' => $id_kelas]);
    return $st->fetchAll();
};

// ---------------------------------------------------------------
// SIMPAN / HAPUS (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi       = $_POST['aksi'] ?? '';
    $id_jadwal  = (int) ($_POST['id_jadwal'] ?? 0);
    $tanggal    = trim($_POST['tanggal'] ?? '');
    $kembali    = 'absensi.php?' . http_build_query(['jadwal' => $id_jadwal, 'tanggal' => $tanggal]);
    $slot       = $ambil_slot($id_jadwal);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
        redirect('absensi.php');
    }
    if (!$slot || !tanggal_valid($tanggal)) {
        flash_set('error', 'Jadwal atau tanggal tidak valid.');
        redirect('absensi.php');
    }

    if ($aksi === 'hapus') {
        $pdo->prepare('DELETE FROM pertemuan WHERE id_jadwal = :j AND tanggal = :t')
            ->execute([':j' => $id_jadwal, ':t' => $tanggal]);
        flash_set('success', 'Absensi pertemuan tanggal ' . tanggal_indo($tanggal, false) . ' dihapus.');
        redirect('absensi.php');
    }

    if ($aksi === 'simpan') {
        $err = [];
        if ($tanggal > $hari_ini_tgl) {
            $err[] = 'Tanggal pertemuan tidak boleh di masa depan.';
        }
        $materi   = trim($_POST['materi'] ?? '');
        $catatan  = trim($_POST['catatan'] ?? '');
        $hadir_p  = $_POST['kehadiran_pengajar'] ?? 'hadir';
        $ke       = trim($_POST['pertemuan_ke'] ?? '');
        $siswa    = $ambil_siswa((int) $slot['id_kelas']);

        if (!in_array($hadir_p, $status_hadir, true)) {
            $err[] = 'Status kehadiran pengajar tidak valid.';
        }
        if ($ke !== '' && (!ctype_digit($ke) || (int) $ke < 1 || (int) $ke > 999)) {
            $err[] = 'Pertemuan ke- harus angka 1-999.';
        }
        if (mb_strlen($materi) > 255 || mb_strlen($catatan) > 255) {
            $err[] = 'Materi dan catatan maksimal 255 karakter.';
        }
        if (!$siswa) {
            $err[] = 'Belum ada siswa aktif di kelas ini.';
        }

        if ($err) {
            foreach ($err as $m) {
                flash_set('error', $m);
            }
            redirect($kembali);
        }

        try {
            $pdo->beginTransaction();

            if ($ke === '') {
                $ke = 1 + (int) db_scalar($pdo,
                    'SELECT COUNT(*) FROM pertemuan WHERE id_jadwal = :j AND tanggal < :t',
                    [':j' => $id_jadwal, ':t' => $tanggal]);
            }

            $pdo->prepare(
                'INSERT INTO pertemuan (id_jadwal, tanggal, pertemuan_ke, materi, kehadiran_pengajar, catatan)
                 VALUES (:j, :t, :ke, :m, :h, :c)
                 ON DUPLICATE KEY UPDATE pertemuan_ke = VALUES(pertemuan_ke), materi = VALUES(materi),
                                         kehadiran_pengajar = VALUES(kehadiran_pengajar), catatan = VALUES(catatan)'
            )->execute([
                ':j' => $id_jadwal, ':t' => $tanggal, ':ke' => (int) $ke,
                ':m' => $materi === '' ? null : $materi, ':h' => $hadir_p,
                ':c' => $catatan === '' ? null : $catatan,
            ]);

            $id_pertemuan = (int) db_scalar($pdo,
                'SELECT id_pertemuan FROM pertemuan WHERE id_jadwal = :j AND tanggal = :t',
                [':j' => $id_jadwal, ':t' => $tanggal]);

            $up = $pdo->prepare(
                'INSERT INTO absensi (id_pertemuan, id_siswa, status, keterangan)
                 VALUES (:p, :s, :st, :k)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), keterangan = VALUES(keterangan)'
            );
            foreach ($siswa as $s) {
                $sid = (int) $s['id_siswa'];
                $st  = $_POST['status'][$sid] ?? 'alpha';
                $ket = trim($_POST['ket'][$sid] ?? '');
                if (!in_array($st, $status_hadir, true)) {
                    $st = 'alpha';
                }
                $up->execute([':p' => $id_pertemuan, ':s' => $sid, ':st' => $st,
                              ':k' => $ket === '' ? null : mb_substr($ket, 0, 255)]);
            }

            $pdo->commit();
            flash_set('success', 'Absensi ' . $slot['nama_kelas'] . ' tanggal ' . tanggal_indo($tanggal, false) . ' tersimpan.');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan absensi. Silakan coba lagi.');
        }
        redirect($kembali);
    }

    redirect('absensi.php');
}

// ---------------------------------------------------------------
// DATA TAMPILAN
// ---------------------------------------------------------------
$slots = $pdo->query(
    "SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, k.nama_kelas
       FROM jadwal j JOIN kelas k ON k.id_kelas = j.id_kelas
      WHERE k.status = 'buka'
   ORDER BY FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.jam_mulai"
)->fetchAll();

$id_jadwal = (int) ($_GET['jadwal'] ?? 0);
$tanggal   = trim($_GET['tanggal'] ?? '');
if ($tanggal === '' || !tanggal_valid($tanggal)) {
    $tanggal = $hari_ini_tgl;
}

$slot = $id_jadwal ? $ambil_slot($id_jadwal) : false;

$pertemuan = null;
$siswa     = [];
$nilai     = [];   // id_siswa => [status, keterangan]
if ($slot) {
    $siswa = $ambil_siswa((int) $slot['id_kelas']);

    $st = $pdo->prepare('SELECT * FROM pertemuan WHERE id_jadwal = :j AND tanggal = :t');
    $st->execute([':j' => $id_jadwal, ':t' => $tanggal]);
    $pertemuan = $st->fetch() ?: null;

    if ($pertemuan) {
        $st = $pdo->prepare('SELECT id_siswa, status, keterangan FROM absensi WHERE id_pertemuan = :p');
        $st->execute([':p' => $pertemuan['id_pertemuan']]);
        foreach ($st->fetchAll() as $a) {
            $nilai[(int) $a['id_siswa']] = [$a['status'], (string) $a['keterangan']];
        }
    }
}

// Jadwal hari ini (jalan pintas)
$hari_ini = hari_indo();
$slot_hari_ini = array_filter($slots, fn($s) => $s['hari'] === $hari_ini);
$sudah = [];
if ($slot_hari_ini) {
    $st = $pdo->prepare('SELECT id_jadwal FROM pertemuan WHERE tanggal = :t');
    $st->execute([':t' => $hari_ini_tgl]);
    $sudah = array_flip(array_column($st->fetchAll(), 'id_jadwal'));
}

// Riwayat pertemuan
$per     = 10;
$total   = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM pertemuan');
$jumlah  = max(1, (int) ceil($total / $per));
$halaman = min(max(1, (int) ($_GET['page'] ?? 1)), $jumlah);

$st = $pdo->prepare(
    "SELECT pt.id_jadwal, pt.tanggal, pt.pertemuan_ke, pt.materi, pt.kehadiran_pengajar,
            k.nama_kelas, j.jam_mulai,
            COALESCE(SUM(a.status = 'hadir'), 0) AS hadir, COUNT(a.id_absensi) AS jml
       FROM pertemuan pt
       JOIN jadwal j ON j.id_jadwal = pt.id_jadwal
       JOIN kelas k  ON k.id_kelas = j.id_kelas
  LEFT JOIN absensi a ON a.id_pertemuan = pt.id_pertemuan
   GROUP BY pt.id_pertemuan, pt.id_jadwal, pt.tanggal, pt.pertemuan_ke, pt.materi, pt.kehadiran_pengajar, k.nama_kelas, j.jam_mulai
   ORDER BY pt.tanggal DESC, j.jam_mulai DESC
      LIMIT :lim OFFSET :off"
);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', ($halaman - 1) * $per, PDO::PARAM_INT);
$st->execute();
$riwayat = $st->fetchAll();

$judul_halaman = 'Absensi';
$sub_judul     = 'Catat kehadiran siswa dan pengajar pada setiap pertemuan.';
$menu_aktif    = 'absensi';
include __DIR__ . '/header.php';
?>

<!-- Pilih jadwal & tanggal -->
<div class="panel" style="margin-bottom:24px">
    <div class="panel__head"><h2 class="panel__title">Pilih Pertemuan</h2></div>
    <div class="panel__body">
        <form method="get" action="absensi.php" class="toolbar__filters" style="align-items:flex-end">
            <div class="field" style="margin:0;flex:1;min-width:260px">
                <label for="jadwal">Kelas &amp; jadwal</label>
                <select class="input" id="jadwal" name="jadwal" required style="width:100%">
                    <option value="">— Pilih jadwal —</option>
                    <?php foreach ($slots as $s): ?>
                        <option value="<?= (int) $s['id_jadwal'] ?>" <?= $id_jadwal === (int) $s['id_jadwal'] ? 'selected' : '' ?>>
                            <?= e($s['nama_kelas']) ?> — <?= e($s['hari']) ?> <?= e(substr($s['jam_mulai'], 0, 5)) ?>–<?= e(substr($s['jam_selesai'], 0, 5)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0">
                <label for="tanggal">Tanggal</label>
                <input class="input" type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" max="<?= e($hari_ini_tgl) ?>">
            </div>
            <button type="submit" class="btn btn--dark">Buka</button>
        </form>

        <?php if ($slot_hari_ini): ?>
            <p class="eyebrow" style="margin:24px 0 10px">Kelas hari ini &middot; <?= e($hari_ini) ?></p>
            <div class="chips">
                <?php foreach ($slot_hari_ini as $s): ?>
                    <a class="chip" href="absensi.php?<?= e(http_build_query(['jadwal' => $s['id_jadwal'], 'tanggal' => $hari_ini_tgl])) ?>">
                        <strong><?= e(substr($s['jam_mulai'], 0, 5)) ?></strong> <?= e($s['nama_kelas']) ?>
                        <?php if (isset($sudah[$s['id_jadwal']])): ?><em>Sudah diabsen</em><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($slot): ?>
    <div class="info-strip">
        <div><span>Kelas</span> <strong><?= e($slot['nama_kelas']) ?></strong> &middot; <?= e($slot['nama_mapel']) ?></div>
        <div><span>Jadwal</span> <strong><?= e($slot['hari']) ?> <?= e(substr($slot['jam_mulai'], 0, 5)) ?>–<?= e(substr($slot['jam_selesai'], 0, 5)) ?></strong></div>
        <div><span>Pengajar</span> <strong><?= e($slot['pengajar'] ?? '—') ?></strong></div>
        <div><span>Tanggal</span> <strong><?= e(tanggal_indo($tanggal)) ?></strong></div>
    </div>

    <?php if (hari_indo($tanggal) !== $slot['hari']): ?>
        <div class="alert alert--info" style="margin:0 0 20px;max-width:920px">
            Tanggal ini jatuh pada hari <?= e(hari_indo($tanggal)) ?>, sedangkan jadwal kelas hari <?= e($slot['hari']) ?>.
            Lanjutkan hanya jika ini pertemuan pengganti.
        </div>
    <?php endif; ?>

    <?php if (!$siswa): ?>
        <div class="panel"><p class="empty">Belum ada siswa aktif di kelas ini. Setujui pendaftaran siswa terlebih dahulu.</p></div>
    <?php else: ?>
        <form method="post" action="absensi.php" class="form-card" style="max-width:none;margin-bottom:24px">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="simpan">
            <input type="hidden" name="id_jadwal" value="<?= (int) $slot['id_jadwal'] ?>">
            <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">

            <div class="form-section">
                <h2 class="form-section__title">Detail Pertemuan</h2>
                <p class="form-section__desc"><?= $pertemuan ? 'Absensi pertemuan ini sudah pernah dicatat. Perubahan akan menimpa data lama.' : 'Belum ada absensi untuk pertemuan ini.' ?></p>
                <div class="form-grid">
                    <div class="field">
                        <label for="pertemuan_ke">Pertemuan ke-</label>
                        <input class="input" type="number" min="1" max="999" id="pertemuan_ke" name="pertemuan_ke"
                               value="<?= e($pertemuan['pertemuan_ke'] ?? '') ?>" placeholder="Otomatis jika dikosongkan">
                    </div>
                    <div class="field">
                        <label for="kehadiran_pengajar">Kehadiran Pengajar</label>
                        <select class="input" id="kehadiran_pengajar" name="kehadiran_pengajar">
                            <?php foreach ($status_hadir as $sh): ?>
                                <option value="<?= e($sh) ?>" <?= ($pertemuan['kehadiran_pengajar'] ?? 'hadir') === $sh ? 'selected' : '' ?>><?= e(ucfirst($sh)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="materi">Materi</label>
                        <input class="input" type="text" id="materi" name="materi" maxlength="255" value="<?= e($pertemuan['materi'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="catatan">Catatan</label>
                        <input class="input" type="text" id="catatan" name="catatan" maxlength="255" value="<?= e($pertemuan['catatan'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section" style="padding-bottom:0">
                <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;margin-bottom:16px">
                    <div>
                        <h2 class="form-section__title">Kehadiran Siswa</h2>
                        <p class="form-section__desc" style="margin:0"><?= count($siswa) ?> siswa aktif</p>
                    </div>
                    <button type="button" class="btn btn--sm btn--outline" id="semuaHadir">Tandai semua hadir</button>
                </div>
            </div>

            <div class="table-wrap" style="border:0;border-top:var(--line)">
                <table class="table">
                    <thead><tr><th>Siswa</th><th>Status</th><th>Keterangan</th></tr></thead>
                    <tbody>
                    <?php foreach ($siswa as $s): $sid = (int) $s['id_siswa']; $cur = $nilai[$sid][0] ?? 'hadir'; ?>
                        <tr>
                            <td><?= e($s['nama_lengkap']) ?><span class="cell-sub"><?= e($s['nis']) ?></span></td>
                            <td>
                                <div class="seg">
                                    <?php foreach ($status_hadir as $sh): ?>
                                        <label class="seg__opt seg__opt--<?= e($sh) ?>">
                                            <input type="radio" name="status[<?= $sid ?>]" value="<?= e($sh) ?>" <?= $cur === $sh ? 'checked' : '' ?>>
                                            <span><?= e(ucfirst($sh)) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><input class="input" type="text" name="ket[<?= $sid ?>]" maxlength="255" value="<?= e($nilai[$sid][1] ?? '') ?>" placeholder="Opsional" style="padding:8px 12px"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                <?php if ($pertemuan): ?>
                    <button type="submit" class="btn btn--danger-ghost" form="formHapus" style="margin-right:auto">Hapus absensi</button>
                <?php endif; ?>
                <button type="submit" class="btn btn--primary">Simpan Absensi</button>
            </div>
        </form>

        <?php if ($pertemuan): ?>
            <form method="post" action="absensi.php" id="formHapus" onsubmit="return confirm('Hapus seluruh absensi pertemuan ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id_jadwal" value="<?= (int) $slot['id_jadwal'] ?>">
                <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">
            </form>
        <?php endif; ?>

        <script>
            document.getElementById('semuaHadir').addEventListener('click', function () {
                document.querySelectorAll('input[type=radio][value=hadir]').forEach(function (r) { r.checked = true; });
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<!-- Riwayat -->
<div class="panel">
    <div class="panel__head"><h2 class="panel__title">Riwayat Pertemuan</h2></div>
    <?php if ($riwayat): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Tanggal</th><th>Kelas</th><th>Materi</th><th>Hadir</th><th>Pengajar</th><th class="text-right">Aksi</th></tr>
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
                        <td class="table-time"><?= (int) $r['hadir'] ?> / <?= (int) $r['jml'] ?></td>
                        <td><?= badge_status($r['kehadiran_pengajar']) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--sm btn--outline" href="absensi.php?<?= e(http_build_query(['jadwal' => $r['id_jadwal'], 'tanggal' => $r['tanggal']])) ?>">Buka</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= paginasi($total, $per, $halaman) ?>
    <?php else: ?>
        <p class="empty">Belum ada pertemuan yang dicatat.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
