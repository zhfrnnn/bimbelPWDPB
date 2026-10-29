<?php
/**
 * jadwal_form.php — Tambah / Edit slot jadwal sebuah kelas (Admin).
 *   jadwal_form.php?kelas=3   -> tambah slot untuk kelas 3
 *   jadwal_form.php?id=7      -> edit slot 7
 * Ada pengecekan bentrok: kelas yang sama, ruangan yang sama, pengajar yang sama.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';

require_admin();

$hari_opsi = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

$id   = (int) ($_GET['id'] ?? 0);
$edit = $id > 0;

$d = ['hari' => 'Senin', 'jam_mulai' => '15:00', 'jam_selesai' => '16:30', 'ruangan' => ''];

if ($edit) {
    $stmt = $pdo->prepare('SELECT * FROM jadwal WHERE id_jadwal = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Slot jadwal tidak ditemukan.');
        redirect('jadwal.php');
    }
    $id_kelas = (int) $row['id_kelas'];
    $slot_lama = teks_slot($row);
    $d = [
        'hari'        => $row['hari'],
        'jam_mulai'   => substr($row['jam_mulai'], 0, 5),
        'jam_selesai' => substr($row['jam_selesai'], 0, 5),
        'ruangan'     => (string) ($row['ruangan'] ?? ''),
    ];
} else {
    $id_kelas = (int) ($_GET['kelas'] ?? 0);
}

// Data kelas induk
$stmt = $pdo->prepare(
    'SELECT k.id_kelas, k.nama_kelas, k.id_pengajar, k.status, m.nama_mapel, p.nama_lengkap AS pengajar
       FROM kelas k
       JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
  LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
      WHERE k.id_kelas = :id'
);
$stmt->execute([':id' => $id_kelas]);
$kelas = $stmt->fetch();
if (!$kelas) {
    flash_set('error', 'Kelas tidak ditemukan. Pilih kelas dari daftar.');
    redirect('jadwal.php');
}

// ---------------------------------------------------------------
// SIMPAN (POST)
// ---------------------------------------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Permintaan tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        if (!in_array($d['hari'], $hari_opsi, true)) {
            $errors[] = 'Hari tidak valid.';
        }
        if (!waktu_valid($d['jam_mulai']) || !waktu_valid($d['jam_selesai'])) {
            $errors[] = 'Jam mulai dan jam selesai wajib diisi (format JJ:MM).';
        } elseif ($d['jam_selesai'] <= $d['jam_mulai']) {
            $errors[] = 'Jam selesai harus lebih besar dari jam mulai.';
        }
        if (mb_strlen($d['ruangan']) > 50) {
            $errors[] = 'Nama ruangan maksimal 50 karakter.';
        }

        // Cek bentrok dengan slot lain di hari yang sama
        if (!$errors) {
            $st = $pdo->prepare(
                "SELECT j.hari, j.jam_mulai, j.jam_selesai, j.ruangan, j.id_kelas, k.nama_kelas, k.id_pengajar
                   FROM jadwal j
                   JOIN kelas k ON k.id_kelas = j.id_kelas
                  WHERE j.hari = :hari
                    AND j.jam_mulai   < :selesai
                    AND j.jam_selesai > :mulai
                    AND j.id_jadwal  <> :self
                    AND k.status = 'buka'"
            );
            $st->execute([
                ':hari' => $d['hari'], ':selesai' => $d['jam_selesai'] . ':00',
                ':mulai' => $d['jam_mulai'] . ':00', ':self' => $id,
            ]);

            foreach ($st->fetchAll() as $b) {
                $waktu  = substr($b['jam_mulai'], 0, 5) . '–' . substr($b['jam_selesai'], 0, 5);
                $alasan = [];
                if ((int) $b['id_kelas'] === $id_kelas) {
                    $alasan[] = 'kelas ini sudah punya jadwal di jam tersebut';
                }
                if ($d['ruangan'] !== '' && $b['ruangan'] !== null && strcasecmp($b['ruangan'], $d['ruangan']) === 0) {
                    $alasan[] = 'ruangan ' . $b['ruangan'] . ' sudah dipakai';
                }
                if ($kelas['id_pengajar'] !== null && (int) $b['id_pengajar'] === (int) $kelas['id_pengajar']
                    && (int) $b['id_kelas'] !== $id_kelas) {
                    $alasan[] = 'pengajar sudah mengajar di kelas lain';
                }
                if ($alasan) {
                    $errors[] = 'Bentrok dengan ' . $b['nama_kelas'] . ' (' . $waktu . '): ' . implode(', ', $alasan) . '.';
                }
            }
        }
    }

    if (!$errors) {
        $data = [
            ':hari' => $d['hari'], ':mulai' => $d['jam_mulai'] . ':00', ':selesai' => $d['jam_selesai'] . ':00',
            ':ruangan' => $d['ruangan'] === '' ? null : $d['ruangan'],
        ];
        try {
            if ($edit) {
                $data[':id'] = $id;
                $pdo->prepare(
                    'UPDATE jadwal SET hari = :hari, jam_mulai = :mulai, jam_selesai = :selesai, ruangan = :ruangan
                      WHERE id_jadwal = :id'
                )->execute($data);
            } else {
                $data[':kelas'] = $id_kelas;
                $pdo->prepare(
                    'INSERT INTO jadwal (id_kelas, hari, jam_mulai, jam_selesai, ruangan)
                     VALUES (:kelas, :hari, :mulai, :selesai, :ruangan)'
                )->execute($data);
            }
            $slot_baru = teks_slot(['hari' => $d['hari'], 'jam_mulai' => $d['jam_mulai'], 'jam_selesai' => $d['jam_selesai'], 'ruangan' => $d['ruangan']]);
            if (!$edit) {
                catat_perubahan_jadwal($pdo, $id_kelas, $kelas['nama_kelas'], 'Jadwal baru ditambahkan: ' . $slot_baru . '.', (int) current_user()['id_user']);
            } elseif ($slot_lama !== $slot_baru) {
                catat_perubahan_jadwal($pdo, $id_kelas, $kelas['nama_kelas'], 'Jadwal berubah dari ' . $slot_lama . ' menjadi ' . $slot_baru . '.', (int) current_user()['id_user']);
            }
            flash_set('success', 'Jadwal ' . $kelas['nama_kelas'] . ' berhasil ' . ($edit ? 'diperbarui.' : 'ditambahkan.'));
            redirect('jadwal.php');
        } catch (PDOException $ex) {
            $errors[] = APP_DEBUG ? 'Gagal menyimpan: ' . $ex->getMessage() : 'Gagal menyimpan data. Silakan coba lagi.';
        }
    }
}

// ---------------------------------------------------------------
// TAMPILAN
// ---------------------------------------------------------------
$judul_halaman = $edit ? 'Edit Jadwal' : 'Tambah Jadwal';
$sub_judul     = 'Atur hari, jam, dan ruangan pertemuan mingguan.';
$menu_aktif    = 'jadwal';
include __DIR__ . '/header.php';
?>

<div class="info-strip">
    <div><span>Kelas</span> <strong><?= e($kelas['nama_kelas']) ?></strong></div>
    <div><span>Mapel</span> <strong><?= e($kelas['nama_mapel']) ?></strong></div>
    <div><span>Pengajar</span> <strong><?= e($kelas['pengajar'] ?? 'Belum ditentukan') ?></strong></div>
</div>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert" style="max-width:920px;margin-top:0">
        <strong>Jadwal belum bisa disimpan:</strong>
        <ul>
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="jadwal_form.php?<?= $edit ? 'id=' . $id : 'kelas=' . $id_kelas ?>" class="form-card" novalidate style="margin-top:20px">
    <?= csrf_field() ?>

    <div class="form-section">
        <h2 class="form-section__title">Waktu &amp; Tempat</h2>
        <p class="form-section__desc">Sistem akan memeriksa bentrok ruangan dan pengajar secara otomatis.</p>
        <div class="form-grid">
            <div class="field">
                <label for="hari">Hari</label>
                <select class="input" id="hari" name="hari">
                    <?php foreach ($hari_opsi as $h): ?>
                        <option value="<?= e($h) ?>" <?= $d['hari'] === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="ruangan">Ruangan</label>
                <input class="input" type="text" id="ruangan" name="ruangan" value="<?= e($d['ruangan']) ?>" maxlength="50" placeholder="Contoh: Ruang A">
            </div>
            <div class="field">
                <label for="jam_mulai">Jam Mulai</label>
                <input class="input" type="time" id="jam_mulai" name="jam_mulai" value="<?= e($d['jam_mulai']) ?>" required>
            </div>
            <div class="field">
                <label for="jam_selesai">Jam Selesai</label>
                <input class="input" type="time" id="jam_selesai" name="jam_selesai" value="<?= e($d['jam_selesai']) ?>" required>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="jadwal.php" class="btn">Batal</a>
        <button type="submit" class="btn btn--primary"><?= $edit ? 'Simpan Perubahan' : 'Simpan Jadwal' ?></button>
    </div>
</form>

<?php include __DIR__ . '/footer.php'; ?>
