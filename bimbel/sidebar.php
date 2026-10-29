<?php
/**
 * sidebar.php — menu samping, otomatis menyesuaikan peran (Admin / User).
 * Menu yang halamannya belum dibuat tampil nonaktif dengan label "Segera".
 * Dipanggil dari header.php (memakai $user dan $menu_aktif).
 */

// Ikon bergaya siku (tanpa lengkung) — path untuk viewBox 24x24
$ikon = [
    'dashboard'   => 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z',
    'siswa'       => 'M9 4h6v6H9zM4 20v-5h16v5',
    'pengajar'    => 'M3 4h18v12H3zM8 20l4-4 4 4',
    'kalender'    => 'M3 5h18v16H3zM3 10h18M8 3v4M16 3v4',
    'pendaftaran' => 'M6 4h12v17H6zM9 2h6v4H9zM9 11h6M9 15h6',
    'absensi'     => 'M4 4h16v16H4zM8 12l3 3 5-6',
    'pembayaran'  => 'M3 6h18v12H3zM3 10h18M7 15h4',
    'mapel'       => 'M5 3h12a2 2 0 0 1 2 2v16H7a2 2 0 0 1-2-2zM5 19a2 2 0 0 1 2-2h12M9 7h6',
    'laporan'     => 'M4 3h16v18H4zM8 17v-4M12 17V8M16 17v-6',
    'pengumuman'  => 'M3 9h4l9-5v16l-9-5H3zM7 15l1 5h3l-1-5',
    'catat'       => 'M4 4h16v16H4zM8 9h8M8 13h8M8 17h4',
    'akun'        => 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z',
    'keluar'      => 'M10 4H4v16h6M14 8l4 4-4 4M18 12H9',
];

// Struktur menu: [grup => [[kunci, label, file, ikon], ...]]
// File boleh memuat query string (contoh: laporan.php?jenis=bulanan).
$menu_laporan = [
    ['laporan_bulanan',     'Laporan Bulanan',     'laporan.php?jenis=bulanan',     'laporan'],
    ['laporan_pendaftaran', 'Laporan Pendaftaran', 'laporan.php?jenis=pendaftaran', 'laporan'],
    ['laporan_pembayaran',  'Laporan Pembayaran',  'laporan.php?jenis=pembayaran',  'laporan'],
];

if ($user['role'] === 'admin') {
    $menu = [
        'Utama' => [
            ['dashboard', 'Dashboard', 'dashboard_admin.php', 'dashboard'],
        ],
        'Data Master' => [
            ['siswa',    'Data Siswa',      'siswa.php',    'siswa'],
            ['pengajar', 'Data Pengajar',   'pengajar.php', 'pengajar'],
            ['mapel',    'Mata Pelajaran',  'mapel.php',    'mapel'],
        ],
        'Akademik' => [
            ['jadwal',      'Kelas & Jadwal', 'jadwal.php',      'kalender'],
            ['pendaftaran', 'Pendaftaran',    'pendaftaran.php', 'pendaftaran'],
            ['absensi',     'Absensi',        'absensi.php',     'absensi'],
            ['pengumuman',  'Pengumuman',     'pengumuman.php',  'pengumuman'],
        ],
        'Keuangan' => [
            ['pembayaran', 'Pembayaran', 'pembayaran.php', 'pembayaran'],
        ],
        'Laporan' => $menu_laporan,
    ];
} elseif ($user['role'] === 'owner') {
    $menu = [
        'Utama'   => [['dashboard', 'Dashboard', 'dashboard_owner.php', 'dashboard']],
        'Laporan' => $menu_laporan,
    ];
} elseif ($user['role'] === 'pengajar') {
    $menu = [
        'Utama' => [
            ['dashboard', 'Dashboard', 'dashboard_user.php', 'dashboard'],
        ],
        'Mengajar' => [
            ['jadwal',       'Jadwal Mengajar',   'jadwal_user.php',   'kalender'],
            ['catat',        'Catat Kehadiran',   'absensi_catat.php', 'catat'],
            ['absensi',      'Riwayat Pertemuan', 'absensi_user.php',  'absensi'],
            ['siswa_kelas',  'Daftar Siswa',      'siswa_kelas.php',   'siswa'],
            ['pengumuman',   'Pengumuman',        'pengumuman_user.php', 'pengumuman'],
        ],
    ];
} else {
    $menu = [
        'Utama' => [
            ['dashboard', 'Dashboard', 'dashboard_user.php', 'dashboard'],
        ],
        'Akademik' => [
            ['jadwal',        'Jadwal Belajar',   'jadwal_user.php',  'kalender'],
            ['mapel',         'Mata Pelajaran',   'mapel_user.php',   'mapel'],
            ['pengajar_info', 'Info Pengajar',    'pengajar_info.php', 'pengajar'],
            ['absensi',       'Riwayat Kehadiran', 'absensi_user.php', 'absensi'],
            ['pengumuman',    'Pengumuman',       'pengumuman_user.php', 'pengumuman'],
        ],
        'Keuangan' => [
            ['pembayaran', 'Pembayaran', 'pembayaran_user.php', 'pembayaran'],
        ],
    ];
}

// Menu akun untuk semua peran
$menu['Akun'] = [
    ['profil', 'Akun Saya', 'profil.php', 'akun'],
];

$svg = function (string $nama) use ($ikon): string {
    return '<svg class="nav__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $ikon[$nama] . '"/></svg>';
};
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
        <div class="brand">
            <span class="brand__mark">B</span>
            <span class="brand__text">Bimbel<small>Tatap Muka</small></span>
        </div>
    </div>

    <nav class="nav" aria-label="Menu utama">
        <?php foreach ($menu as $grup => $items): ?>
            <p class="nav__label"><?= e($grup) ?></p>
            <?php foreach ($items as [$kunci, $label, $file, $ic]): ?>
                <?php if (url_jika_ada(strtok($file, '?'))): ?>
                    <a href="<?= e($file) ?>" title="<?= e($label) ?>" class="nav__item<?= $menu_aktif === $kunci ? ' is-active' : '' ?>"
                       <?= $menu_aktif === $kunci ? 'aria-current="page"' : '' ?>>
                        <?= $svg($ic) ?><span><?= e($label) ?></span>
                    </a>
                <?php else: ?>
                    <span class="nav__item is-disabled" aria-disabled="true">
                        <?= $svg($ic) ?><span><?= e($label) ?></span><em>Segera</em>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__foot">
        <a href="logout.php" title="Keluar" class="nav__item">
            <?= $svg('keluar') ?><span>Keluar</span>
        </a>
    </div>
</aside>
