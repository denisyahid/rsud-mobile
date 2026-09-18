<?php
// ============================================================================
// admin-config.php — Konfigurasi PANEL ADMIN konten "Informasi" RSUD Malangbong
// ----------------------------------------------------------------------------
// PENTING: database admin ini TERPISAH dari database SIMRS yang dipakai api.php.
//   • api.php       → PostgreSQL SIMRS (rsud_malangbong @ 192.168.22.81:5792)
//   • file ini      → MySQL/MariaDB    (admin_info_rsudmobile @ 192.168.22.251)
//
// Dipakai oleh:
//   • admin.php      → panel CRUD + upload gambar (wajib login)
//   • informasi.php  → halaman publik yang tampil di tab "Informasi" aplikasi
//
// Semua nilai bisa ditimpa lewat environment variable, jadi kredensial tidak
// wajib ditulis di dalam file saat produksi.
// ============================================================================

date_default_timezone_set('Asia/Jakarta');

// ---------------------------------------------------------------------------
// 1. KONEKSI DATABASE ADMIN (MySQL / MariaDB)
// ---------------------------------------------------------------------------
$ADMIN_DB_CONFIG = [
    'driver'  => getenv('RSUD_ADMIN_DB_DRIVER') ?: 'mysql',            // mysql | sqlite (sqlite khusus uji lokal)
    'host'    => getenv('RSUD_ADMIN_DB_HOST')   ?: '192.168.22.251',   // kalau PHP jalan di mesin itu sendiri boleh 'localhost'
    'port'    => (int) (getenv('RSUD_ADMIN_DB_PORT') ?: 3306),
    'name'    => getenv('RSUD_ADMIN_DB_NAME')   ?: 'admin_info_rsudmobile',
    'user'    => getenv('RSUD_ADMIN_DB_USER')   ?: 'rsudmalangbong',
    'pass'    => getenv('RSUD_ADMIN_DB_PASS')   ?: 'garutKAB@2024',
    'charset' => 'utf8mb4',
    // Hanya dipakai bila driver = sqlite (mode uji coba tanpa server MySQL)
    'sqlite_file' => getenv('RSUD_ADMIN_SQLITE_FILE') ?: (__DIR__ . '/data/admin_info_rsudmobile.sqlite'),
];

// ---------------------------------------------------------------------------
// 2. PATH & URL
// ---------------------------------------------------------------------------
define('ADMIN_BASE_DIR',   __DIR__);
define('ADMIN_UPLOAD_DIR', getenv('RSUD_ADMIN_UPLOAD_DIR') ?: (__DIR__ . '/uploads'));
define('ADMIN_UPLOAD_URL', 'uploads');        // relatif terhadap folder backend/
define('ADMIN_DATA_DIR',   __DIR__ . '/data');

// ---------------------------------------------------------------------------
// 3. ATURAN UPLOAD GAMBAR
// ---------------------------------------------------------------------------
define('ADMIN_MAX_UPLOAD_BYTES', (int) (getenv('RSUD_ADMIN_MAX_UPLOAD') ?: 5242880)); // 5 MB
define('ADMIN_MAX_IMAGE_WIDTH',  1600);   // gambar besar diperkecil otomatis (hemat kuota pasien)
define('ADMIN_MAX_IMAGE_HEIGHT', 1600);
define('ADMIN_JPEG_QUALITY',     82);
define('ADMIN_WEBP_QUALITY',     82);

// mime asli (dibaca dari ISI file, bukan dari nama file) => ekstensi simpan
$ADMIN_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

// ---------------------------------------------------------------------------
// 4. KEAMANAN PANEL
// ---------------------------------------------------------------------------
define('ADMIN_SESSION_NAME',    'RSUDADMINSESS');
define('ADMIN_MAX_LOGIN_GAGAL', 5);    // percobaan gagal sebelum akun dikunci
define('ADMIN_MENIT_TERKUNCI',  15);   // lama penguncian (menit)
define('ADMIN_SESSION_IDLE',    7200); // logout otomatis bila diam 2 jam

