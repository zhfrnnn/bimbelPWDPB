<?php
/**
 * header.php — pembuka layout halaman terproteksi.
 *
 * Variabel yang bisa diset SEBELUM include header.php:
 *   $judul_halaman  (string) judul halaman
 *   $sub_judul      (string) keterangan di bawah judul
 *   $menu_aktif     (string) kunci menu sidebar yang aktif
 *
 * Pasangan: footer.php (penutup layout).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_login();

$judul_halaman = $judul_halaman ?? 'Dashboard';
$sub_judul     = $sub_judul ?? '';
$menu_aktif    = $menu_aktif ?? '';
$user          = current_user();
$label_peran   = ['admin' => 'Admin', 'owner' => 'Owner', 'pengajar' => 'Pengajar', 'siswa' => 'Siswa'][$user['role']] ?? '';
$eyebrow       = $user['role'] === 'admin' ? 'Panel Admin' : ($user['role'] === 'owner' ? 'Panel Owner' : 'Portal ' . $label_peran);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($judul_halaman) ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="app" id="app">

    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="overlay" id="overlay"></div>

    <div class="app__main">
        <header class="topbar">
            <button type="button" class="topbar__menu" id="menuToggle" aria-label="Buka menu">
                <svg class="nav__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>

            <span class="topbar__date"><?= e(tanggal_indo()) ?></span>

            <div class="userchip">
                <div class="userchip__text">
                    <div class="userchip__name"><?= e($user['nama']) ?></div>
                    <div class="userchip__role"><?= e($label_peran) ?></div>
                </div>
                <div class="userchip__avatar"><?= e(inisial($user['nama'])) ?></div>
            </div>
        </header>

        <main class="content">
            <div class="page-head">
                <p class="eyebrow"><?= e($eyebrow) ?></p>
                <h1><?= e($judul_halaman) ?></h1>
                <?php if ($sub_judul !== ''): ?>
                    <p class="muted"><?= e($sub_judul) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($flash = flash_ambil()): ?>
                <div class="flash">
                    <?php foreach ($flash as $f): ?>
                        <div class="alert alert--<?= e($f['tipe']) ?>" role="status"><?= e($f['pesan']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
