<?php
/**
 * absensi_catat.php — Pengajar mencatat kehadiran siswa dan catatan kegiatan belajar.
 * Alur: pilih jadwal kelas yang diampu + tanggal -> isi materi / catatan, kehadiran
 * pengajar, dan status tiap siswa aktif -> simpan.
 * Pengajar hanya bisa mengisi untuk kelas yang diampunya dan sampai
 * BATAS_HARI_ABSENSI_PENGAJAR hari ke belakang (koreksi lebih lama lewat admin).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_pengajar();

$user        = current_user();
$id_pengajar = (int) $user['id_pengajar'];
$status_hadir = status_kehadiran();
$hari_ini_tgl = date('Y-m-d');
$tgl_min      = date('Y-m-d', strtotime('-' . BATAS_HARI_ABSENSI_PENGAJAR . ' days'));

/** Slot jadwal hanya valid bila kelasnya diampu pengajar ini. */
$ambil_slot_saya = function (int $id_jadwal) use ($pdo, $id_pengajar) {
    $slot = slot_jadwal($pdo, $id_jadwal);
    return ($slot && (int) $slot['id_pengajar'] === $id_pengajar) ? $slot : false;
};

// ---------------------------------------------------------------
// SIMPAN (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_jadwal = (int) ($_POST['id_jadwal'] ?? 0);
    $tanggal   = trim($_POST['tanggal'] ?? '');
    $kembali   = 'absensi_catat.php?' . http_build_query(['jadwal' => $id_jadwal, 'tanggal' => $tanggal]);

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
        redirect('absensi_catat.php');
    }
    $slot = $ambil_slot_saya($id_jadwal);
    if (!$slot || !tanggal_valid($tanggal)) {
        flash_set('error', 'Jadwal tidak valid atau bukan kelas yang Anda ampu.');
        redirect('absensi_catat.php');
    }

    $hasil = simpan_pertemuan($pdo, $slot, $tanggal, $_POST, (int) $user['id_user'], BATAS_HARI_ABSENSI_PENGAJAR);
    if ($hasil['errors']) {
        foreach ($hasil['errors'] as $m) {
            flash_set('error', $m);
        }
    } else {
        flash_set('success', 'Absensi dan catatan ' . $slot['nama_kelas'] . ' tanggal ' . tanggal_indo($tanggal, false) . ' tersimpan.');
    }
    redirect($kembali);
}

// ---------------------------------------------------------------
// DATA TAMPILAN
// ---------------------------------------------------------------
$slots     = slot_pengajar($pdo, $id_pengajar);
$id_jadwal = (int) ($_GET['jadwal'] ?? 0);
$tanggal   = trim($_GET['tanggal'] ?? '');
if ($tanggal === '' || !tanggal_valid($tanggal)) {
    $tanggal = $hari_ini_tgl;
}

$slot = $id_jadwal ? $ambil_slot_saya($id_jadwal) : false;
$pertemuan = null;
$siswa = [];
$nilai = [];
if ($slot) {
    $siswa = siswa_aktif_kelas($pdo, (int) $slot['id_kelas']);
    [$pertemuan, $nilai] = data_pertemuan($pdo, $id_jadwal, $tanggal);
}
$di_luar_batas = $tanggal < $tgl_min || $tanggal > $hari_ini_tgl;

// Kelas hari ini (jalan pintas)
$hari_ini = hari_indo();
$slot_hari_ini = array_filter($slots, fn($s) => $s['hari'] === $hari_ini);
$sudah = [];
if ($slot_hari_ini) {
    $st = $pdo->prepare('SELECT id_jadwal FROM pertemuan WHERE tanggal = :t');
    $st->execute([':t' => $hari_ini_tgl]);
    $sudah = array_flip(array_column($st->fetchAll(), 'id_jadwal'));
}

$judul_halaman = 'Catat Absensi';
$sub_judul     = 'Isi kehadiran siswa dan catatan kegiatan belajar setiap pertemuan.';
$menu_aktif    = 'catat';
include __DIR__ . '/header.php';
?>

<?php if (!$slots): ?>
    <div class="panel"><p class="empty">Anda belum mengampu kelas yang memiliki jadwal. Hubungi admin bimbel.</p></div>
<?php else: ?>