// ---------------------------------------------------------------------------
// 5. PENGATURAN DEFAULT halaman informasi.php
//    (nilai di tabel `pengaturan` akan menimpa default ini)
// ---------------------------------------------------------------------------
function adminDefaultSettings()
{
    return [
        // Identitas & tampilan
        'app_nama'            => 'RSUD Malangbong',
        'app_subjudul'        => 'Informasi & Layanan Rumah Sakit',
        'tampilkan_header'    => '0',      // 0 = header hijau disembunyikan (sesuai permintaan)
        'warna_utama'         => '#1b5e20',
        'tampilkan_slider'    => '1',
        'slider_autoplay'     => '1',
        'slider_interval'     => '5000',   // ms
        // Judul tiap seksi
        'info_judul_seksi'    => 'Informasi & Pengumuman',
        'tarif_judul_seksi'   => 'Tarif Layanan',
        'panduan_judul_seksi' => 'Panduan Pemakaian Aplikasi',
        'kontak_judul_seksi'  => 'Kontak & Layanan',
        'tampilkan_tarif'     => '1',
        'tampilkan_panduan'   => '1',
        'panduan_buka_satu'   => '1',      // hanya satu dropdown terbuka dalam satu waktu
        'tampilkan_kontak'    => '1',
        'tarif_catatan'       => 'Tarif dapat berubah sewaktu-waktu sesuai peraturan yang berlaku. Pastikan konfirmasi ke petugas bila membutuhkan rincian biaya.',
        // Kontak
        'alamat'              => 'Jl. Raya Malangbong, Kab. Garut, Jawa Barat',
        'jam_layanan'         => '24 Jam / 7 Hari',
        'telepon'             => '',
        'whatsapp'            => '6281385831193',
        'email'               => 'rsudmalangbonggarut@gmail.com',
        'website'             => 'https://rsud-malangbong.garutkab.go.id',
        'maps'                => '',
        // Footer
        'footer_teks'         => 'RSUD Malangbong — Kabupaten Garut, Jawa Barat',
        // Pesan bila data kosong
        'pesan_kosong_info'   => 'Belum ada informasi terbaru. Silakan cek kembali nanti.',
        'pesan_kosong_slide'  => '',
        'pesan_kosong_tarif'  => 'Daftar tarif sedang diperbarui. Silakan hubungi petugas untuk informasi biaya.',
    ];
}

// ===========================================================================
// HELPER UMUM
// ===========================================================================

