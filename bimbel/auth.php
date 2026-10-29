<?php
/**
 * auth.php
 * Logika sesi & pembagian akses: Admin, Owner, Pengajar, Siswa.
 *
 * Cara pakai di halaman lain:
 *   require_once __DIR__ . '/koneksi.php';
 *   require_once __DIR__ . '/auth.php';
 *   require_admin();   // khusus Admin
 *   require_user();    // khusus Pengajar & Siswa
 */

// ---------------------------------------------------------------
// Konfigurasi sesi
// ---------------------------------------------------------------
define('SESSION_TIMEOUT', 7200);      // sesi habis setelah 2 jam tidak aktif
define('LOGIN_MAX_GAGAL', 5);         // maksimal percobaan login gagal
define('LOGIN_LOCK_DETIK', 300);      // kunci login selama 5 menit

define('HALAMAN_ADMIN', 'dashboard_admin.php');
define('HALAMAN_OWNER', 'dashboard_owner.php');
define('HALAMAN_USER',  'dashboard_user.php');
define('HALAMAN_LOGIN', 'login.php');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('BIMBELSESS');
    session_start();
}

// ---------------------------------------------------------------
// Helper umum
// ---------------------------------------------------------------
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------
// Status login
// ---------------------------------------------------------------
function session_expired(): bool
{
    return !empty($_SESSION['user_id'])
        && isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT;
}

function is_logged_in(): bool
{
    if (empty($_SESSION['user_id']) || session_expired()) {
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_admin(): bool
{
    return is_logged_in() && current_role() === 'admin';
}

function is_owner(): bool
{
    return is_logged_in() && current_role() === 'owner';
}

function is_user(): bool
{
    return is_logged_in() && in_array(current_role(), ['pengajar', 'siswa'], true);
}

/** Data user yang sedang login (untuk header / sidebar). */
function current_user(): array
{
    return [
        'id_user'     => $_SESSION['user_id']     ?? null,
        'username'    => $_SESSION['username']    ?? '',
        'role'        => $_SESSION['role']        ?? '',
        'nama'        => $_SESSION['nama']        ?? '',
        'id_siswa'    => $_SESSION['id_siswa']    ?? null,
        'id_pengajar' => $_SESSION['id_pengajar'] ?? null,
    ];
}

function halaman_beranda(?string $role = null): string
{
    return match ($role ?? current_role()) {
        'admin' => HALAMAN_ADMIN,
        'owner' => HALAMAN_OWNER,
        default => HALAMAN_USER,
    };
}

function redirect_by_role(): void
{
    redirect(halaman_beranda());
}

// ---------------------------------------------------------------
// Proses login / logout
// ---------------------------------------------------------------

/** Simpan data user ke session setelah kredensial terverifikasi. */
function login_user(array $user, array $profil = []): void
{
    session_regenerate_id(true);   // cegah session fixation

    $_SESSION['user_id']       = (int) $user['id_user'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['nama']          = $profil['nama'] ?? $user['username'];
    $_SESSION['id_siswa']      = $profil['id_siswa'] ?? null;
    $_SESSION['id_pengajar']   = $profil['id_pengajar'] ?? null;
    $_SESSION['last_activity'] = time();

    unset($_SESSION['login_gagal'], $_SESSION['login_kunci_sampai']);
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------
// Pembatasan percobaan login (berbasis sesi)
// ---------------------------------------------------------------
function login_sisa_kunci(): int
{
    $sampai = $_SESSION['login_kunci_sampai'] ?? 0;
    return max(0, $sampai - time());
}

function login_catat_gagal(): void
{
    $_SESSION['login_gagal'] = ($_SESSION['login_gagal'] ?? 0) + 1;
    if ($_SESSION['login_gagal'] >= LOGIN_MAX_GAGAL) {
        $_SESSION['login_kunci_sampai'] = time() + LOGIN_LOCK_DETIK;
        $_SESSION['login_gagal'] = 0;
    }
}

// ---------------------------------------------------------------
// Penjaga halaman (taruh di baris atas setiap halaman terproteksi)
// ---------------------------------------------------------------
function require_login(): void
{
    if (!is_logged_in()) {
        $kedaluwarsa = session_expired();
        logout_session();
        redirect(HALAMAN_LOGIN . ($kedaluwarsa ? '?expired=1' : ''));
    }
}

/** Khusus Admin. Peran lain dialihkan ke beranda masing-masing. */
function require_admin(): void
{
    require_login();
    if (current_role() !== 'admin') {
        redirect(halaman_beranda());
    }
}

/** Khusus Owner (pemilik bimbel). */
function require_owner(): void
{
    require_login();
    if (current_role() !== 'owner') {
        redirect(halaman_beranda());
    }
}

/** Halaman yang boleh dibuka Admin dan Owner (contoh: laporan). */
function require_admin_atau_owner(): void
{
    require_login();
    if (!in_array(current_role(), ['admin', 'owner'], true)) {
        redirect(halaman_beranda());
    }
}

/** Khusus Pengajar & Siswa. Admin/Owner dialihkan ke beranda masing-masing. */
function require_user(): void
{
    require_login();
    if (!in_array(current_role(), ['pengajar', 'siswa'], true)) {
        redirect(halaman_beranda());
    }
}

/** Khusus Pengajar. */
function require_pengajar(): void
{
    require_login();
    if (current_role() !== 'pengajar') {
        redirect(halaman_beranda());
    }
}

/** Khusus Siswa. */
function require_siswa(): void
{
    require_login();
    if (current_role() !== 'siswa') {
        redirect(halaman_beranda());
    }
}