<div class="panel" style="margin-bottom:24px">
    <div class="panel__head"><h2 class="panel__title">Pilih Pertemuan</h2></div>
    <div class="panel__body">
        <form method="get" action="absensi_catat.php" class="toolbar__filters" style="align-items:flex-end">
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
                <input class="input" type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>"
                       min="<?= e($tgl_min) ?>" max="<?= e($hari_ini_tgl) ?>">
            </div>
            <button type="submit" class="btn btn--dark">Buka</button>
        </form>

        <?php if ($slot_hari_ini): ?>
            <p class="eyebrow" style="margin:24px 0 10px">Kelas Anda hari ini &middot; <?= e($hari_ini) ?></p>
            <div class="chips">
                <?php foreach ($slot_hari_ini as $s): ?>
                    <a class="chip" href="absensi_catat.php?<?= e(http_build_query(['jadwal' => $s['id_jadwal'], 'tanggal' => $hari_ini_tgl])) ?>">
                        <strong><?= e(substr($s['jam_mulai'], 0, 5)) ?></strong> <?= e($s['nama_kelas']) ?>
                        <?php if (isset($sudah[$s['id_jadwal']])): ?><em>Sudah diisi</em><?php endif; ?>
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
        <div><span>Ruang</span> <strong><?= e($slot['ruangan'] ?? '—') ?></strong></div>
        <div><span>Tanggal</span> <strong><?= e(tanggal_indo($tanggal)) ?></strong></div>
    </div>

    <?php if ($di_luar_batas): ?>
        <div class="alert alert--error" style="margin:0 0 20px;max-width:920px">
            Tanggal ini di luar batas pengisian (maksimal <?= (int) BATAS_HARI_ABSENSI_PENGAJAR ?> hari ke belakang dan tidak boleh di masa depan).
            Hubungi admin untuk koreksi.
        </div>
    <?php elseif (hari_indo($tanggal) !== $slot['hari']): ?>
        <div class="alert alert--info" style="margin:0 0 20px;max-width:920px">
            Tanggal ini jatuh pada hari <?= e(hari_indo($tanggal)) ?>, sedangkan jadwal kelas hari <?= e($slot['hari']) ?>.
            Lanjutkan hanya jika ini pertemuan pengganti.
        </div>
    <?php endif; ?>

    <?php if (!$siswa): ?>
        <div class="panel"><p class="empty">Belum ada siswa aktif di kelas ini.</p></div>
    <?php else: ?>
        <form method="post" action="absensi_catat.php" class="form-card" style="max-width:none;margin-bottom:24px">
            <?= csrf_field() ?>
            <input type="hidden" name="id_jadwal" value="<?= (int) $slot['id_jadwal'] ?>">
            <input type="hidden" name="tanggal" value="<?= e($tanggal) ?>">

            <div class="form-section">
                <h2 class="form-section__title">Catatan Kegiatan Belajar</h2>
                <p class="form-section__desc"><?= $pertemuan ? 'Pertemuan ini sudah pernah diisi. Perubahan akan menimpa data lama.' : 'Belum ada catatan untuk pertemuan ini.' ?></p>
                <div class="form-grid">
                    <div class="field">
                        <label for="pertemuan_ke">Pertemuan ke-</label>
                        <input class="input" type="number" min="1" max="999" id="pertemuan_ke" name="pertemuan_ke"
                               value="<?= e($pertemuan['pertemuan_ke'] ?? '') ?>" placeholder="Otomatis jika dikosongkan">
                    </div>
                    <div class="field">
                        <label for="kehadiran_pengajar">Kehadiran Anda</label>
                        <select class="input" id="kehadiran_pengajar" name="kehadiran_pengajar">
                            <?php foreach ($status_hadir as $sh): ?>
                                <option value="<?= e($sh) ?>" <?= ($pertemuan['kehadiran_pengajar'] ?? 'hadir') === $sh ? 'selected' : '' ?>><?= e(ucfirst($sh)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="materi">Materi yang dibahas</label>
                        <input class="input" type="text" id="materi" name="materi" maxlength="255" value="<?= e($pertemuan['materi'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="catatan">Catatan kegiatan belajar</label>
                        <input class="input" type="text" id="catatan" name="catatan" maxlength="255" value="<?= e($pertemuan['catatan'] ?? '') ?>"
                               placeholder="Contoh: tugas, kendala, perkembangan kelas">
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
                <?php if (!$di_luar_batas): ?>
                    <button type="submit" class="btn btn--primary">Simpan Absensi &amp; Catatan</button>
                <?php else: ?>
                    <span class="muted">Tidak bisa disimpan: tanggal di luar batas.</span>
                <?php endif; ?>
            </div>
        </form>

        <script>
            document.getElementById('semuaHadir').addEventListener('click', function () {
                document.querySelectorAll('input[type=radio][value=hadir]').forEach(function (r) { r.checked = true; });
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