/** Escape untuk output HTML. */
function e($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape untuk ditaruh di dalam atribut URL (link). */
function eUrl($value)
{
    $v = trim((string) ($value ?? ''));
    if ($v === '') return '';
    // blokir javascript: / data: berbahaya
    if (preg_match('/^\s*(javascript|data|vbscript|file)\s*:/i', $v)) return '#';
    return e($v);
}

/** Tanggal+jam sekarang format MySQL/DATETIME. */
function adminNow()
{
    return date('Y-m-d H:i:s');
}

/** Format tanggal "Y-m-d" / datetime → "17 September 2026". */
function adminTanggalIndo($tanggal)
{
    if (empty($tanggal)) return '';
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
              'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime((string) $tanggal);
    if ($ts === false) return (string) $tanggal;
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Ukuran file jadi mudah dibaca. */
function adminFormatBytes($bytes)
{
    $bytes = (float) $bytes;
    $unit  = ['B', 'KB', 'MB', 'GB'];
    $i     = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $i     = min($i, count($unit) - 1);
    return round($bytes / pow(1024, $i), $i === 0 ? 0 : 1) . ' ' . $unit[$i];
}

/**
 * Angka jadi rupiah: 1250000 → "Rp 1.250.000".
 * @param int|string $nilai
 * @param bool       $pakaiRp sertakan awalan "Rp "
 */
function adminFormatRupiah($nilai, $pakaiRp = true)
{
    $nominal = (int) preg_replace('/[^0-9]/', '', (string) $nilai);
    $teks    = number_format(max(0, $nominal), 0, ',', '.');
    return $pakaiRp ? 'Rp ' . $teks : $teks;
}

/** Ambil angka dari teks bebas: "Rp 1.250.000" → 1250000. */
function adminAngkaDariTeks($teks)
{
    $bersih = preg_replace('/[^0-9]/', '', (string) $teks) ?? '';
    return $bersih === '' ? 0 : min(999999999, (int) $bersih);
}

/** Nama file aman dari teks (untuk nama file gambar). */
function adminSlug($teks, $max = 40)
{
    $teks = strtolower(trim((string) $teks));
    $teks = preg_replace('/[^a-z0-9]+/', '-', $teks) ?? '';
    $teks = trim($teks, '-');
    if ($teks === '') $teks = 'berkas';
    return substr($teks, 0, $max);
}

// ===========================================================================
// KONEKSI DATABASE
// ===========================================================================

/**
 * Koneksi PDO tunggal (singleton) ke database admin.
 * Melempar PDOException bila gagal — pemanggil yang memutuskan mau fallback
 * (informasi.php) atau menampilkan pesan error (admin.php).
 */
function adminPdo()
{
    global $ADMIN_DB_CONFIG;
    static $pdo = null;
    static $cfgKey = null;
    static $gagal = null;   // simpan error agar tidak mencoba koneksi berulang-ulang

    $key = json_encode($ADMIN_DB_CONFIG);
    if ($pdo instanceof PDO && $cfgKey === $key) return $pdo;
    if ($gagal instanceof Throwable && $cfgKey === $key) throw $gagal;

    $driver = strtolower((string) $ADMIN_DB_CONFIG['driver']);

    if ($driver === 'sqlite') {
        $file = $ADMIN_DB_CONFIG['sqlite_file'];
        $dir  = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dsn = 'sqlite:' . $file;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $ADMIN_DB_CONFIG['host'],
            (int) $ADMIN_DB_CONFIG['port'],
            $ADMIN_DB_CONFIG['name'],
            $ADMIN_DB_CONFIG['charset']
        );
        $opsi = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 6,   // jangan menggantung lama saat DB mati
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $opsi[PDO::MYSQL_ATTR_INIT_COMMAND] =
                'SET NAMES ' . $ADMIN_DB_CONFIG['charset'] . ' COLLATE ' . $ADMIN_DB_CONFIG['charset'] . '_unicode_ci';
        }
        try {
            $pdo = new PDO($dsn, $ADMIN_DB_CONFIG['user'], $ADMIN_DB_CONFIG['pass'], $opsi);
        } catch (Throwable $ex) {
            $cfgKey = $key;
            $gagal  = $ex;
            throw $ex;
        }
    }

    $cfgKey = $key;
    $gagal  = null;
    return $pdo;
}

/** Status koneksi DB dalam bentuk array (untuk panel "Cek Sistem"). */
function adminDbStatus()
{
    global $ADMIN_DB_CONFIG;
    try {
        adminPdo();
        return ['ok' => true, 'pesan' => 'Koneksi database admin berhasil.'];
    } catch (Throwable $ex) {
        return [
            'ok'    => false,
            'pesan' => 'Gagal koneksi ke database admin: ' . $ex->getMessage(),
            'target' => sprintf(
                '%s://%s@%s:%d/%s',
                $ADMIN_DB_CONFIG['driver'],
                $ADMIN_DB_CONFIG['user'],
                $ADMIN_DB_CONFIG['driver'] === 'sqlite' ? '-' : $ADMIN_DB_CONFIG['host'],
                (int) $ADMIN_DB_CONFIG['port'],
                $ADMIN_DB_CONFIG['name']
            ),
        ];
    }
}

