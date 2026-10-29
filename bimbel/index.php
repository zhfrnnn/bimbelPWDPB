<?php
/**
 * index.php — pintu masuk aplikasi.
 * Sudah login -> ke dashboard sesuai peran, belum -> ke halaman login.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';

redirect(is_logged_in() ? halaman_beranda() : HALAMAN_LOGIN);
