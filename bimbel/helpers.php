<?php
/**
 * helpers.php
 * Fungsi bantu tampilan & data yang dipakai di banyak halaman.
 * (Fungsi e() dan rupiah() sudah ada di koneksi.php)
 */

if (!function_exists('hari_indo')) {

    /** Nama hari dalam bahasa Indonesia: Senin ... Minggu */
    function hari_indo(?string $tgl = null): string
    {
        static $nama = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
        $ts = $tgl ? strtotime($tgl) : time();
        return $nama[(int) date('N', $ts)];
    }

    /** Nama bulan (1-12) dalam bahasa Indonesia. */
    function bulan_indo(int $bulan, bool $singkat = false): string
    {
        static $panjang = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                           'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $nama = $panjang[$bulan] ?? '';
        return $singkat ? mb_substr($nama, 0, 3) : $nama;
    }

    /** Contoh: Minggu, 18 Oktober 2026 */
    function tanggal_indo(?string $tgl = null, bool $denganHari = true): string
    {
        $ts   = $tgl ? strtotime($tgl) : time();
        $teks = date('j', $ts) . ' ' . bulan_indo((int) date('n', $ts)) . ' ' . date('Y', $ts);
        return $denganHari ? hari_indo($tgl) . ', ' . $teks : $teks;
    }

    /** Inisial 2 huruf dari nama, contoh: "Budi Santoso" -> "BS" */
    function inisial(string $nama): string
    {
        $kata = preg_split('/\s+/', trim($nama), -1, PREG_SPLIT_NO_EMPTY);
        if (!$kata) {
            return '?';
        }
        $hasil = mb_substr($kata[0], 0, 1);
        if (count($kata) > 1) {
            $hasil .= mb_substr(end($kata), 0, 1);
        }
        return mb_strtoupper($hasil);
    }

    /** Badge status berwarna untuk pendaftaran, pembayaran, kelas, absensi. */
    function badge_status(string $status): string
    {
        $positif  = ['aktif', 'lunas', 'buka', 'hadir', 'selesai', 'lulus'];
        $perhatian = ['pending', 'menunggu', 'menunggu_verifikasi'];
        $negatif  = ['belum_bayar', 'alpha', 'batal', 'nonaktif', 'tutup'];

        $kelas = '';
        if (in_array($status, $positif, true))      { $kelas = 'badge--blue'; }
        elseif (in_array($status, $perhatian, true)) { $kelas = 'badge--dark'; }
        elseif (in_array($status, $negatif, true))   { $kelas = 'badge--danger'; }

        $label = ucfirst(str_replace('_', ' ', $status));
        return '<span class="badge ' . $kelas . '">' . e($label) . '</span>';
    }

    /** Kembalikan nama file jika halamannya sudah ada, selain itu null. */
    function url_jika_ada(string $file): ?string
    {
        return file_exists(__DIR__ . '/' . $file) ? $file : null;
    }

    /** Ambil satu nilai (COUNT, SUM, dll) dari query. */
    function db_scalar(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    // -----------------------------------------------------------
    // Notifikasi (flash message) — tampil sekali setelah redirect
    // -----------------------------------------------------------
    function flash_set(string $tipe, string $pesan): void
    {
        $_SESSION['flash'][] = ['tipe' => $tipe, 'pesan' => $pesan];   // success | error | info
    }

    function flash_ambil(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }

    // -----------------------------------------------------------
    // URL, pagination, dan validasi
    // -----------------------------------------------------------

    /** Query string saat ini ($_GET) digabung dengan perubahan. Contoh: query_url(['page' => 2]) */
    function query_url(array $ubah = []): string
    {
        $p = array_filter(array_merge($_GET, $ubah), fn($v) => $v !== null && $v !== '');
        return $p ? '?' . http_build_query($p) : '';
    }

    /** HTML pagination sederhana (info jumlah data + tombol halaman). */
    function paginasi(int $total, int $per, int $halaman): string
    {
        if ($total === 0) {
            return '';
        }
        $jumlah = max(1, (int) ceil($total / $per));
        $dari   = ($halaman - 1) * $per + 1;
        $sampai = min($total, $halaman * $per);

        $html = '<div class="pagination"><p class="pagination__info">Menampilkan '
              . $dari . '&ndash;' . $sampai . ' dari ' . $total . ' data</p>';

        if ($jumlah > 1) {
            $link = fn(int $p, string $teks) =>
                '<a href="' . e(query_url(['page' => $p])) . '">' . $teks . '</a>';

            $html .= '<div class="pagination__links">';
            $html .= $halaman > 1 ? $link($halaman - 1, '&lsaquo;') : '<span class="is-disabled">&lsaquo;</span>';
            for ($i = max(1, $halaman - 2); $i <= min($jumlah, $halaman + 2); $i++) {
                $html .= $i === $halaman ? '<span class="is-current">' . $i . '</span>' : $link($i, (string) $i);
            }
            $html .= $halaman < $jumlah ? $link($halaman + 1, '&rsaquo;') : '<span class="is-disabled">&rsaquo;</span>';
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** Format YYYY-MM-DD yang benar-benar tanggal valid. */
    function tanggal_valid(string $s): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }

    /** Format jam HH:MM (00:00 - 23:59). */
    function waktu_valid(string $s): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
    }

    /** Tahun ajaran berjalan, contoh: 2026/2027 (tahun ajaran baru mulai Juli). */
    function tahun_ajaran_sekarang(): string
    {
        $y = (int) date('Y');
        return (int) date('n') >= 7 ? $y . '/' . ($y + 1) : ($y - 1) . '/' . $y;
    }

    // -----------------------------------------------------------
    // Pendaftaran & pembayaran (dipakai beberapa halaman)
    // -----------------------------------------------------------

    /** Jumlah kursi yang masih kosong di sebuah kelas (pending + aktif dihitung terisi). */
    function kuota_tersisa(PDO $pdo, int $id_kelas, int $kecuali_pendaftaran = 0): int
    {
        $kuota  = (int) db_scalar($pdo, 'SELECT kuota FROM kelas WHERE id_kelas = :id', [':id' => $id_kelas]);
        $terisi = (int) db_scalar($pdo,
            "SELECT COUNT(*) FROM pendaftaran
              WHERE id_kelas = :k AND status IN ('pending', 'aktif') AND id_pendaftaran <> :p",
            [':k' => $id_kelas, ':p' => $kecuali_pendaftaran]);
        return $kuota - $terisi;
    }

    /**
     * Buat tagihan SPP satu bulan untuk sebuah pendaftaran bila belum ada.
     * Mengembalikan true jika tagihan baru dibuat. Biaya kelas 0 tidak ditagih.
     */
    function buat_tagihan(PDO $pdo, int $id_pendaftaran, int $bulan, int $tahun): bool
    {
        $stmt = $pdo->prepare(
            'SELECT k.biaya_bulanan FROM pendaftaran pd JOIN kelas k ON k.id_kelas = pd.id_kelas
              WHERE pd.id_pendaftaran = :id'
        );
        $stmt->execute([':id' => $id_pendaftaran]);
        $biaya = $stmt->fetchColumn();
        if ($biaya === false || (float) $biaya <= 0) {
            return false;
        }

        $invoice = sprintf('INV-%04d%02d-%06d', $tahun, $bulan, $id_pendaftaran);
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO pembayaran (id_pendaftaran, no_invoice, periode_bulan, periode_tahun, jumlah, status)
             VALUES (:p, :inv, :b, :t, :jml, 'belum_bayar')"
        );
        $ins->execute([':p' => $id_pendaftaran, ':inv' => $invoice, ':b' => $bulan, ':t' => $tahun, ':jml' => $biaya]);
        return $ins->rowCount() > 0;
    }

    /** Daftar hari berurutan (sesuai ENUM tabel jadwal). */
    function daftar_hari(): array
    {
        return ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    }
}