/** Jalankan query dengan parameter aman. */
function adminQ($sql, array $params = [])
{
    $stmt = adminPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Ambil banyak baris. */
function adminAll($sql, array $params = [])
{
    return adminQ($sql, $params)->fetchAll();
}

/** Ambil satu baris (atau null). */
function adminOne($sql, array $params = [])
{
    $row = adminQ($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Ambil satu nilai kolom pertama. */
function adminValue($sql, array $params = [], $default = null)
{
    $row = adminQ($sql, $params)->fetch(PDO::FETCH_NUM);
    return ($row === false || !isset($row[0]) || $row[0] === null) ? $default : $row[0];
}

/** Cek apakah sebuah tabel sudah ada di database admin. */
function adminTableExists($table)
{
    global $ADMIN_DB_CONFIG;
    $table = preg_replace('/[^a-z0-9_]/i', '', (string) $table);
    try {
        if (strtolower((string) $ADMIN_DB_CONFIG['driver']) === 'sqlite') {
            $n = (int) adminValue(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?",
                [$table],
                0
            );
        } else {
            $n = (int) adminValue(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
                0
            );
        }
        return $n > 0;
    } catch (Throwable $ex) {
        return false;
    }
}

// ===========================================================================
// PENGATURAN (tabel `pengaturan`, key-value)
// ===========================================================================

/**
 * Semua pengaturan: nilai dari database ditumpuk di atas nilai default,
 * sehingga halaman tetap jalan walau tabel belum diisi / DB mati.
 */
function adminSettings()
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    $cache = adminDefaultSettings();
    try {
        if (adminTableExists('pengaturan')) {
            foreach (adminAll('SELECT kunci, nilai FROM pengaturan') as $row) {
                $cache[$row['kunci']] = (string) $row['nilai'];
            }
        }
    } catch (Throwable $ex) {
        // biarkan default dipakai
    }
    return $cache;
}

/** Satu nilai pengaturan. */
function adminSetting($kunci, $default = '')
{
    $s = adminSettings();
    if (array_key_exists($kunci, $s) && $s[$kunci] !== '') return $s[$kunci];
    if (array_key_exists($kunci, $s)) return $s[$kunci];
    return $default;
}

/** Pengaturan bernilai boolean (0/1). */
function adminSettingBool($kunci, $default = false)
{
    $s = adminSettings();
    $v = array_key_exists($kunci, $s) ? $s[$kunci] : ($default ? '1' : '0');
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'ya', 'y', 'on'], true);
}

// ===========================================================================
// SKEMA DATABASE (dipakai installer otomatis di admin.php dan file .sql)
// ===========================================================================

/**
 * Daftar pernyataan CREATE TABLE IF NOT EXISTS — idempoten (aman dijalankan
 * berulang kali). Isi file backend/sql/admin_info_rsudmobile.sql setara dengan
 * daftar ini untuk MySQL.
 */
