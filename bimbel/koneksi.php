<?php
/**
 * koneksi.php
 * Koneksi database (PDO) untuk Sistem Informasi Bimbel.
 *
 * Cara pakai di file lain:
 *   require_once __DIR__ . '/koneksi.php';
 *   $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u');
 *   $stmt->execute([':u' => $username]);
 */

// ---------------------------------------------------------------
// Konfigurasi (sesuaikan dengan server lokal / hosting)
// ---------------------------------------------------------------
define('DB_HOST',    'localhost');
define('DB_NAME',    'db_bimbel');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// Nama aplikasi & zona waktu
define('APP_NAME', 'Bimbel Tatap Muka');
date_default_timezone_set('Asia/Jakarta');

// Ubah ke false saat sudah dipublikasikan
define('APP_DEBUG', true);

// ---------------------------------------------------------------
// Cadangan bila ekstensi mbstring belum aktif di server
// ---------------------------------------------------------------
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return preg_match_all('/./us', (string) $s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null)
    {
        preg_match_all('/./us', (string) $s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $enc = null) { return strtoupper((string) $s); }
}

// ---------------------------------------------------------------
// Membuat koneksi PDO
// ---------------------------------------------------------------
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lempar exception saat error
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // hasil berupa array asosiatif
    PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared statement asli (aman dari SQL injection)
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    http_response_code(500);
    if (APP_DEBUG) {
        die('Koneksi database gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    die('Terjadi gangguan pada sistem. Silakan coba beberapa saat lagi.');
}

// ---------------------------------------------------------------
// Helper kecil yang dipakai di seluruh aplikasi
// ---------------------------------------------------------------

/** Escape output HTML agar aman dari XSS. */
function e($teks): string
{
    return htmlspecialchars((string) $teks, ENT_QUOTES, 'UTF-8');
}

/** Format angka ke Rupiah, contoh: Rp 150.000 */
function rupiah($angka): string
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}
