<?php
/**
 * api/index.php — REST API (JSON) untuk aplikasi mobile Siswa & Pengajar.
 *
 * Alamat dasar     : http://localhost/bimbel/api/            (dengan .htaccess / mod_rewrite)
 * Tanpa mod_rewrite: http://localhost/bimbel/api/index.php?r=auth/login
 * Autentikasi      : header  Authorization: Bearer <token>   (token dari POST auth/login)
 * Format           : request JSON (Content-Type: application/json), response JSON:
 *                    {"success": true, "data": ...}  atau  {"success": false, "error": "...", "errors": [...]}
 *
 * Aturan bisnis memakai fungsi yang sama dengan website (services.php),
 * jadi perilaku website dan aplikasi mobile selalu sama.
 * Dokumentasi lengkap endpoint: lihat API.md.
 */
define('API_MODE', true);

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services.php';

const API_TOKEN_HARI      = 30;   // masa berlaku token
const API_LOGIN_MAKS      = 5;    // maksimal login gagal per (IP + username)
const API_LOGIN_MENIT     = 5;    // dalam jendela waktu ini
const API_PER_HALAMAN     = 20;

// ---------------------------------------------------------------
// Utilitas response
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function api_kirim(int $kode, array $isi): never
{
    http_response_code($kode);
    echo json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function api_ok($data = null, int $kode = 200): never
{
    api_kirim($kode, ['success' => true, 'data' => $data]);
}

function api_gagal(string $pesan, int $kode = 400, array $errors = []): never
{
    $isi = ['success' => false, 'error' => $pesan];
    if ($errors) {
        $isi['errors'] = array_values($errors);
    }
    api_kirim($kode, $isi);
}

set_exception_handler(function (Throwable $ex) {
    api_gagal(APP_DEBUG ? 'Kesalahan server: ' . $ex->getMessage() : 'Terjadi gangguan pada sistem.', 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------
// Input
// ---------------------------------------------------------------
function api_input(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $mentah = file_get_contents('php://input', false, null, 0, 1048577);
    if ($mentah !== false && strlen($mentah) > 1048576) {
        api_gagal('Isi permintaan terlalu besar.', 413);
    }
    $data = [];
    if ($mentah !== false && trim($mentah) !== '') {
        $json = json_decode($mentah, true);
        if (is_array($json)) {
            $data = $json;
        } elseif (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            api_gagal('JSON tidak valid.', 400);
        }
    }
    if (!$data && $_POST) {
        $data = $_POST;
    }
    return $data;
}

function api_str(array $in, string $kunci): string
{
    $v = $in[$kunci] ?? '';
    return is_scalar($v) ? trim((string) $v) : '';
}

function api_halaman(): array
{
    $per    = min(50, max(1, (int) ($_GET['per'] ?? API_PER_HALAMAN)));
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    return [$per, $page, ($page - 1) * $per];
}

function api_meta(int $total, int $per, int $page): array
{
    return ['total' => $total, 'per_page' => $per, 'page' => $page, 'last_page' => max(1, (int) ceil($total / $per))];
}

// ---------------------------------------------------------------
// Token & autentikasi
// ---------------------------------------------------------------
function api_header_auth(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    return (string) $h;
}

/** Pengguna yang sedang login lewat token, atau hentikan dengan 401. */
function api_user(PDO $pdo, ?array $peran = null): array
{
    static $cache = null;
    if ($cache === null) {
        if (!preg_match('/^Bearer\s+([A-Za-z0-9]{64})$/', trim(api_header_auth()), $m)) {
            api_gagal('Token tidak ada. Login dahulu.', 401);
        }
        $st = $pdo->prepare(
            "SELECT u.id_user, u.username, u.role, u.status, t.id_token
               FROM api_token t JOIN users u ON u.id_user = t.id_user
              WHERE t.token_hash = :h AND t.expires_at > NOW()"
        );
        $st->execute([':h' => hash('sha256', $m[1])]);
        $u = $st->fetch();
        if (!$u || $u['status'] !== 'aktif') {
            api_gagal('Token tidak valid atau sudah kedaluwarsa. Login ulang.', 401);
        }
        $u['id_siswa'] = null;
        $u['id_pengajar'] = null;
        $u['nama'] = $u['username'];
        if ($u['role'] === 'siswa') {
            $q = $pdo->prepare('SELECT id_siswa, nama_lengkap, status FROM siswa WHERE id_user = :id');
            $q->execute([':id' => $u['id_user']]);
            if ($r = $q->fetch()) {
                $u['id_siswa'] = (int) $r['id_siswa'];
                $u['nama'] = $r['nama_lengkap'];
                $u['status_siswa'] = $r['status'];
            }
        } elseif ($u['role'] === 'pengajar') {
            $q = $pdo->prepare('SELECT id_pengajar, nama_lengkap FROM pengajar WHERE id_user = :id');
            $q->execute([':id' => $u['id_user']]);
            if ($r = $q->fetch()) {
                $u['id_pengajar'] = (int) $r['id_pengajar'];
                $u['nama'] = $r['nama_lengkap'];
            }
        }
        $cache = $u;
    }
    if ($peran !== null && !in_array($cache['role'], $peran, true)) {
        api_gagal('Akses ditolak untuk peran Anda.', 403);
    }
    return $cache;
}

function api_profil_user(array $u): array
{
    return [
        'id_user' => (int) $u['id_user'], 'username' => $u['username'], 'role' => $u['role'], 'nama' => $u['nama'],
        'id_siswa' => $u['id_siswa'], 'id_pengajar' => $u['id_pengajar'], 'status_siswa' => $u['status_siswa'] ?? null,
    ];
}

function api_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---------------------------------------------------------------
// Router
// ---------------------------------------------------------------
$jalur = $_GET['r'] ?? '';
if ($jalur === '' && !empty($_SERVER['PATH_INFO'])) {
    $jalur = $_SERVER['PATH_INFO'];
}
$jalur  = trim((string) $jalur, '/');
$metode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Cocokkan "siswa/kelas/{id}/daftar" dengan jalur; kembalikan parameter atau null. */
$cocok = function (string $pola, string $m) use ($jalur, $metode): ?array {
    if ($metode !== $m) {
        return null;
    }
    $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pola) . '$#';
    return preg_match($re, $jalur, $hasil) ? array_filter($hasil, 'is_string', ARRAY_FILTER_USE_KEY) : null;
};

// ===== Informasi umum ============================================
if ($jalur === '' && $metode === 'GET') {
    api_ok(['nama' => APP_NAME, 'versi' => '1.0', 'dokumentasi' => 'Lihat API.md']);
}

// ===== AUTENTIKASI ================================================
if ($cocok('auth/login', 'POST') !== null) {
    $in = api_input();
    $username = api_str($in, 'username');
    $password = is_scalar($in['password'] ?? null) ? (string) $in['password'] : '';
    if ($username === '' || $password === '') {
        api_gagal('Username dan password wajib diisi.', 422);
    }
    $ip = api_ip();

    $gagal = (int) db_scalar($pdo,
        'SELECT COUNT(*) FROM api_login_gagal WHERE ip = :ip AND username = :u AND waktu > DATE_SUB(NOW(), INTERVAL ' . API_LOGIN_MENIT . ' MINUTE)',
        [':ip' => $ip, ':u' => mb_substr($username, 0, 50)]);
    if ($gagal >= API_LOGIN_MAKS) {
        api_gagal('Terlalu banyak percobaan gagal. Coba lagi dalam ' . API_LOGIN_MENIT . ' menit.', 429);
    }

    $st = $pdo->prepare('SELECT id_user, username, password, role, status FROM users WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    $u = $st->fetch();

    // Hash pembanding supaya waktu respons relatif sama walau username tidak ada
    $hash = $u['password'] ?? '$2y$10$AwvcSawZaR.0wilQykK/kuGiHUGKpj9Dy2bbe16h9ld9rQtTOzfvi';
    if (!password_verify($password, $hash) || !$u) {
        $pdo->prepare('INSERT INTO api_login_gagal (ip, username) VALUES (:ip, :u)')->execute([':ip' => $ip, ':u' => mb_substr($username, 0, 50)]);
        $pdo->exec('DELETE FROM api_login_gagal WHERE waktu < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        api_gagal('Username atau password salah.', 401);
    }
    if ($u['status'] !== 'aktif') {
        api_gagal('Akun Anda dinonaktifkan. Hubungi admin.', 403);
    }
    if (!in_array($u['role'], ['siswa', 'pengajar'], true)) {
        api_gagal('Akun admin dan owner memakai website, bukan aplikasi mobile.', 403);
    }

    $pdo->prepare('DELETE FROM api_login_gagal WHERE ip = :ip AND username = :u')->execute([':ip' => $ip, ':u' => mb_substr($username, 0, 50)]);
    $pdo->exec('DELETE FROM api_token WHERE expires_at < NOW()');

    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO api_token (id_user, token_hash, perangkat, expires_at)
         VALUES (:u, :h, :p, DATE_ADD(NOW(), INTERVAL ' . API_TOKEN_HARI . ' DAY))'
    )->execute([':u' => $u['id_user'], ':h' => hash('sha256', $token), ':p' => mb_substr(api_str($in, 'perangkat'), 0, 100) ?: null]);
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id_user = :id')->execute([':id' => $u['id_user']]);

    // Ambil profil singkat untuk respons
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $profil = api_user($pdo);
    api_ok(['token' => $token, 'berlaku_hari' => API_TOKEN_HARI, 'user' => api_profil_user($profil)]);
}

if ($cocok('auth/register', 'POST') !== null) {
    $in = api_input();
    $d = [];
    foreach (field_registrasi_siswa() as $f) {
        $d[$f] = api_str($in, $f);
    }
    if ($d['jenis_kelamin'] === '') { $d['jenis_kelamin'] = 'L'; }
    if ($d['tingkat'] === '')       { $d['tingkat'] = 'SMA'; }
    $password = is_scalar($in['password'] ?? null) ? (string) $in['password'] : '';
    $kelas = is_array($in['kelas'] ?? null) ? array_map('intval', $in['kelas']) : [];

    $hasil = registrasi_siswa($pdo, $d, $password, $kelas);
    if ($hasil['errors']) {
        api_gagal('Registrasi gagal.', 422, $hasil['errors']);
    }
    api_ok([
        'id_siswa' => $hasil['id_siswa'],
        'status'   => 'menunggu',
        'pesan'    => 'Registrasi berhasil. Akun menunggu verifikasi admin.',
        'kelas_ditolak' => $hasil['kelas_ditolak'],
    ], 201);
}

if ($cocok('kelas', 'GET') !== null) {   // publik: kelas yang dibuka (untuk form registrasi)
    api_ok(kelas_terbuka($pdo));
}

// ===== Selebihnya wajib login =====================================
$user = api_user($pdo);

if ($cocok('auth/logout', 'POST') !== null) {
    preg_match('/^Bearer\s+([A-Za-z0-9]{64})$/', trim(api_header_auth()), $m);
    $pdo->prepare('DELETE FROM api_token WHERE token_hash = :h')->execute([':h' => hash('sha256', $m[1])]);
    api_ok(['pesan' => 'Berhasil keluar.']);
}

if ($cocok('me', 'GET') !== null) {
    api_ok(api_profil_user($user));
}

if ($cocok('auth/password', 'POST') !== null) {
    $in   = api_input();
    $lama = is_scalar($in['password_lama'] ?? null) ? (string) $in['password_lama'] : '';
    $baru = is_scalar($in['password_baru'] ?? null) ? (string) $in['password_baru'] : '';
    $hash = (string) db_scalar($pdo, 'SELECT password FROM users WHERE id_user = :id', [':id' => $user['id_user']]);
    if (!password_verify($lama, $hash)) {
        api_gagal('Password lama salah.', 422);
    }
    if (strlen($baru) < 6 || $baru === $lama) {
        api_gagal('Password baru minimal 6 karakter dan harus berbeda dari yang lama.', 422);
    }
    $pdo->prepare('UPDATE users SET password = :p WHERE id_user = :id')
        ->execute([':p' => password_hash($baru, PASSWORD_DEFAULT), ':id' => $user['id_user']]);
    // Cabut token lain agar perangkat lain harus login ulang
    preg_match('/^Bearer\s+([A-Za-z0-9]{64})$/', trim(api_header_auth()), $m);
    $pdo->prepare('DELETE FROM api_token WHERE id_user = :id AND token_hash <> :h')
        ->execute([':id' => $user['id_user'], ':h' => hash('sha256', $m[1])]);
    api_ok(['pesan' => 'Password berhasil diubah.']);
}

if ($cocok('pengumuman', 'GET') !== null) {
    api_user($pdo, ['siswa', 'pengajar']);
    [$per, $page, $off] = api_halaman();
    $id_profil = (int) ($user['role'] === 'siswa' ? $user['id_siswa'] : $user['id_pengajar']);
    api_ok([
        'items' => pengumuman_untuk($pdo, $user['role'], $id_profil, $per, $off),
        'meta'  => api_meta(jumlah_pengumuman_untuk($pdo, $user['role'], $id_profil), $per, $page),
    ]);
}

// ===== SISWA ======================================================
if (str_starts_with($jalur, 'siswa/')) {
    api_user($pdo, ['siswa']);
    $id_siswa = (int) $user['id_siswa'];

    if ($cocok('siswa/jadwal', 'GET') !== null) {
        $st = $pdo->prepare(
            "SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan,
                    k.id_kelas, k.nama_kelas, m.nama_mapel, p.nama_lengkap AS pengajar
               FROM pendaftaran pd
               JOIN kelas k          ON k.id_kelas = pd.id_kelas
               JOIN jadwal j         ON j.id_kelas = k.id_kelas
               JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
          LEFT JOIN pengajar p       ON p.id_pengajar = k.id_pengajar
              WHERE pd.id_siswa = :id AND pd.status = 'aktif' AND k.status = 'buka'
           ORDER BY FIELD(j.hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.jam_mulai"
        );
        $st->execute([':id' => $id_siswa]);
        api_ok($st->fetchAll());
    }

    if ($cocok('siswa/mapel', 'GET') !== null) {
        api_ok(kelas_terbuka($pdo, $id_siswa));
    }

    if (($p = $cocok('siswa/kelas/{id}/daftar', 'POST')) !== null) {
        [$ok, $pesan] = ajukan_kelas($pdo, $id_siswa, (int) $p['id']);
        $ok ? api_ok(['pesan' => $pesan], 201) : api_gagal($pesan, 422);
    }

    if ($cocok('siswa/pengajar', 'GET') !== null) {
        api_ok(info_pengajar_untuk_siswa($pdo, $id_siswa));
    }

    if ($cocok('siswa/absensi', 'GET') !== null) {
        [$per, $page, $off] = api_halaman();
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status='hadir'),0) AS hadir, COALESCE(SUM(status='izin'),0) AS izin,
                    COALESCE(SUM(status='sakit'),0) AS sakit, COALESCE(SUM(status='alpha'),0) AS alpha
               FROM absensi WHERE id_siswa = :id"
        );
        $st->execute([':id' => $id_siswa]);
        $rekap = array_map('intval', $st->fetch());
        $rekap['persen_hadir'] = $rekap['total'] > 0 ? (int) round($rekap['hadir'] / $rekap['total'] * 100) : null;

        $st = $pdo->prepare(
            "SELECT pt.tanggal, pt.pertemuan_ke, pt.materi, a.status, a.keterangan, k.nama_kelas, j.jam_mulai
               FROM absensi a
               JOIN pertemuan pt ON pt.id_pertemuan = a.id_pertemuan
               JOIN jadwal j     ON j.id_jadwal = pt.id_jadwal
               JOIN kelas k      ON k.id_kelas = j.id_kelas
              WHERE a.id_siswa = :id
           ORDER BY pt.tanggal DESC, j.jam_mulai DESC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':id', $id_siswa, PDO::PARAM_INT);
        $st->bindValue(':lim', $per, PDO::PARAM_INT);
        $st->bindValue(':off', $off, PDO::PARAM_INT);
        $st->execute();
        api_ok(['rekap' => $rekap, 'riwayat' => $st->fetchAll(), 'meta' => api_meta($rekap['total'], $per, $page)]);
    }

    if ($cocok('siswa/pembayaran', 'GET') !== null) {
        $st = $pdo->prepare(
            "SELECT pb.id_pembayaran, pb.no_invoice, pb.periode_bulan, pb.periode_tahun, pb.jumlah,
                    pb.tanggal_bayar, pb.metode, pb.status, k.nama_kelas
               FROM pembayaran pb
               JOIN pendaftaran pd ON pd.id_pendaftaran = pb.id_pendaftaran
               JOIN kelas k        ON k.id_kelas = pd.id_kelas
              WHERE pd.id_siswa = :s
           ORDER BY pb.periode_tahun DESC, pb.periode_bulan DESC, pb.id_pembayaran DESC"
        );
        $st->execute([':s' => $id_siswa]);
        api_ok($st->fetchAll());
    }

    if (($p = $cocok('siswa/pembayaran/{id}/lapor', 'POST')) !== null) {
        $metode = api_str(api_input(), 'metode');
        if (!in_array($metode, ['tunai', 'transfer', 'qris'], true)) {
            api_gagal('Metode harus tunai, transfer, atau qris.', 422);
        }
        lapor_bayar($pdo, $id_siswa, (int) $p['id'], $metode)
            ? api_ok(['pesan' => 'Laporan pembayaran terkirim. Admin akan memverifikasinya.'])
            : api_gagal('Tagihan tidak ditemukan atau sudah dilaporkan.', 404);
    }

    api_gagal('Endpoint tidak ditemukan.', 404);
}

// ===== PENGAJAR ===================================================
if (str_starts_with($jalur, 'pengajar/')) {
    api_user($pdo, ['pengajar']);
    $id_pengajar = (int) $user['id_pengajar'];

    if ($cocok('pengajar/jadwal', 'GET') !== null) {
        $st = $pdo->prepare(
            "SELECT j.id_jadwal, j.hari, j.jam_mulai, j.jam_selesai, j.ruangan, k.id_kelas, k.nama_kelas, m.nama_mapel,
                    (SELECT COUNT(*) FROM pendaftaran pd WHERE pd.id_kelas = k.id_kelas AND pd.status = 'aktif') AS jml_siswa
               FROM jadwal j
               JOIN kelas k          ON k.id_kelas = j.id_kelas
               JOIN mata_pelajaran m ON m.id_mapel = k.id_mapel
              WHERE k.id_pengajar = :id AND k.status = 'buka'
           ORDER BY FIELD(j.hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.jam_mulai"
        );
        $st->execute([':id' => $id_pengajar]);
        api_ok($st->fetchAll());
    }

    if ($cocok('pengajar/kelas', 'GET') !== null) {
        api_ok(kelas_pengajar($pdo, $id_pengajar));
    }

    if (($p = $cocok('pengajar/kelas/{id}/siswa', 'GET')) !== null) {
        $id_kelas = (int) $p['id'];
        if (!db_scalar($pdo, 'SELECT COUNT(*) FROM kelas WHERE id_kelas = :k AND id_pengajar = :p', [':k' => $id_kelas, ':p' => $id_pengajar])) {
            api_gagal('Kelas tidak ditemukan atau bukan kelas Anda.', 404);
        }
        api_ok(siswa_aktif_kelas($pdo, $id_kelas));
    }

    if ($cocok('pengajar/pertemuan', 'GET') !== null) {
        [$per, $page, $off] = api_halaman();
        $total = (int) db_scalar($pdo,
            'SELECT COUNT(*) FROM pertemuan pt JOIN jadwal j ON j.id_jadwal = pt.id_jadwal JOIN kelas k ON k.id_kelas = j.id_kelas WHERE k.id_pengajar = :p',
            [':p' => $id_pengajar]);
        api_ok(['items' => riwayat_pertemuan_pengajar($pdo, $id_pengajar, $per, $off), 'meta' => api_meta($total, $per, $page)]);
    }

    // Data form absensi: slot, siswa aktif, dan nilai yang sudah tersimpan
    if ($cocok('pengajar/absensi', 'GET') !== null) {
        $slot = slot_jadwal($pdo, (int) ($_GET['jadwal'] ?? 0));
        if (!$slot || (int) $slot['id_pengajar'] !== $id_pengajar) {
            api_gagal('Jadwal tidak ditemukan atau bukan kelas Anda.', 404);
        }
        $tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
        if (!tanggal_valid($tanggal)) {
            api_gagal('Tanggal tidak valid (format YYYY-MM-DD).', 422);
        }
        [$pertemuan, $nilai] = data_pertemuan($pdo, (int) $slot['id_jadwal'], $tanggal);
        $siswa = array_map(function ($s) use ($nilai) {
            $n = $nilai[(int) $s['id_siswa']] ?? ['hadir', ''];
            return $s + ['status' => $n[0], 'keterangan' => $n[1]];
        }, siswa_aktif_kelas($pdo, (int) $slot['id_kelas']));
        api_ok(['slot' => $slot, 'tanggal' => $tanggal, 'pertemuan' => $pertemuan, 'siswa' => $siswa,
                'batas_mundur_hari' => BATAS_HARI_ABSENSI_PENGAJAR]);
    }

    // Simpan absensi + catatan kegiatan belajar
    if ($cocok('pengajar/absensi', 'POST') !== null) {
        $in   = api_input();
        $slot = slot_jadwal($pdo, (int) ($in['id_jadwal'] ?? 0));
        if (!$slot || (int) $slot['id_pengajar'] !== $id_pengajar) {
            api_gagal('Jadwal tidak ditemukan atau bukan kelas Anda.', 404);
        }
        $tanggal = api_str($in, 'tanggal');

        // Ubah daftar siswa [{id_siswa, status, keterangan}] menjadi bentuk yang dipakai simpan_pertemuan()
        $status = $ket = [];
        foreach ((is_array($in['siswa'] ?? null) ? $in['siswa'] : []) as $baris) {
            if (is_array($baris) && isset($baris['id_siswa'])) {
                $sid = (int) $baris['id_siswa'];
                $status[$sid] = is_string($baris['status'] ?? null) ? $baris['status'] : 'alpha';
                $ket[$sid]    = is_string($baris['keterangan'] ?? null) ? $baris['keterangan'] : '';
            }
        }
        $data = [
            'pertemuan_ke' => api_str($in, 'pertemuan_ke'), 'kehadiran_pengajar' => api_str($in, 'kehadiran_pengajar') ?: 'hadir',
            'materi' => api_str($in, 'materi'), 'catatan' => api_str($in, 'catatan'), 'status' => $status, 'ket' => $ket,
        ];
        $hasil = simpan_pertemuan($pdo, $slot, $tanggal, $data, (int) $user['id_user'], BATAS_HARI_ABSENSI_PENGAJAR);
        if ($hasil['errors']) {
            api_gagal('Absensi gagal disimpan.', 422, $hasil['errors']);
        }
        api_ok(['id_pertemuan' => $hasil['id_pertemuan'], 'pesan' => 'Absensi dan catatan tersimpan.']);
    }

    api_gagal('Endpoint tidak ditemukan.', 404);
}

api_gagal('Endpoint tidak ditemukan.', 404);