function adminSchemaStatements($driver = 'mysql')
{
    $driver = strtolower((string) $driver);

    if ($driver === 'sqlite') {
        return [
            "CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                nama_lengkap TEXT DEFAULT '',
                email TEXT DEFAULT '',
                role TEXT NOT NULL DEFAULT 'admin',
                status_aktif INTEGER NOT NULL DEFAULT 1,
                login_gagal INTEGER NOT NULL DEFAULT 0,
                terkunci_sampai TEXT DEFAULT NULL,
                terakhir_login TEXT DEFAULT NULL,
                dibuat_pada TEXT NOT NULL,
                diperbarui_pada TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS pengaturan (
                kunci TEXT PRIMARY KEY,
                nilai TEXT,
                label TEXT DEFAULT '',
                kelompok TEXT DEFAULT 'umum',
                tipe TEXT DEFAULT 'text',
                diperbarui_pada TEXT DEFAULT NULL
            )",
            "CREATE TABLE IF NOT EXISTS slide (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                judul TEXT NOT NULL,
                subjudul TEXT DEFAULT '',
                gambar TEXT DEFAULT '',
                tautan TEXT DEFAULT '',
                warna_latar TEXT DEFAULT '',
                urutan INTEGER NOT NULL DEFAULT 0,
                status_aktif INTEGER NOT NULL DEFAULT 1,
                dibuat_pada TEXT NOT NULL,
                diperbarui_pada TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS informasi (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kategori TEXT NOT NULL DEFAULT 'Pengumuman',
                judul TEXT NOT NULL,
                konten TEXT,
                gambar TEXT DEFAULT '',
                tautan TEXT DEFAULT '',
                tanggal TEXT DEFAULT NULL,
                urutan INTEGER NOT NULL DEFAULT 0,
                status_aktif INTEGER NOT NULL DEFAULT 1,
                dibuat_pada TEXT NOT NULL,
                diperbarui_pada TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS panduan (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                judul TEXT NOT NULL,
                isi TEXT,
                ikon TEXT DEFAULT '',
                urutan INTEGER NOT NULL DEFAULT 0,
                status_aktif INTEGER NOT NULL DEFAULT 1,
                dibuat_pada TEXT NOT NULL,
                diperbarui_pada TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS tarif (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kategori TEXT NOT NULL DEFAULT 'Lainnya',
                nama_layanan TEXT NOT NULL,
                satuan TEXT DEFAULT '',
                tarif INTEGER NOT NULL DEFAULT 0,
                keterangan TEXT DEFAULT '',
                urutan INTEGER NOT NULL DEFAULT 0,
                status_aktif INTEGER NOT NULL DEFAULT 1,
                dibuat_pada TEXT NOT NULL,
                diperbarui_pada TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS admin_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                waktu TEXT NOT NULL,
                username TEXT DEFAULT '',
                aktivitas TEXT DEFAULT '',
                keterangan TEXT DEFAULT '',
                ip TEXT DEFAULT ''
            )",
            'CREATE INDEX IF NOT EXISTS idx_slide_urutan ON slide (status_aktif, urutan)',
            'CREATE INDEX IF NOT EXISTS idx_informasi_urutan ON informasi (status_aktif, tanggal)',
            'CREATE INDEX IF NOT EXISTS idx_panduan_urutan ON panduan (status_aktif, urutan)',
            'CREATE INDEX IF NOT EXISTS idx_tarif_kategori ON tarif (status_aktif, kategori, urutan)',
        ];
    }

    // ---------------- MySQL / MariaDB ----------------
    return [
        "CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            nama_lengkap VARCHAR(150) NOT NULL DEFAULT '',
            email VARCHAR(180) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'admin',
            status_aktif TINYINT(1) NOT NULL DEFAULT 1,
            login_gagal SMALLINT NOT NULL DEFAULT 0,
            terkunci_sampai DATETIME NULL DEFAULT NULL,
            terakhir_login DATETIME NULL DEFAULT NULL,
            dibuat_pada DATETIME NOT NULL,
            diperbarui_pada DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS pengaturan (
            kunci VARCHAR(80) NOT NULL,
            nilai TEXT NULL,
            label VARCHAR(150) NOT NULL DEFAULT '',
            kelompok VARCHAR(40) NOT NULL DEFAULT 'umum',
            tipe VARCHAR(20) NOT NULL DEFAULT 'text',
            diperbarui_pada DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (kunci)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS slide (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            judul VARCHAR(180) NOT NULL,
            subjudul VARCHAR(255) NOT NULL DEFAULT '',
            gambar VARCHAR(255) NOT NULL DEFAULT '',
            tautan VARCHAR(255) NOT NULL DEFAULT '',
            warna_latar VARCHAR(20) NOT NULL DEFAULT '',
            urutan SMALLINT NOT NULL DEFAULT 0,
            status_aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat_pada DATETIME NOT NULL,
            diperbarui_pada DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_slide_aktif_urutan (status_aktif, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS informasi (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kategori VARCHAR(60) NOT NULL DEFAULT 'Pengumuman',
            judul VARCHAR(200) NOT NULL,
            konten TEXT NULL,
            gambar VARCHAR(255) NOT NULL DEFAULT '',
            tautan VARCHAR(255) NOT NULL DEFAULT '',
            tanggal DATE NULL DEFAULT NULL,
            urutan SMALLINT NOT NULL DEFAULT 0,
            status_aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat_pada DATETIME NOT NULL,
            diperbarui_pada DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_informasi_aktif (status_aktif, tanggal, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS panduan (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            judul VARCHAR(180) NOT NULL,
            isi TEXT NULL,
            ikon VARCHAR(40) NOT NULL DEFAULT '',
            urutan SMALLINT NOT NULL DEFAULT 0,
            status_aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat_pada DATETIME NOT NULL,
            diperbarui_pada DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_panduan_aktif_urutan (status_aktif, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS tarif (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kategori VARCHAR(60) NOT NULL DEFAULT 'Lainnya',
            nama_layanan VARCHAR(200) NOT NULL,
            satuan VARCHAR(60) NOT NULL DEFAULT '',
            tarif INT UNSIGNED NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NOT NULL DEFAULT '',
            urutan SMALLINT NOT NULL DEFAULT 0,
            status_aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat_pada DATETIME NOT NULL,
            diperbarui_pada DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_tarif_kategori (status_aktif, kategori, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS admin_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            waktu DATETIME NOT NULL,
            username VARCHAR(120) NOT NULL DEFAULT '',
            aktivitas VARCHAR(80) NOT NULL DEFAULT '',
            keterangan VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_admin_log_waktu (waktu)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** Nama-nama tabel inti (untuk cek kelengkapan instalasi). */
function adminCoreTables()
{
    return ['admin_users', 'pengaturan', 'slide', 'informasi', 'panduan', 'tarif', 'admin_log'];
}

// ===========================================================================
// KREDENSIAL AWAL (hanya dipakai saat tabel admin_users masih kosong,
// misalnya lewat instaler otomatis di admin.php). Setelah login pertama,
// ganti password dari menu "Akun Saya".
// ===========================================================================
define('ADMIN_DEFAULT_USERNAME', getenv('RSUD_ADMIN_USERNAME') ?: 'rsudmalangbonggarut@gmail.com');
define('ADMIN_DEFAULT_PASSWORD', getenv('RSUD_ADMIN_PASSWORD') ?: '@_Malangbong123');

/**
 * Hash bcrypt dari password default di atas. Dipakai supaya isi file SQL yang
 * dihasilkan selalu sama (deterministik) dari waktu ke waktu. Bila password
 * default diganti lewat env sehingga hash ini tidak cocok lagi, hash baru
 * dibuat otomatis saat itu juga.
 */
define('ADMIN_DEFAULT_HASH', '$2y$10$lrnfeT75qGtW5y11PS4OwObyKrDmSKxtcTtd5VgK5FSAN9jK5Ow/y');

/** Hash bcrypt yang valid untuk ADMIN_DEFAULT_PASSWORD. */
function adminDefaultHash()
{
    if (defined('ADMIN_DEFAULT_HASH')
        && function_exists('password_verify')
        && password_verify(ADMIN_DEFAULT_PASSWORD, ADMIN_DEFAULT_HASH)) {
        return ADMIN_DEFAULT_HASH;
    }
    return password_hash(ADMIN_DEFAULT_PASSWORD, PASSWORD_BCRYPT);
}

// ===========================================================================
// FORMAT TEKS KONTEN (dipakai admin.php untuk pratinjau & informasi.php)
// ===========================================================================
/**
 * Ubah teks polos dari admin menjadi HTML yang aman:
 *   • semua karakter di-escape (tidak ada HTML mentah dari database)
 *   • baris yang diawali "-" atau "*" menjadi daftar bullet
 *   • baris diawali angka + "." menjadi daftar bernomor
 *   • **teks** menjadi tebal
 *   • baris kosong menjadi pemisah paragraf
 */
function adminFormatTeks($teks)
{
    $teks = (string) $teks;
    if (trim($teks) === '') return '';

    $teks  = preg_replace('/\r\n?/', "\n", $teks) ?? $teks;
    $baris = explode("\n", $teks);
    $html  = '';
    $mode  = '';   // '' | 'ul' | 'ol' | 'p'
    $buf   = [];

    $tutup = function () use (&$html, &$mode, &$buf) {
        if ($mode === 'ul') $html .= '<ul>' . implode('', $buf) . '</ul>';
        elseif ($mode === 'ol') $html .= '<ol>' . implode('', $buf) . '</ol>';
        elseif ($mode === 'p') $html .= '<p>' . implode('<br>', $buf) . '</p>';
        $buf  = [];
        $mode = '';
    };

    $inline = static function ($s) {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
        return $s;
    };

    foreach ($baris as $baris) {
        $t = trim($baris);
        if ($t === '') { $tutup(); continue; }

        if (preg_match('/^[-*•]\s+(.*)$/u', $t, $m)) {
            if ($mode !== 'ul') { $tutup(); $mode = 'ul'; }
            $buf[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.*)$/u', $t, $m)) {
            if ($mode !== 'ol') { $tutup(); $mode = 'ol'; }
            $buf[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($mode !== 'p') { $tutup(); $mode = 'p'; }
        $buf[] = $inline($t);
    }
    $tutup();

    return $html;
}

/** Panjang ringkasan teks polos (untuk tabel admin). */
function adminRingkas($teks, $max = 90)
{
    $teks = trim(preg_replace('/\s+/', ' ', (string) $teks) ?? '');
    if ($teks === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($teks) <= $max) return $teks;
    if (strlen($teks) <= $max) return $teks;
    $potong = function_exists('mb_substr') ? mb_substr($teks, 0, $max) : substr($teks, 0, $max);
    return rtrim($potong) . '…';
}

// ===========================================================================
// DATA CONTOH (dipakai instaler otomatis, file SQL, dan cadangan informasi.php)
// ===========================================================================

/** Data contoh panduan (dipakai instaler admin.php, informasi.php, dan file SQL). */
function adminContohPanduan()
{
    return [
        [
            'judul' => 'Login ke Aplikasi',
            'ikon'  => 'login',
            'isi'   => "Buka aplikasi RSUD Malangbong.\nMasukkan No. Rekam Medis (No. CM) atau NIK pada kolom yang tersedia.\nTekan tombol Masuk.\nBila data tidak ditemukan, hubungi loket pendaftaran untuk memastikan nomor Anda sudah terdaftar.",
        ],
        [
            'judul' => 'Mendaftar sebagai Pasien Baru',
            'ikon'  => 'pasien',
            'isi'   => "Pada halaman Login pilih Daftar Pasien Baru.\nIsi NIK 16 digit — sistem memvalidasi format NIK secara otomatis.\nLengkapi nama, tempat & tanggal lahir, alamat, dan nomor HP aktif.\nTekan Daftar. Nomor Rekam Medis akan diberikan setelah data diverifikasi petugas.",
        ],
        [
            'judul' => 'Booking Kunjungan Poliklinik',
            'ikon'  => 'kalender',
            'isi'   => "Masuk ke tab Booking.\nPilih tanggal kunjungan (maksimal 30 hari ke depan).\nPilih poliklinik, lalu pilih dokter yang jadwalnya tersedia.\nPeriksa kembali ringkasan booking, lalu tekan Konfirmasi.\nSimpan nomor antrian yang muncul sebagai bukti pendaftaran.",
        ],
        [
            'judul' => 'Melihat & Mengunduh Hasil Pemeriksaan',
            'ikon'  => 'hasil',
            'isi'   => "Buka tab Hasil.\nPilih pemeriksaan Laboratorium atau Radiologi berstatus Selesai.\nTekan Unduh PDF untuk menyimpan hasil ke ponsel.\nHasil hanya muncul setelah dokter/instansi selesai memverifikasi.",
        ],
        [
            'judul' => 'Riwayat Kunjungan & Bukti Antrian',
            'ikon'  => 'riwayat',
            'isi'   => "Buka tab Riwayat untuk melihat seluruh kunjungan.\nTekan Lihat Bukti untuk menampilkan kartu antrian beserta QR check-in.\nKunjungan rawat jalan yang belum dilayani dapat dibatalkan dari halaman ini.\nSaat tiba di rumah sakit, lakukan check-in dengan memindai QR di loket admisi.",
        ],
    ];
}

/** Data contoh kartu informasi. */
function adminContohInformasi()
{
    return [
        [
            'kategori' => 'Pengumuman',
            'judul'    => 'Selamat datang di Aplikasi Mobile RSUD Malangbong',
            'konten'   => "Terima kasih telah menggunakan aplikasi mobile RSUD Malangbong.\nAplikasi ini memudahkan pendaftaran, booking kunjungan dokter, melihat hasil pemeriksaan, serta riwayat kunjungan — di mana pun dan kapan pun.",
            'tautan'   => '',
        ],
        [
            'kategori' => 'Layanan',
            'judul'    => 'Pendaftaran Online Dibuka Setiap Hari',
            'konten'   => "Pendaftaran online dapat dilakukan maksimal 30 hari sebelum kunjungan.\nDatang 30 menit lebih awal dan lakukan check-in di loket admisi dengan memindai QR code.\nKuota setiap dokter terbatas — bila penuh, pilih jadwal lain.",
            'tautan'   => '',
        ],
        [
            'kategori' => 'Kesehatan',
            'judul'    => 'Siapkan Dokumen Sebelum Berobat',
            'konten'   => "- KTP atau Kartu Keluarga\n- Kartu BPJS Kesehatan (bila ada)\n- Surat rujukan dari faskes tingkat pertama\n- Obat yang sedang dikonsumsi\n\nDokumen lengkap mempercepat proses pendaftaran di loket.",
            'tautan'   => '',
        ],
    ];
}

/** Data contoh slide. */
function adminContohSlide()
{
    return [
        ['judul' => 'Pendaftaran Online Lebih Cepat', 'subjudul' => 'Booking poliklinik dari rumah, tanpa antri lama di loket.', 'tautan' => '', 'warna' => '#1b5e20'],
        ['judul' => 'Hasil Lab & Radiologi di Genggaman', 'subjudul' => 'Unduh PDF hasil pemeriksaan begitu statusnya Selesai.', 'tautan' => '', 'warna' => '#0f766e'],
        ['judul' => 'Check-in Kunjungan Pakai QR', 'subjudul' => 'Pindai QR di loket admisi untuk memastikan kehadiran Anda.', 'tautan' => '', 'warna' => '#1d4ed8'],
    ];
}

/**
 * Kategori tarif bawaan — dipakai panel admin (datalist) dan halaman informasi
 * (pengelompokan). Admin boleh mengetik kategori baru di luar daftar ini.
 */
function adminKategoriTarif()
{
    return ['Pendaftaran', 'Rawat Jalan', 'IGD', 'Rawat Inap', 'Laboratorium',
            'Radiologi', 'Tindakan', 'Persalinan', 'Farmasi', 'Lainnya'];
}

/** Satuan tarif yang umum dipakai (datalist di form admin). */
function adminSatuanTarif()
{
    return ['per kunjungan', 'per hari', 'per tindakan', 'per pemeriksaan',
            'per paket', 'per resep', 'per foto', 'per kali'];
}

/**
 * Data contoh tarif layanan. Angka hanya contoh — ganti dengan tarif resmi
 * rumah sakit lewat panel admin (menu "Tarif Layanan").
 */
function adminContohTarif()
{
    return [
        ['kategori' => 'Pendaftaran',   'nama_layanan' => 'Pendaftaran Rawat Jalan',   'satuan' => 'per kunjungan',   'tarif' => 15000,   'keterangan' => 'Termasuk kartu berobat untuk pasien baru.'],
        ['kategori' => 'Rawat Jalan',   'nama_layanan' => 'Konsultasi Dokter Umum',    'satuan' => 'per kunjungan',   'tarif' => 35000,   'keterangan' => ''],
        ['kategori' => 'Rawat Jalan',   'nama_layanan' => 'Konsultasi Dokter Spesialis', 'satuan' => 'per kunjungan', 'tarif' => 75000,   'keterangan' => 'Mengikuti jadwal praktik poliklinik.'],
        ['kategori' => 'IGD',           'nama_layanan' => 'Pemeriksaan IGD',           'satuan' => 'per kunjungan',   'tarif' => 100000,  'keterangan' => 'Belum termasuk obat dan tindakan medis.'],
        ['kategori' => 'Laboratorium',  'nama_layanan' => 'Darah Lengkap',             'satuan' => 'per pemeriksaan', 'tarif' => 65000,   'keterangan' => ''],
        ['kategori' => 'Laboratorium',  'nama_layanan' => 'Gula Darah Sewaktu',        'satuan' => 'per pemeriksaan', 'tarif' => 25000,   'keterangan' => ''],
        ['kategori' => 'Radiologi',     'nama_layanan' => 'Rontgen Thorax',            'satuan' => 'per foto',        'tarif' => 120000,  'keterangan' => 'Hasil dapat diunduh lewat aplikasi.'],
        ['kategori' => 'Rawat Inap',    'nama_layanan' => 'Kamar Kelas III',           'satuan' => 'per hari',        'tarif' => 150000,  'keterangan' => 'Termasuk visite dokter dan perawatan.'],
        ['kategori' => 'Rawat Inap',    'nama_layanan' => 'Kamar Kelas II',            'satuan' => 'per hari',        'tarif' => 275000,  'keterangan' => ''],
        ['kategori' => 'Persalinan',    'nama_layanan' => 'Persalinan Normal',         'satuan' => 'per tindakan',    'tarif' => 2500000, 'keterangan' => 'Belum termasuk penanganan komplikasi.'],
    ];
}
