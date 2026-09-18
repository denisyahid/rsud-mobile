<?php
// ============================================================================
// admin.php — PANEL ADMIN konten "Informasi" Aplikasi Mobile RSUD Malangbong
// ----------------------------------------------------------------------------
// Fungsi:
//   • Login administrator (hash bcrypt + pembatasan percobaan gagal)
//   • CRUD Slide gambar        → tampil sebagai slider di informasi.php
//   • CRUD Kartu Informasi     → tampil sebagai card bergambar di informasi.php
//   • CRUD Panduan Pemakaian   → tampil sebagai dropdown/accordion
//   • Pengaturan halaman       → judul seksi, kontak, warna, footer, dll.
//   • Upload gambar            → otomatis diperkecil (GD), mime divalidasi
//   • Cek sistem               → status koneksi DB, folder upload, tabel
//
// Database: admin_info_rsudmobile (MySQL) — TERPISAH dari database SIMRS
// yang dipakai api.php. Konfigurasinya ada di admin-config.php.
//
// Akses: http://server/backend/admin.php
// ============================================================================

require_once __DIR__ . '/admin-config.php';

// Mode uji (dipakai backend/tests/admin-smoke.mjs): jangan jalankan router
// otomatis dan jangan kirim header/redirect sungguhan.
if (!defined('RSUD_ADMIN_TEST_MODE')) {
    define('RSUD_ADMIN_TEST_MODE', false);
}

/** Exception khusus untuk error yang pesannya aman ditampilkan ke pengguna. */
class AdminError extends Exception {}

// ===========================================================================
// A. SESI, HEADER, FLASH, LOG
// ===========================================================================

/** Simpanan sesi pengganti bila session PHP tidak tersedia (mode CLI/uji). */
function &adminFakeSession()
{
    if (!isset($GLOBALS['ADMIN_FAKE_SESSION']) || !is_array($GLOBALS['ADMIN_FAKE_SESSION'])) {
        $GLOBALS['ADMIN_FAKE_SESSION'] = [];
    }
    return $GLOBALS['ADMIN_FAKE_SESSION'];
}

function adminSessionActive()
{
    return function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE;
}

/**
 * Nama sesi PHP saat ini belum memakai nama khusus panel admin?
 *
 * Catatan: PHP tidak punya konstanta PHP_SESSION_NAME — nama sesi aktif dibaca
 * lewat fungsi session_name() (mengembalikan session.name, default "PHPSESSID").
 */
function adminSessionNameBeda()
{
    return function_exists('session_name') && session_name() !== ADMIN_SESSION_NAME;
}

/** Mulai sesi dengan cookie yang aman (httponly, secure otomatis saat HTTPS). */
function adminSessionStart()
{
    if (adminSessionActive()) return true;
    if (PHP_SAPI === 'cli' || RSUD_ADMIN_TEST_MODE) return false;

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');

    if (adminSessionNameBeda() && !headers_sent()) {
        session_name(ADMIN_SESSION_NAME);
    }
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $ok = @session_start();
    return $ok !== false && adminSessionActive();
}

function adminSessGet($key, $default = null)
{
    if (adminSessionActive()) {
        return $_SESSION[$key] ?? $default;
    }
    $fake = &adminFakeSession();
    return $fake[$key] ?? $default;
}

function adminSessSet($key, $value)
{
    if (adminSessionActive()) {
        $_SESSION[$key] = $value;
        return;
    }
    $fake = &adminFakeSession();
    $fake[$key] = $value;
}

function adminSessUnset($key)
{
    if (adminSessionActive()) {
        unset($_SESSION[$key]);
        return;
    }
    $fake = &adminFakeSession();
    unset($fake[$key]);
}

function adminSessDestroy()
{
    if (adminSessionActive()) {
        $_SESSION = [];
        if (!headers_sent() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
        return;
    }
    $fake = &adminFakeSession();
    $fake = [];
}

/** Kirim header HTTP bila memang masih boleh. */
function adminHeader($header, $code = null)
{
    if (RSUD_ADMIN_TEST_MODE || headers_sent()) return;
    if ($code === null) {
        header($header);
    } else {
        header($header, true, $code);
    }
}

/** IP klien (dukung reverse proxy). */
function adminClientIp()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/** Catat aktivitas admin ke tabel admin_log (tidak pernah membuat fatal error). */
function adminLog($aktivitas, $keterangan = '', $username = null)
{
    try {
        if (!adminTableExists('admin_log')) return;
        if ($username === null) {
            $u = adminCurrentUser();
            $username = $u['username'] ?? (adminSessGet('admin_username', '') ?: 'tamu');
        }
        adminQ(
            'INSERT INTO admin_log (waktu, username, aktivitas, keterangan, ip) VALUES (?, ?, ?, ?, ?)',
            [adminNow(), (string) $username, (string) $aktivitas, mb_substr((string) $keterangan, 0, 250), adminClientIp()]
        );
    } catch (Throwable $ex) {
        // diabaikan — log tidak boleh merusak alur utama
    }
}

/** Simpan pesan sekali-tampil (flash). */
function adminFlash($tipe, $pesan)
{
    $list   = adminSessGet('admin_flash', []);
    $list[] = ['tipe' => $tipe, 'pesan' => $pesan];
    adminSessSet('admin_flash', $list);
}

/** Ambil sekaligus hapus semua pesan flash. */
function adminTakeFlash()
{
    $list = adminSessGet('admin_flash', []);
    adminSessUnset('admin_flash');
    return is_array($list) ? $list : [];
}

/** Redirect setelah POST (pola PRG) — 303 supaya tidak resubmit saat refresh. */
function adminRedirect($url)
{
    if (RSUD_ADMIN_TEST_MODE) {
        $GLOBALS['ADMIN_TEST_REDIRECT'] = $url;
        return;
    }
    if (!headers_sent()) {
        header('Location: ' . $url, true, 303);
    }
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta http-equiv="refresh" content="0;url=' . e($url) . '">'
        . '<title>Mengalihkan…</title></head><body>'
        . '<p style="font-family:sans-serif;padding:24px">Mengalihkan ke <a href="' . e($url) . '">' . e($url) . '</a></p>'
        . '</body></html>';
    exit;
}

/** URL panel saat ini (untuk redirect kembali ke halaman yang sama). */
function adminSelfUrl(array $extra = [])
{
    $params = array_merge(['page' => adminGet('page', 'dashboard')], $extra);
    return 'admin.php?' . http_build_query($params);
}

// ===========================================================================
// B. INPUT & CSRF
// ===========================================================================

function adminGet($key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function adminPost($key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    if (is_array($v)) return $v;
    return is_string($v) ? trim($v) : $v;
}

function adminPostInt($key, $default = 0)
{
    $v = adminPost($key, $default);
    return is_numeric($v) ? (int) $v : (int) $default;
}

/** Potong teks sesuai batas kolom database agar tidak error "Data too long". */
function adminLimit($teks, $max)
{
    $teks = trim((string) $teks);
    if (function_exists('mb_substr')) return mb_substr($teks, 0, $max);
    return substr($teks, 0, $max);
}

function adminCsrfToken()
{
    $token = adminSessGet('admin_csrf');
    if (!is_string($token) || strlen($token) < 16) {
        $token = bin2hex(random_bytes(32));
        adminSessSet('admin_csrf', $token);
    }
    return $token;
}

function adminCsrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . e(adminCsrfToken()) . '">';
}

/**
 * Validasi token CSRF.
 * Pada mode uji token bisa dilewati dengan konstanta RSUD_ADMIN_SKIP_CSRF.
 */
function adminVerifyCsrf()
{
    if (defined('RSUD_ADMIN_SKIP_CSRF') && RSUD_ADMIN_SKIP_CSRF) return true;
    $kirim = (string) ($_POST['csrf_token'] ?? '');
    $asli  = (string) adminSessGet('admin_csrf', '');
    return $kirim !== '' && $asli !== '' && hash_equals($asli, $kirim);
}

/** Pastikan request POST + CSRF valid; kalau tidak, lempar AdminError. */
function adminRequirePost()
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        throw new AdminError('Metode request tidak diizinkan.');
    }
    if (!adminVerifyCsrf()) {
        throw new AdminError('Sesi berakhir / token keamanan tidak cocok. Muat ulang halaman lalu coba lagi.');
    }
}

// ===========================================================================
// C. FOLDER UPLOAD & PENGOLAHAN GAMBAR
// ===========================================================================

/** Pastikan folder upload ada dan bisa ditulis. */
function adminEnsureUploadDir()
{
    $dir = ADMIN_UPLOAD_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // cegah eksekusi skrip & daftar isi folder upload (Apache)
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht, adminHtaccessIsi());
    }
    $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (is_dir($dir) && !file_exists($idx)) {
        @file_put_contents($idx, adminIndexHtmlIsi());
    }
    return is_dir($dir);
}

/** Isi .htaccess folder upload (juga disimpan di repo agar ikut ter-deploy). */
function adminHtaccessIsi()
{
    return <<<HTACCESS
# ===========================================================================
# Folder upload gambar — Panel Admin Informasi RSUD Malangbong
# Jangan izinkan eksekusi skrip apa pun dari folder ini.
# ===========================================================================
Options -Indexes -ExecCGI

# Matikan mesin PHP di folder ini (Apache + mod_php)
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>

# Tolak akses ke berkas skrip (Apache 2.4)
<IfModule mod_authz_core.c>
    <FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar|pl|py|cgi|sh|htaccess)$">
        Require all denied
    </FilesMatch>
</IfModule>

# Tolak akses ke berkas skrip (Apache 2.2)
<IfModule !mod_authz_core.c>
    <FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar|pl|py|cgi|sh|htaccess)$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</IfModule>

# Cache gambar agak lama (hemat kuota pasien)
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 7 days"
    ExpiresByType image/png  "access plus 7 days"
    ExpiresByType image/webp "access plus 7 days"
    ExpiresByType image/gif  "access plus 7 days"
</IfModule>
HTACCESS;
}

/** Isi index.html folder upload (mencegah daftar isi direktori). */
function adminIndexHtmlIsi()
{
    return "<!DOCTYPE html>\n<html lang=\"id\"><head><meta charset=\"UTF-8\">"
        . "<title>403 - Terlarang</title></head>"
        . "<body style=\"font-family:sans-serif;padding:24px;color:#6b7280\">"
        . "Folder ini menyimpan gambar unggahan panel admin RSUD Malangbong."
        . "</body></html>\n";
}

function adminUploadDirWritable()
{
    if (!adminEnsureUploadDir()) return false;
    return is_writable(ADMIN_UPLOAD_DIR);
}

/** Path absolut file gambar dari nama file yang tersimpan di database. */
function adminImagePath($namaFile)
{
    $namaFile = basename((string) $namaFile);
    if ($namaFile === '' || $namaFile === '.') return '';
    return rtrim(ADMIN_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $namaFile;
}

/** URL relatif file gambar (dipakai di tag <img>). */
function adminImageUrl($namaFile)
{
    $namaFile = basename((string) $namaFile);
    if ($namaFile === '') return '';
    return ADMIN_UPLOAD_URL . '/' . rawurlencode($namaFile);
}

/** Hapus file gambar lama (aman: hanya di dalam folder upload). */
function adminDeleteImage($namaFile)
{
    $path = adminImagePath($namaFile);
    if ($path !== '' && is_file($path)) {
        return @unlink($path);
    }
    return false;
}

/** Deteksi mime asli dari isi file (bukan dari nama/klaim browser). */
function adminDetectMime($file)
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string) @finfo_file($fi, $file);
            @finfo_close($fi);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) @mime_content_type($file);
    }
    if ($mime === '') {
        $info = @getimagesize($file);
        if (is_array($info) && !empty($info['mime'])) $mime = (string) $info['mime'];
    }
    return strtolower(trim($mime));
}

/** Pindahkan file upload (dukung mode CLI/uji di mana is_uploaded_file() false). */
function adminMoveUpload($tmp, $dest)
{
    if (function_exists('is_uploaded_file') && is_uploaded_file($tmp)) {
        return @move_uploaded_file($tmp, $dest);
    }
    if (@rename($tmp, $dest)) return true;
    if (@copy($tmp, $dest)) {
        @unlink($tmp);
        return true;
    }
    return false;
}

/**
 * Perkecil & simpan gambar memakai GD (bila tersedia).
 * Mengembalikan nama file baru, atau false bila gagal.
 */
function adminProsesGambar($tmpFile, $mime, $prefix)
{
    if (!adminEnsureUploadDir() || !adminUploadDirWritable()) {
        throw new AdminError('Folder upload tidak bisa ditulis: ' . ADMIN_UPLOAD_DIR
            . ' — ubah permission menjadi 775 (atau 755 dengan pemilik user web server).');
    }

    global $ADMIN_ALLOWED_MIME;
    if (!isset($ADMIN_ALLOWED_MIME[$mime])) {
        throw new AdminError('Jenis file tidak diizinkan. Gunakan JPG, PNG, WEBP, atau GIF.');
    }
    $ext = $ADMIN_ALLOWED_MIME[$mime];

    $stamp = date('Ymd-His');
    $rand  = function_exists('random_bytes') ? bin2hex(random_bytes(3)) : str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $nama  = adminSlug($prefix) . '-' . $stamp . '-' . $rand . '.' . $ext;
    $dest  = rtrim(ADMIN_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $nama;

    $pakaiGd = function_exists('imagecreatefromstring') && $mime !== 'image/gif';

    if (!$pakaiGd) {
        // GIF (bisa animasi) atau server tanpa GD → simpan apa adanya
        if (!adminMoveUpload($tmpFile, $dest)) {
            throw new AdminError('Gagal menyimpan file ke folder upload.');
        }
        return $nama;
    }

    $data = @file_get_contents($tmpFile);
    $img  = $data === false ? false : @imagecreatefromstring($data);
    if (!$img) {
        // GD gagal membaca → simpan mentah selama mime valid
        if (!adminMoveUpload($tmpFile, $dest)) {
            throw new AdminError('Gagal menyimpan file ke folder upload.');
        }
        return $nama;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $maxW = ADMIN_MAX_IMAGE_WIDTH;
    $maxH = ADMIN_MAX_IMAGE_HEIGHT;

    if ($w > $maxW || $h > $maxH) {
        $ratio = min($maxW / max(1, $w), $maxH / max(1, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $canvas = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $transparent);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
        }
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $canvas;
    }

    $sukses = false;
    switch ($mime) {
        case 'image/jpeg':
            $sukses = @imagejpeg($img, $dest, ADMIN_JPEG_QUALITY);
            break;
        case 'image/png':
            // kompresi 9 = terkecil; alpha tetap tersimpan
            $sukses = @imagepng($img, $dest, 9);
            break;
        case 'image/webp':
            $sukses = function_exists('imagewebp') ? @imagewebp($img, $dest, ADMIN_WEBP_QUALITY) : @imagepng($img, $dest, 9);
            if ($sukses && !function_exists('imagewebp')) {
                $nama = preg_replace('/\.webp$/', '.png', $nama);
                $dest = rtrim(ADMIN_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $nama;
            }
            break;
        default:
            $sukses = false;
    }
    imagedestroy($img);

    if (!$sukses || !is_file($dest)) {
        // gagal olah → simpan file asli
        if (!adminMoveUpload($tmpFile, $dest)) {
            throw new AdminError('Gagal menyimpan gambar. Periksa permission folder upload.');
        }
    } else {
        @unlink($tmpFile);
    }

    return $nama;
}

/** Pesan error kode upload PHP → bahasa manusia. */
function adminUploadErrorMessage($code)
{
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas upload_max_filesize di php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas yang diizinkan form.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian. Coba lagi.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara PHP tidak ada. Hubungi administrator server.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk. Periksa permission folder upload.',
        UPLOAD_ERR_EXTENSION  => 'Upload dibatalkan oleh ekstensi PHP.',
    ];
    return $map[$code] ?? 'Upload gagal (kode ' . (int) $code . ').';
}

/**
 * Tangani satu field upload gambar.
 * Mengembalikan ['file' => namaFileBaru/lama, 'berubah' => bool, 'pesan' => string]
 *
 * @param string $field   nama input file
 * @param string $prefix  awalan nama file (mis. "slide")
 * @param string $lama    nama file lama di database (untuk dihapus bila diganti)
 * @param bool   $hapus   centang "hapus gambar" dari form
 */
function adminSimpanGambar($field, $prefix, $lama = '', $hapus = false)
{
    $lama = basename((string) $lama);

    if ($hapus) {
        if ($lama !== '') {
            adminDeleteImage($lama);
            return ['file' => '', 'berubah' => true, 'pesan' => 'Gambar lama dihapus.'];
        }
        return ['file' => '', 'berubah' => false, 'pesan' => ''];
    }

    if (!isset($_FILES[$field])) {
        return ['file' => $lama, 'berubah' => false, 'pesan' => ''];
    }

    $f = $_FILES[$field];
    $err = isset($f['error']) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;

    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['file' => $lama, 'berubah' => false, 'pesan' => ''];
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new AdminError(adminUploadErrorMessage($err));
    }

    $tmp  = (string) ($f['tmp_name'] ?? '');
    $size = (int) ($f['size'] ?? 0);

    if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
        throw new AdminError('File sementara tidak ditemukan. Coba upload ulang.');
    }
    if ($size <= 0) {
        throw new AdminError('File kosong / ukuran tidak valid.');
    }
    if ($size > ADMIN_MAX_UPLOAD_BYTES) {
        throw new AdminError('Ukuran file ' . adminFormatBytes($size)
            . ' melebihi batas ' . adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES) . '.');
    }

    $mime = adminDetectMime($tmp);
    global $ADMIN_ALLOWED_MIME;
    if (!isset($ADMIN_ALLOWED_MIME[$mime])) {
        @unlink($tmp);
        throw new AdminError('Jenis file tidak diizinkan (terdeteksi: ' . ($mime ?: 'tidak dikenal')
            . '). Gunakan JPG, PNG, WEBP, atau GIF.');
    }

    // pastikan benar-benar gambar (perlindungan ganda)
    $info = @getimagesize($tmp);
    if ($info === false) {
        @unlink($tmp);
        throw new AdminError('File bukan gambar yang valid.');
    }

    $nama = adminProsesGambar($tmp, $mime, $prefix);

    if ($lama !== '' && $lama !== $nama) {
        adminDeleteImage($lama);
    }

    return ['file' => $nama, 'berubah' => true, 'pesan' => 'Gambar berhasil diunggah.'];
}

// ===========================================================================
// D. AUTENTIKASI ADMIN
// ===========================================================================

/** Ambil baris user berdasarkan username (atau email). */
function adminFindUser($username)
{
    $username = trim((string) $username);
    if ($username === '') return null;
    return adminOne('SELECT * FROM admin_users WHERE username = ? LIMIT 1', [$username]);
}

function adminFindUserById($id)
{
    return adminOne('SELECT * FROM admin_users WHERE id = ? LIMIT 1', [(int) $id]);
}

/** Apakah akun sedang terkunci karena terlalu sering salah password? */
function adminUserTerkunci($user)
{
    if (empty($user['terkunci_sampai'])) return false;
    return strtotime((string) $user['terkunci_sampai']) > time();
}

/**
 * Coba login.
 * @return array ['ok' => bool, 'pesan' => string, 'user' => ?array]
 */
function adminAttemptLogin($username, $password)
{
    $username = trim((string) $username);
    $password = (string) $password;

    if ($username === '' || $password === '') {
        return ['ok' => false, 'pesan' => 'Username dan password wajib diisi.', 'user' => null];
    }

    if (!adminTableExists('admin_users')) {
        return [
            'ok'    => false,
            'pesan' => 'Tabel admin_users belum ada. Jalankan instalasi skema lewat menu "Cek Sistem" '
                     . 'atau import file backend/sql/admin_info_rsudmobile.sql.',
            'user'  => null,
        ];
    }

    $user = adminFindUser($username);
    if (!$user) {
        // pesan sengaja umum agar tidak membocorkan daftar username
        return ['ok' => false, 'pesan' => 'Username atau password salah.', 'user' => null];
    }

    if ((int) ($user['status_aktif'] ?? 0) !== 1) {
        return ['ok' => false, 'pesan' => 'Akun ini dinonaktifkan. Hubungi administrator.', 'user' => $user];
    }

    if (adminUserTerkunci($user)) {
        $sisa = max(1, (int) ceil((strtotime((string) $user['terkunci_sampai']) - time()) / 60));
        return ['ok' => false, 'pesan' => 'Akun terkunci sementara karena terlalu sering salah password. Coba lagi ±' . $sisa . ' menit.', 'user' => $user];
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        $gagal = (int) ($user['login_gagal'] ?? 0) + 1;
        $kunci = $gagal >= ADMIN_MAX_LOGIN_GAGAL
            ? date('Y-m-d H:i:s', time() + ADMIN_MENIT_TERKUNCI * 60)
            : null;
        adminQ('UPDATE admin_users SET login_gagal = ?, terkunci_sampai = ?, diperbarui_pada = ? WHERE id = ?',
            [$gagal, $kunci, adminNow(), (int) $user['id']]);
        adminLog('login_gagal', 'username: ' . $username, $username);

        $sisa = ADMIN_MAX_LOGIN_GAGAL - $gagal;
        $pesan = $kunci !== null
            ? 'Terlalu banyak percobaan gagal. Akun dikunci ' . ADMIN_MENIT_TERKUNCI . ' menit.'
            : 'Username atau password salah. Sisa percobaan: ' . max(0, $sisa) . '.';
        return ['ok' => false, 'pesan' => $pesan, 'user' => $user];
    }

    // sukses
    adminQ('UPDATE admin_users SET login_gagal = 0, terkunci_sampai = NULL, terakhir_login = ?, diperbarui_pada = ? WHERE id = ?',
        [adminNow(), adminNow(), (int) $user['id']]);

    // perbarui hash bila algoritma/cost PHP sudah berubah
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
        adminQ('UPDATE admin_users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), (int) $user['id']]);
    }

    if (adminSessionActive() && !RSUD_ADMIN_TEST_MODE) {
        @session_regenerate_id(true);
    }
    adminSessSet('admin_id', (int) $user['id']);
    adminSessSet('admin_username', (string) $user['username']);
    adminSessSet('admin_last_active', time());
    adminSessSet('admin_csrf', bin2hex(random_bytes(32)));

    adminLog('login_sukses', 'masuk panel admin', $user['username']);

    return ['ok' => true, 'pesan' => 'Login berhasil.', 'user' => $user];
}

/** User yang sedang login (null bila belum / sesi kedaluwarsa). */
function adminCurrentUser()
{
    static $user = false;
    if ($user !== false) return $user;

    $id = (int) adminSessGet('admin_id', 0);
    if ($id <= 0) return $user = null;

    // logout otomatis bila terlalu lama tidak aktif
    $last = (int) adminSessGet('admin_last_active', 0);
    if ($last > 0 && (time() - $last) > ADMIN_SESSION_IDLE) {
        adminSessDestroy();
        return $user = null;
    }
    adminSessSet('admin_last_active', time());

    try {
        $row = adminFindUserById($id);
    } catch (Throwable $ex) {
        return $user = null;
    }
    if (!$row || (int) ($row['status_aktif'] ?? 0) !== 1) {
        adminSessDestroy();
        return $user = null;
    }
    return $user = $row;
}

function adminIsLoggedIn()
{
    return adminCurrentUser() !== null;
}

function adminLogout()
{
    $u = adminCurrentUser();
    if ($u) adminLog('logout', 'keluar panel admin', $u['username']);
    adminSessDestroy();
}

// ===========================================================================
// E. DEFINISI ENTITAS (slide, informasi, panduan)
// ===========================================================================

function adminEntities()
{
    return [
        'slide' => [
            'tabel'    => 'slide',
            'label'    => 'Slide',
            'labelBny' => 'Slide Gambar',
            'prefix'   => 'slide',
            'punyaGambar' => true,
            'urutanSql'   => 'urutan ASC, id ASC',
            'kolom'    => ['judul', 'subjudul', 'gambar', 'tautan', 'warna_latar', 'urutan', 'status_aktif'],
            'wajib'    => ['judul'],
            'panjang'  => ['judul' => 180, 'subjudul' => 255, 'tautan' => 255, 'warna_latar' => 20, 'gambar' => 255],
        ],
        'informasi' => [
            'tabel'    => 'informasi',
            'label'    => 'Kartu Informasi',
            'labelBny' => 'Kartu Informasi',
            'prefix'   => 'info',
            'punyaGambar' => true,
            'urutanSql'   => 'urutan ASC, tanggal DESC, id DESC',
            'kolom'    => ['kategori', 'judul', 'konten', 'gambar', 'tautan', 'tanggal', 'urutan', 'status_aktif'],
            'wajib'    => ['judul'],
            'panjang'  => ['kategori' => 60, 'judul' => 200, 'tautan' => 255, 'gambar' => 255],
        ],
        'panduan' => [
            'tabel'    => 'panduan',
            'label'    => 'Panduan',
            'labelBny' => 'Panduan Pemakaian',
            'prefix'   => 'panduan',
            'punyaGambar' => false,
            'urutanSql'   => 'urutan ASC, id ASC',
            'kolom'    => ['judul', 'isi', 'ikon', 'urutan', 'status_aktif'],
            'wajib'    => ['judul'],
            'panjang'  => ['judul' => 180, 'ikon' => 40],
        ],
        'tarif' => [
            'tabel'    => 'tarif',
            'label'    => 'Tarif Layanan',
            'labelBny' => 'Tarif Layanan',
            'prefix'   => 'tarif',
            'punyaGambar' => false,
            'kolomJudul'  => 'nama_layanan',   // kolom yang mewakili "judul" baris
            'urutanSql'   => 'kategori ASC, urutan ASC, id ASC',
            'kolom'    => ['kategori', 'nama_layanan', 'satuan', 'tarif', 'keterangan', 'urutan', 'status_aktif'],
            'wajib'    => ['nama_layanan'],
            'panjang'  => ['kategori' => 60, 'nama_layanan' => 200, 'satuan' => 60, 'keterangan' => 255],
            'kategoriDefault' => 'Lainnya',
        ],
    ];
}

/** Kolom yang berperan sebagai "judul" sebuah baris (untuk log & konfirmasi hapus). */
function adminJudulBaris($row)
{
    if (!is_array($row)) return '';
    if (isset($row['judul']))          return (string) $row['judul'];
    if (isset($row['nama_layanan']))   return (string) $row['nama_layanan'];
    return '';
}

function adminEntity($key)
{
    $list = adminEntities();
    if (!isset($list[$key])) {
        throw new AdminError('Data tidak dikenal: ' . $key);
    }
    return $list[$key];
}

/** Daftar baris (urut sesuai konfigurasi entitas). */
function adminRows($entityKey, $hanyaAktif = false)
{
    $e   = adminEntity($entityKey);
    $sql = 'SELECT * FROM ' . $e['tabel'];
    if ($hanyaAktif) $sql .= ' WHERE status_aktif = 1';
    $sql .= ' ORDER BY ' . $e['urutanSql'];
    return adminAll($sql);
}

function adminRow($entityKey, $id)
{
    $e = adminEntity($entityKey);
    return adminOne('SELECT * FROM ' . $e['tabel'] . ' WHERE id = ? LIMIT 1', [(int) $id]);
}

function adminCount($entityKey, $hanyaAktif = false)
{
    $e   = adminEntity($entityKey);
    $sql = 'SELECT COUNT(*) FROM ' . $e['tabel'];
    if ($hanyaAktif) $sql .= ' WHERE status_aktif = 1';
    return (int) adminValue($sql, [], 0);
}

/** Urutan berikutnya (kelipatan 10 supaya mudah disisipkan). */
function adminNextUrutan($entityKey)
{
    $e = adminEntity($entityKey);
    $max = (int) adminValue('SELECT COALESCE(MAX(urutan), 0) FROM ' . $e['tabel'], [], 0);
    return $max + 10;
}

/**
 * Validasi + rapikan data sebelum disimpan.
 * @return array data siap tulis ke database
 */
function adminPrepareData($entityKey, array $input, $id = 0)
{
    $e    = adminEntity($entityKey);
    $data = [];

    foreach ($e['kolom'] as $kolom) {
        if ($kolom === 'gambar') continue;   // ditangani proses upload
        if ($kolom === 'urutan') {
            $data['urutan'] = isset($input['urutan']) && $input['urutan'] !== ''
                ? max(0, min(32000, (int) $input['urutan']))
                : adminNextUrutan($entityKey);
            continue;
        }
        if ($kolom === 'status_aktif') {
            $data['status_aktif'] = !empty($input['status_aktif']) ? 1 : 0;
            continue;
        }

        $nilai = isset($input[$kolom]) ? (is_array($input[$kolom]) ? '' : (string) $input[$kolom]) : '';

        if ($kolom === 'tanggal') {
            $nilai = trim($nilai);
            if ($nilai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
                throw new AdminError('Format tanggal tidak valid (gunakan YYYY-MM-DD).');
            }
            $data['tanggal'] = $nilai === '' ? null : $nilai;
            continue;
        }

        if ($kolom === 'warna_latar') {
            $nilai = trim($nilai);
            if ($nilai !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $nilai)) {
                throw new AdminError('Warna latar harus format hex, contoh: #1b5e20');
            }
            $data['warna_latar'] = $nilai;
            continue;
        }

        if ($kolom === 'tautan') {
            $nilai = trim($nilai);
            if ($nilai !== '' && preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $nilai)) {
                throw new AdminError('Tautan tidak diizinkan.');
            }
            $data['tautan'] = adminLimit($nilai, $e['panjang']['tautan'] ?? 255);
            continue;
        }

        if ($kolom === 'tarif') {
            $data['tarif'] = adminAngkaDariTeks($nilai);
            continue;
        }

        if ($kolom === 'kategori') {
            $nilai = trim($nilai);
            if ($nilai === '') $nilai = $e['kategoriDefault'] ?? 'Pengumuman';
            $data['kategori'] = adminLimit($nilai, $e['panjang']['kategori'] ?? 60);
            continue;
        }

        if ($kolom === 'ikon') {
            $nilai = trim($nilai);
            $boleh = array_keys(adminPanduanIcons());
            if ($nilai !== '' && !in_array($nilai, $boleh, true)) $nilai = 'info';
            $data['ikon'] = $nilai === '' ? 'info' : $nilai;
            continue;
        }

        // teks biasa
        $nilai = preg_replace('/\r\n?/', "\n", $nilai) ?? $nilai;
        if (in_array($kolom, ['judul', 'subjudul', 'nama_layanan', 'satuan'], true)) $nilai = trim($nilai);
        $max = $e['panjang'][$kolom] ?? null;
        $data[$kolom] = $max ? adminLimit($nilai, $max) : $nilai;
    }

    // wajib isi
    foreach ($e['wajib'] as $w) {
        if (trim((string) ($data[$w] ?? '')) === '') {
            throw new AdminError('Kolom "' . ucfirst(str_replace('_', ' ', $w)) . '" wajib diisi.');
        }
    }

    // konten minimal untuk panduan
    if ($entityKey === 'panduan' && trim((string) ($data['isi'] ?? '')) === '') {
        throw new AdminError('Isi panduan wajib diisi agar dropdown tidak kosong.');
    }

    $data['diperbarui_pada'] = adminNow();
    if ((int) $id === 0) $data['dibuat_pada'] = adminNow();

    return $data;
}

/** INSERT / UPDATE generik dengan prepared statement. */
function adminSaveRow($entityKey, array $data, $id = 0)
{
    $e     = adminEntity($entityKey);
    $tabel = $e['tabel'];
    $id    = (int) $id;

    if ($id > 0) {
        $sets   = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[]   = $k . ' = ?';
            $params[] = $v;
        }
        $params[] = $id;
        adminQ('UPDATE ' . $tabel . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        return $id;
    }

    $kolom  = array_keys($data);
    $tanda  = implode(', ', array_fill(0, count($kolom), '?'));
    adminQ('INSERT INTO ' . $tabel . ' (' . implode(', ', $kolom) . ') VALUES (' . $tanda . ')',
        array_values($data));
    return (int) adminPdo()->lastInsertId();
}

function adminDeleteRow($entityKey, $id)
{
    $e = adminEntity($entityKey);
    return adminQ('DELETE FROM ' . $e['tabel'] . ' WHERE id = ?', [(int) $id])->rowCount();
}

function adminToggleRow($entityKey, $id)
{
    $e   = adminEntity($entityKey);
    $row = adminRow($entityKey, $id);
    if (!$row) throw new AdminError('Data tidak ditemukan.');
    $baru = ((int) $row['status_aktif'] === 1) ? 0 : 1;
    adminQ('UPDATE ' . $e['tabel'] . ' SET status_aktif = ?, diperbarui_pada = ? WHERE id = ?',
        [$baru, adminNow(), (int) $id]);
    return $baru === 1;
}

/** Naik/turunkan posisi baris, lalu rapikan ulang semua nomor urutan. */
function adminMoveRow($entityKey, $id, $arah)
{
    $e    = adminEntity($entityKey);
    $rows = adminRows($entityKey);
    $ids  = array_map(static function ($r) { return (int) $r['id']; }, $rows);
    $pos  = array_search((int) $id, $ids, true);
    if ($pos === false) throw new AdminError('Data tidak ditemukan.');

    $target = $arah === 'naik' ? $pos - 1 : $pos + 1;
    if ($target < 0 || $target >= count($ids)) return false;

    $tmp = $ids[$pos];
    $ids[$pos] = $ids[$target];
    $ids[$target] = $tmp;

    foreach ($ids as $i => $rid) {
        adminQ('UPDATE ' . $e['tabel'] . ' SET urutan = ?, diperbarui_pada = ? WHERE id = ?',
            [($i + 1) * 10, adminNow(), $rid]);
    }
    return true;
}

// ===========================================================================
// F. METADATA PENGATURAN (untuk form + seeding)
// ===========================================================================

/** Daftar field pengaturan, dikelompokkan, lengkap dengan tipe inputnya. */
function adminSettingFields()
{
    return [
        'Identitas & Tampilan' => [
            ['kunci' => 'app_nama',         'label' => 'Nama rumah sakit',              'tipe' => 'text',  'ket' => 'Dipakai di header (bila aktif) dan footer.'],
            ['kunci' => 'app_subjudul',     'label' => 'Subjudul halaman',              'tipe' => 'text',  'ket' => 'Baris kecil di bawah nama rumah sakit.'],
            ['kunci' => 'warna_utama',      'label' => 'Warna utama',                   'tipe' => 'color', 'ket' => 'Warna aksen halaman informasi.'],
            ['kunci' => 'tampilkan_header', 'label' => 'Tampilkan header di atas halaman', 'tipe' => 'switch', 'ket' => 'Default: mati (halaman langsung mulai dari slider).'],
            ['kunci' => 'tampilkan_slider', 'label' => 'Tampilkan slider gambar',       'tipe' => 'switch', 'ket' => 'Slider diambil dari menu Slide Gambar.'],
            ['kunci' => 'slider_autoplay',  'label' => 'Slider bergeser otomatis',      'tipe' => 'switch', 'ket' => ''],
            ['kunci' => 'slider_interval',  'label' => 'Jeda geser slider (milidetik)', 'tipe' => 'number', 'ket' => 'Disarankan 4000 – 8000.'],
        ],
        'Judul Seksi' => [
            ['kunci' => 'info_judul_seksi',    'label' => 'Judul seksi informasi', 'tipe' => 'text', 'ket' => 'Cadangan: saat ini kartu informasi tidak dirender di informasi.php (berita lewat slider).'],
            ['kunci' => 'tarif_judul_seksi',   'label' => 'Judul tab tarif',       'tipe' => 'text', 'ket' => 'Contoh: Tarif Layanan.'],
            ['kunci' => 'panduan_judul_seksi', 'label' => 'Judul seksi panduan',   'tipe' => 'text', 'ket' => ''],
            ['kunci' => 'tampilkan_tarif',     'label' => 'Tampilkan tab tarif layanan', 'tipe' => 'switch', 'ket' => 'Isi tab diambil dari menu Tarif Layanan.'],
            ['kunci' => 'tampilkan_panduan',   'label' => 'Tampilkan tab panduan', 'tipe' => 'switch', 'ket' => ''],
            ['kunci' => 'panduan_buka_satu',   'label' => 'Hanya satu dropdown terbuka', 'tipe' => 'switch', 'ket' => 'Bila mati, beberapa dropdown bisa terbuka bersamaan.'],
            ['kunci' => 'kontak_judul_seksi',  'label' => 'Judul seksi kontak',    'tipe' => 'text', 'ket' => ''],
            ['kunci' => 'tampilkan_kontak',    'label' => 'Tampilkan tab kontak',  'tipe' => 'switch', 'ket' => ''],
        ],
        'Kontak & Layanan' => [
            ['kunci' => 'alamat',      'label' => 'Alamat',                    'tipe' => 'text',   'ket' => ''],
            ['kunci' => 'jam_layanan', 'label' => 'Jam pelayanan',             'tipe' => 'text',   'ket' => ''],
            ['kunci' => 'telepon',     'label' => 'Telepon / IGD',             'tipe' => 'text',   'ket' => 'Kosongkan bila tidak ingin ditampilkan.'],
            ['kunci' => 'whatsapp',    'label' => 'Nomor WhatsApp',            'tipe' => 'text',   'ket' => 'Format internasional tanpa +, contoh: 6281385831193'],
            ['kunci' => 'email',       'label' => 'Email',                     'tipe' => 'text',   'ket' => ''],
            ['kunci' => 'website',     'label' => 'Website',                   'tipe' => 'text',   'ket' => 'Awali dengan https://'],
            ['kunci' => 'maps',        'label' => 'Link Google Maps',          'tipe' => 'text',   'ket' => 'Opsional — alamat jadi bisa diklik.'],
        ],
        'Footer & Pesan' => [
            ['kunci' => 'footer_teks',       'label' => 'Teks footer',                'tipe' => 'text', 'ket' => ''],
            ['kunci' => 'pesan_kosong_info', 'label' => 'Pesan bila informasi kosong','tipe' => 'text', 'ket' => 'Cadangan: dipakai bila kartu informasi ditampilkan kembali.'],
            ['kunci' => 'pesan_kosong_slide','label' => 'Pesan bila slide kosong',    'tipe' => 'text', 'ket' => 'Kosongkan = seksi slider disembunyikan.'],
            ['kunci' => 'pesan_kosong_tarif','label' => 'Pesan bila tarif kosong',    'tipe' => 'text', 'ket' => ''],
            ['kunci' => 'tarif_catatan',     'label' => 'Catatan di bawah daftar tarif', 'tipe' => 'text', 'ket' => 'Contoh: tarif dapat berubah sewaktu-waktu. Kosongkan untuk menyembunyikan.'],
        ],
    ];
}

/** Semua kunci pengaturan yang dikenal (dipakai saat menyimpan form). */
function adminSettingKeys()
{
    $keys = [];
    foreach (adminSettingFields() as $group) {
        foreach ($group as $f) $keys[$f['kunci']] = $f;
    }
    return $keys;
}

/** Ikon yang bisa dipilih untuk item panduan (kunci => label). */
function adminPanduanIcons()
{
    return [
        'info'      => 'Info (i)',
        'login'     => 'Login / kunci',
        'pasien'    => 'Pasien baru',
        'kalender'  => 'Booking / kalender',
        'hasil'     => 'Hasil pemeriksaan',
        'riwayat'   => 'Riwayat / antrian',
        'obat'      => 'Obat / farmasi',
        'bayar'     => 'Pembayaran',
        'bantuan'   => 'Bantuan / kontak',
    ];
}

/** Kategori bawaan kartu informasi (boleh ditambah bebas lewat datalist). */
function adminKategoriInfo()
{
    return ['Pengumuman', 'Berita', 'Layanan', 'Kesehatan', 'Jadwal', 'Umum'];
}

// ===========================================================================
// G. INSTALER SKEMA (fallback bila file .sql belum diimport)
// ===========================================================================

/**
 * Buat tabel bila belum ada + isi data awal. Idempoten: aman dijalankan ulang.
 * @return array laporan per langkah ['label','ok','pesan']
 */
function adminInstallSchema($isiContoh = true)
{
    global $ADMIN_DB_CONFIG;
    $laporan = [];
    $driver  = strtolower((string) $ADMIN_DB_CONFIG['driver']);

    // 1. buat tabel
    try {
        foreach (adminSchemaStatements($driver) as $sql) {
            adminPdo()->exec($sql);
        }
        $laporan[] = ['label' => 'Membuat tabel', 'ok' => true, 'pesan' => implode(', ', adminCoreTables())];
    } catch (Throwable $ex) {
        $laporan[] = ['label' => 'Membuat tabel', 'ok' => false, 'pesan' => $ex->getMessage()];
        return $laporan;
    }

    // 2. isi pengaturan default
    try {
        $ada = (int) adminValue('SELECT COUNT(*) FROM pengaturan', [], 0);
        if ($ada === 0) {
            foreach (adminSettingFields() as $kelompok => $fields) {
                foreach ($fields as $f) {
                    $default = adminDefaultSettings();
                    $nilai   = $default[$f['kunci']] ?? '';
                    adminQ(
                        'INSERT INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?)',
                        [$f['kunci'], $nilai, $f['label'], $kelompok, $f['tipe'], adminNow()]
                    );
                }
            }
            $laporan[] = ['label' => 'Mengisi pengaturan default', 'ok' => true, 'pesan' => count(adminSettingKeys()) . ' baris'];
        } else {
            $laporan[] = ['label' => 'Mengisi pengaturan default', 'ok' => true, 'pesan' => 'sudah ada (' . $ada . ' baris), dilewati'];
        }
    } catch (Throwable $ex) {
        $laporan[] = ['label' => 'Mengisi pengaturan default', 'ok' => false, 'pesan' => $ex->getMessage()];
    }

    // 3. buat akun admin pertama
    try {
        $ada = (int) adminValue('SELECT COUNT(*) FROM admin_users', [], 0);
        if ($ada === 0) {
            $user = defined('ADMIN_DEFAULT_USERNAME') ? ADMIN_DEFAULT_USERNAME : 'admin';
            adminQ(
                'INSERT INTO admin_users (username, password_hash, nama_lengkap, email, role, status_aktif, login_gagal, dibuat_pada, diperbarui_pada)
                 VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?)',
                [$user, adminDefaultHash(), 'Administrator Konten Informasi', $user, 'admin', adminNow(), adminNow()]
            );
            $laporan[] = ['label' => 'Membuat akun admin', 'ok' => true, 'pesan' => 'username: ' . $user . ' (segera ganti password)'];
        } else {
            $laporan[] = ['label' => 'Membuat akun admin', 'ok' => true, 'pesan' => 'sudah ada (' . $ada . ' akun), dilewati'];
        }
    } catch (Throwable $ex) {
        $laporan[] = ['label' => 'Membuat akun admin', 'ok' => false, 'pesan' => $ex->getMessage()];
    }

    if (!$isiContoh) return $laporan;

    // 4. data contoh (hanya bila tabel benar-benar kosong)
    try {
        if ((int) adminValue('SELECT COUNT(*) FROM panduan', [], 0) === 0) {
            $contohPanduan = adminContohPanduan();
            foreach ($contohPanduan as $i => $p) {
                adminQ(
                    'INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, 1, ?, ?)',
                    [$p['judul'], $p['isi'], $p['ikon'], ($i + 1) * 10, adminNow(), adminNow()]
                );
            }
            $laporan[] = ['label' => 'Data contoh panduan', 'ok' => true, 'pesan' => count($contohPanduan) . ' item'];
        } else {
            $laporan[] = ['label' => 'Data contoh panduan', 'ok' => true, 'pesan' => 'sudah ada, dilewati'];
        }

        if ((int) adminValue('SELECT COUNT(*) FROM informasi', [], 0) === 0) {
            $contohInfo = adminContohInformasi();
            foreach ($contohInfo as $i => $inf) {
                adminQ(
                    'INSERT INTO informasi (kategori, judul, konten, gambar, tautan, tanggal, urutan, status_aktif, dibuat_pada, diperbarui_pada)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [$inf['kategori'], $inf['judul'], $inf['konten'], '', $inf['tautan'], date('Y-m-d'), ($i + 1) * 10, adminNow(), adminNow()]
                );
            }
            $laporan[] = ['label' => 'Data contoh informasi', 'ok' => true, 'pesan' => count($contohInfo) . ' kartu'];
        } else {
            $laporan[] = ['label' => 'Data contoh informasi', 'ok' => true, 'pesan' => 'sudah ada, dilewati'];
        }

        if ((int) adminValue('SELECT COUNT(*) FROM tarif', [], 0) === 0) {
            $contohTarif = adminContohTarif();
            foreach ($contohTarif as $i => $t) {
                adminQ(
                    'INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [$t['kategori'], $t['nama_layanan'], $t['satuan'], (int) $t['tarif'], $t['keterangan'], ($i + 1) * 10, adminNow(), adminNow()]
                );
            }
            $laporan[] = ['label' => 'Data contoh tarif', 'ok' => true, 'pesan' => count($contohTarif) . ' layanan (angka contoh — sesuaikan tarif resmi)'];
        } else {
            $laporan[] = ['label' => 'Data contoh tarif', 'ok' => true, 'pesan' => 'sudah ada, dilewati'];
        }

        if ((int) adminValue('SELECT COUNT(*) FROM slide', [], 0) === 0) {
            $contohSlide = adminContohSlide();
            foreach ($contohSlide as $i => $s) {
                adminQ(
                    'INSERT INTO slide (judul, subjudul, gambar, tautan, warna_latar, urutan, status_aktif, dibuat_pada, diperbarui_pada)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [$s['judul'], $s['subjudul'], '', $s['tautan'], $s['warna'], ($i + 1) * 10, adminNow(), adminNow()]
                );
            }
            $laporan[] = ['label' => 'Data contoh slide', 'ok' => true, 'pesan' => count($contohSlide) . ' slide (tanpa gambar — unggah lewat menu Slide)'];
        } else {
            $laporan[] = ['label' => 'Data contoh slide', 'ok' => true, 'pesan' => 'sudah ada, dilewati'];
        }
    } catch (Throwable $ex) {
        $laporan[] = ['label' => 'Data contoh', 'ok' => false, 'pesan' => $ex->getMessage()];
    }

    return $laporan;
}

// ===========================================================================
// H. PENANGAN SEMUA AKSI POST
// ===========================================================================

function adminHandlePost()
{
    $aksi = strtolower(trim((string) adminPost('aksi', '')));
    $page = adminPost('page', 'dashboard');

    $bolehPage = ['dashboard', 'slide', 'informasi', 'panduan', 'tarif', 'pengaturan', 'akun', 'sistem', 'login'];
    if (!in_array($page, $bolehPage, true)) $page = 'dashboard';

    $kembali = ($aksi === 'login' || $page === 'login') ? 'admin.php?page=login' : 'admin.php?page=' . $page;

    try {
        // ---------- LOGIN ----------
        if ($aksi === 'login') {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')) !== 'POST') {
                throw new AdminError('Metode request tidak diizinkan.');
            }
            if (!adminVerifyCsrf()) {
                throw new AdminError('Sesi berakhir. Muat ulang halaman login lalu coba lagi.');
            }
            $hasil = adminAttemptLogin(adminPost('username'), (string) adminPost('password', ''));
            if (!$hasil['ok']) {
                adminFlash('error', $hasil['pesan']);
                adminRedirect('admin.php?page=login');
                return;
            }
            adminFlash('sukses', 'Selamat datang, ' . ($hasil['user']['nama_lengkap'] ?: $hasil['user']['username']) . '.');
            adminRedirect('admin.php?page=dashboard');
            return;
        }

        // ---------- LOGOUT ----------
        if ($aksi === 'logout') {
            adminRequirePost();
            adminLogout();
            adminRedirect('admin.php?page=login&keluar=1');
            return;
        }

        // ---------- aksi lain: wajib login + CSRF ----------
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new AdminError('Metode request tidak diizinkan.');
        }
        if (!adminVerifyCsrf()) {
            throw new AdminError('Token keamanan tidak cocok. Muat ulang halaman lalu coba lagi.');
        }
        if (!adminIsLoggedIn()) {
            throw new AdminError('Silakan login terlebih dahulu.');
        }

        switch ($aksi) {
            // ---- simpan (tambah/ubah) slide / informasi / panduan ----
            case 'simpan': {
                $entitas = adminPost('entitas', '');
                $e       = adminEntity($entitas);
                $id      = adminPostInt('id', 0);

                $lama = null;
                if ($id > 0) {
                    $lama = adminRow($entitas, $id);
                    if (!$lama) throw new AdminError('Data yang mau diubah tidak ditemukan.');
                }

                $data = adminPrepareData($entitas, $_POST, $id);

                if (!empty($e['punyaGambar'])) {
                    $gambar = adminSimpanGambar('gambar', $e['prefix'], $lama['gambar'] ?? '', !empty($_POST['hapus_gambar']));
                    $data['gambar'] = adminLimit($gambar['file'], $e['panjang']['gambar'] ?? 255);
                }

                $newId = adminSaveRow($entitas, $data, $id);
                adminLog($id > 0 ? 'ubah_' . $entitas : 'tambah_' . $entitas,
                    ($id > 0 ? 'id=' . $id : 'id=' . $newId) . ' judul=' . adminLimit(adminJudulBaris($data), 60));
                adminFlash('sukses', $e['label'] . ' berhasil ' . ($id > 0 ? 'diperbarui' : 'ditambahkan') . '.');
                adminRedirect('admin.php?page=' . $entitas);
                return;
            }

            // ---- hapus ----
            case 'hapus': {
                $entitas = adminPost('entitas', '');
                $e       = adminEntity($entitas);
                $id      = adminPostInt('id', 0);
                $row     = adminRow($entitas, $id);
                if (!$row) throw new AdminError('Data tidak ditemukan.');

                if (!empty($e['punyaGambar']) && !empty($row['gambar'])) {
                    adminDeleteImage($row['gambar']);
                }
                adminDeleteRow($entitas, $id);
                adminLog('hapus_' . $entitas, 'id=' . $id . ' judul=' . adminLimit(adminJudulBaris($row), 60));
                adminFlash('sukses', $e['label'] . ' "' . adminLimit(adminJudulBaris($row), 40) . '" dihapus.');
                adminRedirect('admin.php?page=' . $entitas);
                return;
            }

            // ---- aktif / nonaktif ----
            case 'toggle': {
                $entitas = adminPost('entitas', '');
                $id      = adminPostInt('id', 0);
                $aktif   = adminToggleRow($entitas, $id);
                adminLog('toggle_' . $entitas, 'id=' . $id . ' → ' . ($aktif ? 'aktif' : 'nonaktif'));
                adminFlash('sukses', 'Status diubah menjadi ' . ($aktif ? 'AKTIF' : 'NONAKTIF') . '.');
                adminRedirect('admin.php?page=' . $entitas);
                return;
            }

            // ---- naik / turun urutan ----
            case 'urutan':
            case 'urutan_naik':
            case 'urutan_turun': {
                $entitas = adminPost('entitas', '');
                $id      = adminPostInt('id', 0);
                $arah    = ($aksi === 'urutan_turun' || adminPost('arah', 'naik') === 'turun') ? 'turun' : 'naik';
                $pindah  = adminMoveRow($entitas, $id, $arah);
                if (!$pindah) {
                    adminFlash('info', 'Posisi sudah paling ' . ($arah === 'naik' ? 'atas' : 'bawah') . '.');
                } else {
                    adminFlash('sukses', 'Urutan diperbarui.');
                }
                adminRedirect('admin.php?page=' . $entitas);
                return;
            }

            // ---- pengaturan halaman informasi ----
            case 'pengaturan_simpan': {
                $kirim  = adminPost('set', []);
                if (!is_array($kirim)) $kirim = [];
                $fields = adminSettingKeys();
                $jumlah = 0;

                foreach ($fields as $kunci => $f) {
                    $tipe  = $f['tipe'] ?? 'text';
                    $nilai = '';

                    if ($tipe === 'switch') {
                        $nilai = (isset($kirim[$kunci]) && in_array((string) $kirim[$kunci], ['1', 'on', 'true', 'ya'], true)) ? '1' : '0';
                    } else {
                        $raw = $kirim[$kunci] ?? '';
                        $nilai = trim(is_array($raw) ? '' : (string) $raw);
                    }

                    // validasi ringan per tipe
                    if ($tipe === 'color') {
                        if ($nilai !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $nilai)) {
                            throw new AdminError('Warna utama harus format hex, contoh: #1b5e20');
                        }
                        if ($nilai === '') $nilai = '#1b5e20';
                    }
                    if ($tipe === 'number') {
                        $nilai = (string) max(1000, min(60000, (int) $nilai));
                    }
                    if ($tipe === 'text' && $nilai !== '' && preg_match('/^\s*(javascript|vbscript)\s*:/i', $nilai)) {
                        throw new AdminError('Isi tidak diizinkan untuk field "' . $f['label'] . '".');
                    }
                    if (in_array($kunci, ['website', 'maps'], true) && $nilai !== '' && !preg_match('#^https?://#i', $nilai)) {
                        $nilai = 'https://' . ltrim($nilai, '/');
                    }

                    $nilai = adminLimit($nilai, 500);
                    $ada   = adminOne('SELECT kunci FROM pengaturan WHERE kunci = ?', [$kunci]);
                    if ($ada) {
                        adminQ('UPDATE pengaturan SET nilai = ?, diperbarui_pada = ? WHERE kunci = ?', [$nilai, adminNow(), $kunci]);
                    } else {
                        adminQ('INSERT INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?)',
                            [$kunci, $nilai, $f['label'], 'umum', $tipe, adminNow()]);
                    }
                    $jumlah++;
                }

                adminLog('ubah_pengaturan', $jumlah . ' kunci diperbarui');
                adminFlash('sukses', 'Pengaturan tersimpan (' . $jumlah . ' item). Halaman informasi.php langsung memakai nilai baru.');
                adminRedirect('admin.php?page=pengaturan');
                return;
            }

            // ---- data akun (nama, email) ----
            case 'akun_simpan': {
                $user  = adminCurrentUser();
                $nama  = adminLimit(adminPost('nama_lengkap'), 150);
                $email = adminLimit(adminPost('email'), 180);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new AdminError('Format email tidak valid.');
                }
                adminQ('UPDATE admin_users SET nama_lengkap = ?, email = ?, diperbarui_pada = ? WHERE id = ?',
                    [$nama, $email, adminNow(), (int) $user['id']]);
                adminLog('ubah_akun', 'profil diperbarui');
                adminFlash('sukses', 'Data akun diperbarui.');
                adminRedirect('admin.php?page=akun');
                return;
            }

            // ---- ganti password ----
            case 'password_ganti': {
                $user = adminCurrentUser();
                $lama = (string) adminPost('password_lama', '');
                $baru = (string) adminPost('password_baru', '');
                $conf = (string) adminPost('password_konfirmasi', '');

                if (!password_verify($lama, (string) $user['password_hash'])) {
                    throw new AdminError('Password lama tidak cocok.');
                }
                if (strlen($baru) < 8) {
                    throw new AdminError('Password baru minimal 8 karakter.');
                }
                if ($baru !== $conf) {
                    throw new AdminError('Konfirmasi password baru tidak sama.');
                }
                if ($baru === $lama) {
                    throw new AdminError('Password baru harus berbeda dari password lama.');
                }
                adminQ('UPDATE admin_users SET password_hash = ?, login_gagal = 0, terkunci_sampai = NULL, diperbarui_pada = ? WHERE id = ?',
                    [password_hash($baru, PASSWORD_BCRYPT), adminNow(), (int) $user['id']]);
                adminLog('ganti_password', 'password diubah');
                adminFlash('sukses', 'Password berhasil diganti.');
                adminRedirect('admin.php?page=akun');
                return;
            }

            // ---- jalankan instaler skema ----
            case 'setup': {
                $isiContoh = adminPost('isi_contoh', '1') !== '0';
                $laporan   = adminInstallSchema($isiContoh);
                $gagal     = 0;
                foreach ($laporan as $l) if (empty($l['ok'])) $gagal++;
                adminLog('setup_skema', $gagal === 0 ? 'berhasil' : ($gagal . ' langkah gagal'));
                if ($gagal === 0) {
                    adminFlash('sukses', 'Skema database siap. Semua langkah instalasi berhasil.');
                } else {
                    adminFlash('error', 'Ada ' . $gagal . ' langkah instalasi yang gagal — lihat rinciannya di bawah.');
                }
                $GLOBALS['ADMIN_SETUP_LAPORAN'] = $laporan;
                adminRedirect('admin.php?page=sistem');
                return;
            }

            default:
                throw new AdminError('Aksi tidak dikenal: ' . $aksi);
        }
    } catch (AdminError $ex) {
        adminFlash('error', $ex->getMessage());
        adminRedirect($kembali);
    } catch (Throwable $ex) {
        adminFlash('error', 'Terjadi kesalahan pada server: ' . $ex->getMessage());
        adminRedirect($kembali);
    }
}

// ===========================================================================
// I. IKON (SVG inline — tanpa CDN, aman untuk jaringan intranet rumah sakit)
// ===========================================================================

function adminIcoPaths()
{
    return [
        'dashboard' => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
        'image'     => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="M21 16.5l-4.5-4.5L7 21"/>',
        'cards'     => '<path d="M5 4h11v16H6.5A1.5 1.5 0 015 18.5z"/><path d="M16 8h3v10.5A1.5 1.5 0 0117.5 20H16"/><path d="M8 8.5h5M8 12h5M8 15.5h3"/>',
        'list'      => '<path d="M8.5 6H21M8.5 12H21M8.5 18H21"/><circle cx="4" cy="6" r="1.2"/><circle cx="4" cy="12" r="1.2"/><circle cx="4" cy="18" r="1.2"/>',
        'gear'      => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v2.4M12 18.8v2.4M4.6 4.6l1.7 1.7M17.7 17.7l1.7 1.7M2.8 12h2.4M18.8 12h2.4M4.6 19.4l1.7-1.7M17.7 6.3l1.7-1.7"/>',
        'user'      => '<circle cx="12" cy="8" r="3.8"/><path d="M4.5 20.5a7.5 7.5 0 0115 0"/>',
        'shield'    => '<path d="M12 21.5s7.5-3.6 7.5-9.6V5.4L12 2.6 4.5 5.4v6.5c0 6 7.5 9.6 7.5 9.6z"/><path d="M9.2 12.2l2 2 3.6-3.9"/>',
        'logout'    => '<path d="M9.5 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3.5"/><path d="M16 16.5l4.5-4.5L16 7.5"/><path d="M20.5 12H9.5"/>',
        'plus'      => '<path d="M12 5.5v13M5.5 12h13"/>',
        'edit'      => '<path d="M12 20.5h8.5"/><path d="M16.4 3.9a2.1 2.1 0 013 3L7.6 18.7 3.5 20l1.3-4.1z"/>',
        'trash'     => '<path d="M3.5 6.5h17"/><path d="M8.5 6.5V4h7v2.5"/><path d="M18.5 6.5l-.9 13.2a1.5 1.5 0 01-1.5 1.4H7.9a1.5 1.5 0 01-1.5-1.4L5.5 6.5"/><path d="M10 11v6M14 11v6"/>',
        'up'        => '<path d="M12 19V5.5"/><path d="M5.5 12L12 5.5 18.5 12"/>',
        'down'      => '<path d="M12 5v14"/><path d="M18.5 12L12 18.5 5.5 12"/>',
        'eye'       => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'check'     => '<path d="M20 6.5L9.2 17.3 4 12.1"/>',
        'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
        'upload'    => '<path d="M12 16.5V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3.5A1.5 1.5 0 005.5 21h13a1.5 1.5 0 001.5-1.5V16"/>',
        'external'  => '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14.5V19a1.5 1.5 0 01-1.5 1.5H5A1.5 1.5 0 013.5 19V7.5A1.5 1.5 0 015 6h4.5"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.8h.01"/>',
        'alert'     => '<path d="M12 3.5l9 16.5H3z"/><path d="M12 10v4"/><path d="M12 17.2h.01"/>',
        'refresh'   => '<path d="M20.5 12a8.5 8.5 0 11-2.7-6.2"/><path d="M20.5 4v5h-5"/>',
        'lock'      => '<rect x="4.5" y="10" width="15" height="10.5" rx="2.2"/><path d="M8 10V7.2a4 4 0 018 0V10"/>',
        'pasien'    => '<circle cx="12" cy="7.2" r="3.4"/><path d="M5.5 20.5a6.5 6.5 0 0113 0"/><path d="M12 12.4v3.4M10.3 14.1h3.4"/>',
        'kalender'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.2"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'hasil'     => '<path d="M6.5 3h8l4.5 4.5v13H6.5z"/><path d="M14.5 3v4.5H19"/><path d="M9.5 13h5M9.5 16.5h3.5"/>',
        'riwayat'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
        'obat'      => '<rect x="2.5" y="9" width="19" height="6" rx="3"/><path d="M12 9v6"/>',
        'bayar'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.2"/><path d="M2.5 10h19M6.5 15h4"/>',
        'tarif'     => '<path d="M20.5 12.5l-8 8a1.6 1.6 0 01-2.3 0l-6.2-6.2a1.6 1.6 0 01-.5-1.2V4.8A1.3 1.3 0 014.8 3.5h8.3c.4 0 .9.2 1.2.5l6.2 6.2c.6.6.6 1.7 0 2.3z"/><circle cx="8.2" cy="8.2" r="1.5"/><path d="M11.5 12.5h4M11.5 15.5h2.5"/>',
        'bantuan'   => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.6a2.5 2.5 0 114.9.8c0 1.6-2.5 2-2.5 3.4"/><path d="M12 17.2h.01"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'download'  => '<path d="M12 3.5v12"/><path d="M7 11l5 5 5-5"/><path d="M4 17v2.5A1.5 1.5 0 005.5 21h13a1.5 1.5 0 001.5-1.5V17"/>',
        'db'        => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'folder'    => '<path d="M3.5 6.5A1.5 1.5 0 015 5h4l2 2.5h8a1.5 1.5 0 011.5 1.5v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18z"/>',
        'pin'       => '<path d="M12 21.5s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10.2" r="2.6"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
    ];
}

/** Cetak ikon SVG. */
function adminIco($nama, $ukuran = 18, $extraClass = '')
{
    $paths = adminIcoPaths();
    $isi   = $paths[$nama] ?? $paths['info'];
    $cls   = $extraClass !== '' ? ' class="' . e($extraClass) . '"' : '';
    return '<svg' . $cls . ' width="' . (int) $ukuran . '" height="' . (int) $ukuran
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $isi . '</svg>';
}

/** Logo RSUD (dipakai di halaman login & header panel). */
function adminLogo($ukuran = 44)
{
    $u = (int) $ukuran;
    return '<svg width="' . $u . '" height="' . $u . '" viewBox="0 0 48 48" fill="none" aria-hidden="true">'
        . '<rect width="48" height="48" rx="13" fill="#fff"/>'
        . '<path d="M24 9v30M9 24h30" stroke="#1b5e20" stroke-width="6" stroke-linecap="round"/>'
        . '<circle cx="24" cy="24" r="9" fill="#2e7d32" stroke="#fff" stroke-width="3"/>'
        . '<circle cx="24" cy="24" r="3" fill="#fff"/></svg>';
}

// ===========================================================================
// J. STYLE & SCRIPT PANEL
// ===========================================================================

function adminCss()
{
    $brand = adminSetting('warna_utama', '#1b5e20');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) $brand = '#1b5e20';
    echo '<style>:root{--brand:' . e($brand) . ';}</style>' . "\n";
    echo <<<'CSS'
<style>
  :root{
    --brand-dark:#123c16; --brand-soft:#e9f3ea; --brand-soft2:#f3f8f3;
    --bg:#f3f5f3; --card:#ffffff; --line:#e5e7eb; --line2:#eef1ee;
    --ink:#131a14; --muted:#6b7280; --muted2:#9aa3a0;
    --ok:#15803d; --okbg:#e7f6ec; --err:#b42318; --errbg:#fdecea;
    --warn:#b45309; --warnbg:#fff5e6; --infoblue:#1d4ed8; --infobg:#eaf1ff;
    --radius:14px; --radius-sm:10px;
    --shadow:0 1px 2px rgba(16,40,20,.05), 0 10px 26px rgba(16,40,20,.06);
    --sidebar:248px;
  }
  *{margin:0;padding:0;box-sizing:border-box}
  html{-webkit-text-size-adjust:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    background:var(--bg); color:var(--ink); font-size:14px; line-height:1.55;
    -webkit-font-smoothing:antialiased;
  }
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}
  button{font:inherit}
  img{max-width:100%}

  /* ---------- layout ---------- */
  .shell{display:flex;min-height:100vh}
  .sidebar{
    width:var(--sidebar);flex:none;background:linear-gradient(180deg,#14451a,var(--brand) 60%,#2f7d34);
    color:#eaf5eb;padding:18px 14px;position:sticky;top:0;height:100vh;overflow-y:auto;
  }
  .side-brand{display:flex;align-items:center;gap:10px;padding:4px 6px 16px}
  .side-brand .lg{width:38px;height:38px;flex:none;border-radius:11px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.18)}
  .side-brand .lg svg{width:26px;height:26px}
  .side-brand b{display:block;font-size:13.5px;color:#fff;line-height:1.25}
  .side-brand span{display:block;font-size:10.5px;opacity:.78}
  .side-title{font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.6;padding:14px 8px 6px}
  .nav a{
    display:flex;align-items:center;gap:10px;padding:9.5px 10px;border-radius:11px;
    color:#e8f3e9;font-size:13px;font-weight:600;margin-bottom:3px;transition:background .15s,transform .05s;
  }
  .nav a:hover{background:rgba(255,255,255,.13);text-decoration:none}
  .nav a.aktif{background:#fff;color:var(--brand);box-shadow:0 6px 16px rgba(0,0,0,.18)}
  .nav a .cnt{margin-left:auto;font-size:10.5px;font-weight:800;background:rgba(255,255,255,.2);padding:1px 7px;border-radius:999px}
  .nav a.aktif .cnt{background:var(--brand-soft);color:var(--brand)}
  .side-foot{margin-top:18px;padding:12px 10px;border-top:1px solid rgba(255,255,255,.18);font-size:10.5px;opacity:.75;line-height:1.6}

  .main{flex:1;min-width:0;display:flex;flex-direction:column}
  .topbar{
    position:sticky;top:0;z-index:20;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);
    border-bottom:1px solid var(--line);padding:12px 20px;display:flex;align-items:center;gap:12px;
  }
  .topbar h1{font-size:16px;font-weight:800;letter-spacing:-.1px}
  .topbar .sub{font-size:11.5px;color:var(--muted);margin-top:1px}
  .topbar .spacer{margin-left:auto}
  .burger{display:none;width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--ink);align-items:center;justify-content:center;cursor:pointer}
  .content{padding:20px;max-width:1240px;width:100%}

  /* ---------- komponen ---------- */
  .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
  .card-hd{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line2)}
  .card-hd h2{font-size:13.5px;font-weight:800;letter-spacing:.01em}
  .card-hd .sub{font-size:11.5px;color:var(--muted);margin-top:1px}
  .card-hd .right{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .card-bd{padding:16px}
  .grid{display:grid;gap:16px}
  .grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
  .grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
  .stack{display:flex;flex-direction:column;gap:16px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

  .btn{
    display:inline-flex;align-items:center;gap:7px;padding:8.5px 14px;border-radius:10px;
    border:1px solid var(--line);background:#fff;color:var(--ink);font-size:12.5px;font-weight:700;
    cursor:pointer;transition:transform .05s,box-shadow .15s,background .15s;white-space:nowrap;
  }
  .btn:hover{background:#fafbfa;box-shadow:0 2px 8px rgba(16,40,20,.08);text-decoration:none}
  .btn:active{transform:translateY(1px)}
  .btn-p{background:var(--brand);border-color:var(--brand);color:#fff}
  .btn-p:hover{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff}
  .btn-d{color:var(--err);border-color:#f3cfcb;background:#fff}
  .btn-d:hover{background:var(--errbg)}
  .btn-s{padding:6px 10px;font-size:11.5px;border-radius:9px}
  .btn-ico{padding:7px;width:32px;height:32px;justify-content:center}
  .btn[disabled]{opacity:.5;cursor:not-allowed}

  .badge{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:800;letter-spacing:.03em;padding:3px 9px;border-radius:999px;text-transform:uppercase}
  .badge-ok{background:var(--okbg);color:var(--ok)}
  .badge-off{background:#eef0ef;color:#6b7280}
  .badge-cat{background:var(--brand-soft);color:var(--brand);text-transform:none;letter-spacing:0;font-weight:700}
  .badge-warn{background:var(--warnbg);color:var(--warn)}
  .badge-err{background:var(--errbg);color:var(--err)}

  .alert{display:flex;gap:10px;align-items:flex-start;padding:11px 14px;border-radius:12px;font-size:12.5px;font-weight:600;border:1px solid transparent;margin-bottom:14px}
  .alert svg{flex:none;margin-top:1px}
  .alert-ok{background:var(--okbg);color:#14532d;border-color:#c6e7cf}
  .alert-err{background:var(--errbg);color:#7f1d1d;border-color:#f5cfca}
  .alert-info{background:var(--infobg);color:#1e3a8a;border-color:#cddcff}
  .alert-warn{background:var(--warnbg);color:#7c3f06;border-color:#f6dfb8}

  /* ---------- form ---------- */
  .field{margin-bottom:14px}
  .field > label{display:block;font-size:12px;font-weight:800;margin-bottom:5px;color:#22312a}
  .field .ket{font-size:11px;color:var(--muted);margin-top:4px;line-height:1.5}
  .req{color:var(--err)}
  input[type=text],input[type=password],input[type=email],input[type=url],input[type=date],input[type=number],select,textarea{
    width:100%;padding:9.5px 11px;border:1px solid var(--line);border-radius:10px;background:#fff;
    font-size:13px;color:var(--ink);font-family:inherit;transition:border-color .15s,box-shadow .15s;
  }
  textarea{min-height:104px;resize:vertical;line-height:1.6}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(27,94,32,.13)}
  input[type=color]{width:46px;height:36px;padding:2px;border:1px solid var(--line);border-radius:9px;background:#fff;cursor:pointer}
  input[type=file]{width:100%;font-size:12px;padding:8px;border:1px dashed var(--line);border-radius:10px;background:#fcfdfc;cursor:pointer}
  input[type=file]::file-selector-button{
    margin-right:10px;padding:6px 12px;border-radius:8px;border:1px solid var(--line);
    background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:11.5px;cursor:pointer;
  }
  .two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
  .switch{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--line);border-radius:11px;background:#fcfdfc;cursor:pointer}
  .switch input{position:absolute;opacity:0;width:0;height:0}
  .switch .track{width:40px;height:23px;border-radius:999px;background:#d6dbd7;position:relative;transition:background .18s;flex:none}
  .switch .track::after{content:"";position:absolute;top:2.5px;left:2.5px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .18s}
  .switch input:checked + .track{background:var(--brand)}
  .switch input:checked + .track::after{transform:translateX(17px)}
  .switch input:focus-visible + .track{box-shadow:0 0 0 3px rgba(27,94,32,.2)}
  .switch .txt b{display:block;font-size:12.5px;font-weight:700}
  .switch .txt span{display:block;font-size:11px;color:var(--muted)}

  .drop{border:1px dashed var(--line);border-radius:12px;padding:12px;background:#fcfdfc}
  .drop .prev{display:flex;gap:12px;align-items:center}
  .drop img{width:104px;height:70px;object-fit:cover;border-radius:9px;border:1px solid var(--line);background:#fff}
  .drop .noprev{width:104px;height:70px;border-radius:9px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;flex:none}

  /* ---------- tabel ---------- */
  .tbl-wrap{overflow-x:auto}
  table.tbl{width:100%;border-collapse:collapse;font-size:12.5px}
  table.tbl th{
    text-align:left;font-size:10.5px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);
    padding:10px 12px;border-bottom:1px solid var(--line);background:#fbfcfb;white-space:nowrap;font-weight:800;
  }
  table.tbl td{padding:11px 12px;border-bottom:1px solid var(--line2);vertical-align:middle}
  table.tbl tr:last-child td{border-bottom:0}
  table.tbl tbody tr:hover{background:#fbfdfb}
  .thumb{width:74px;height:50px;object-fit:cover;border-radius:8px;border:1px solid var(--line);background:#fff;display:block}
  .thumb-ph{width:74px;height:50px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:800}
  .cell-judul{font-weight:700;color:#16211a;max-width:340px}
  .cell-sub{font-size:11px;color:var(--muted);margin-top:2px;max-width:340px}
  .aksi{display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap}
  .kosong{padding:34px 18px;text-align:center;color:var(--muted)}
  .kosong .ic{width:52px;height:52px;border-radius:50%;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;margin:0 auto 10px}
  .kosong b{display:block;color:#26332b;font-size:13.5px;margin-bottom:3px}
  .kosong p{font-size:12px;max-width:400px;margin:0 auto}

  /* ---------- statistik dashboard ---------- */
  .stat{padding:14px 16px;display:flex;align-items:center;gap:12px}
  .stat .ic{width:42px;height:42px;border-radius:12px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;flex:none}
  .stat b{display:block;font-size:20px;font-weight:800;line-height:1.1}
  .stat span{display:block;font-size:11.5px;color:var(--muted);margin-top:2px}
  .kv{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid var(--line2);font-size:12.5px}
  .kv:first-child{border-top:0}
  .kv .k{color:var(--muted);font-weight:600}
  .kv .v{font-weight:700;text-align:right;word-break:break-word}

  code.mono, .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px}
  .kotak-kode{background:#0f1a12;color:#d6e8d8;border-radius:12px;padding:12px 14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;overflow-x:auto;line-height:1.7;white-space:pre}
  .hint{font-size:11.5px;color:var(--muted);line-height:1.6}

  /* ---------- halaman login ---------- */
  .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;
    background:radial-gradient(1200px 500px at 15% -10%,#2f7d34 0%,transparent 60%),linear-gradient(160deg,#0f3d17,#1b5e20 55%,#2e7d32)}
  .login-card{width:100%;max-width:400px;background:#fff;border-radius:20px;box-shadow:0 26px 60px rgba(0,0,0,.3);overflow:hidden}
  .login-hd{padding:24px 24px 16px;text-align:center}
  .login-hd .lg{width:58px;height:58px;margin:0 auto 12px;border-radius:16px;background:var(--brand-soft);display:flex;align-items:center;justify-content:center}
  .login-hd h1{font-size:17px;font-weight:800}
  .login-hd p{font-size:12px;color:var(--muted);margin-top:3px}
  .login-bd{padding:6px 24px 24px}
  .login-ft{padding:12px 24px;background:#fafbfa;border-top:1px solid var(--line2);font-size:10.5px;color:var(--muted);text-align:center;line-height:1.6}

  /* ---------- responsif ---------- */
  .scrim{display:none}
  @media (max-width:1020px){
    .grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}
    .grid-3{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @media (max-width:860px){
    .sidebar{position:fixed;z-index:60;left:0;top:0;transform:translateX(-102%);transition:transform .22s ease;box-shadow:0 0 40px rgba(0,0,0,.35)}
    body.nav-open .sidebar{transform:translateX(0)}
    body.nav-open .scrim{display:block;position:fixed;inset:0;background:rgba(9,20,12,.45);z-index:55}
    .burger{display:inline-flex}
    .content{padding:14px}
    .topbar{padding:10px 14px}
    .grid-4,.grid-3,.grid-2,.two{grid-template-columns:minmax(0,1fr)}
    .cell-judul,.cell-sub{max-width:200px}
  }
  @media print{ .sidebar,.topbar,.aksi{display:none!important} }
</style>
CSS;
}

function adminJs()
{
    echo <<<'JS'
<script>
(function(){
  // buka/tutup sidebar di layar kecil
  var burger = document.querySelector('[data-burger]');
  var scrim  = document.querySelector('[data-scrim]');
  function closeNav(){ document.body.classList.remove('nav-open'); }
  if (burger) burger.addEventListener('click', function(){ document.body.classList.toggle('nav-open'); });
  if (scrim)  scrim.addEventListener('click', closeNav);

  // konfirmasi sebelum submit form berbahaya
  document.querySelectorAll('form[data-confirm]').forEach(function(f){
    f.addEventListener('submit', function(ev){
      if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); return false; }
    });
  });

  // konfirmasi per tombol (mis. tombol Hapus di dalam form baris tabel)
  document.querySelectorAll('button[data-confirm]').forEach(function(b){
    b.addEventListener('click', function(ev){
      if (!window.confirm(b.getAttribute('data-confirm'))) {
        ev.preventDefault(); ev.stopPropagation(); return false;
      }
    });
  });

  // pratinjau gambar saat file dipilih
  document.querySelectorAll('input[type=file][data-preview]').forEach(function(inp){
    inp.addEventListener('change', function(){
      var box = document.querySelector(inp.getAttribute('data-preview'));
      if (!box) return;
      var file = inp.files && inp.files[0];
      if (!file) return;
      if (file.size > 0 && box.getAttribute('data-max') && file.size > parseInt(box.getAttribute('data-max'), 10)) {
        box.innerHTML = '<div class="alert alert-err" style="margin:0">' +
          'Ukuran file melebihi batas ' + box.getAttribute('data-max-label') + '. Pilih file lain.</div>';
        inp.value = '';
        return;
      }
      var url = URL.createObjectURL(file);
      box.innerHTML = '<img src="' + url + '" alt="Pratinjau gambar baru">';
    });
  });

  // textarea tumbuh otomatis
  document.querySelectorAll('textarea[data-autogrow]').forEach(function(t){
    function grow(){ t.style.height = 'auto'; t.style.height = Math.min(520, t.scrollHeight + 2) + 'px'; }
    t.addEventListener('input', grow); grow();
  });

  // penghitung karakter
  document.querySelectorAll('[data-counter]').forEach(function(t){
    var out = document.querySelector(t.getAttribute('data-counter'));
    if (!out) return;
    var max = parseInt(t.getAttribute('maxlength') || '0', 10);
    function upd(){ out.textContent = (t.value || '').length + (max ? ' / ' + max : '') + ' karakter'; }
    t.addEventListener('input', upd); upd();
  });

  // sembunyikan alert otomatis setelah 6 detik
  setTimeout(function(){
    document.querySelectorAll('.alert[data-autohide]').forEach(function(a){
      a.style.transition = 'opacity .5s, transform .5s';
      a.style.opacity = '0'; a.style.transform = 'translateY(-6px)';
      setTimeout(function(){ a.remove(); }, 520);
    });
  }, 6000);

  // toggle tampil password
  document.querySelectorAll('[data-toggle-pass]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inp = document.querySelector(btn.getAttribute('data-toggle-pass'));
      if (!inp) return;
      inp.type = (inp.type === 'password') ? 'text' : 'password';
      btn.textContent = (inp.type === 'password') ? 'Lihat' : 'Sembunyikan';
    });
  });

  // input warna → sinkron dengan field teks hex
  document.querySelectorAll('input[type=color][data-sync]').forEach(function(c){
    var t = document.querySelector(c.getAttribute('data-sync'));
    if (!t) return;
    c.addEventListener('input', function(){ t.value = c.value; });
    t.addEventListener('input', function(){ if (/^#[0-9a-fA-F]{6}$/.test(t.value)) c.value = t.value; });
  });
})();
</script>
JS;
}

// ===========================================================================
// K. KERANGKA HALAMAN (layout)
// ===========================================================================

function adminMenuItems()
{
    $menu = [
        'dashboard'  => ['label' => 'Dashboard',            'ikon' => 'dashboard', 'sub' => 'Ringkasan konten halaman informasi'],
        'slide'      => ['label' => 'Slide Gambar',         'ikon' => 'image',     'sub' => 'Slider bergambar di bagian atas halaman'],
        'informasi'  => ['label' => 'Kartu Informasi',      'ikon' => 'cards',     'sub' => 'Pengumuman & berita berbentuk kartu'],
        'panduan'    => ['label' => 'Panduan (Dropdown)',   'ikon' => 'list',      'sub' => 'Langkah pemakaian aplikasi, tampil sebagai dropdown'],
        'tarif'      => ['label' => 'Tarif Layanan',        'ikon' => 'tarif',     'sub' => 'Daftar biaya layanan, tampil di tab Tarif pada halaman informasi'],
        'pengaturan' => ['label' => 'Pengaturan Halaman',   'ikon' => 'gear',      'sub' => 'Judul seksi, kontak, warna, footer'],
        'akun'       => ['label' => 'Akun Saya',            'ikon' => 'user',      'sub' => 'Profil & ganti password'],
        'sistem'     => ['label' => 'Cek Sistem',           'ikon' => 'shield',    'sub' => 'Koneksi database, folder upload, skema'],
    ];
    return $menu;
}

/** Hitung baris untuk badge menu (aman walau tabel belum ada). */
function adminMenuCount($key)
{
    try {
        return adminCount($key);
    } catch (Throwable $ex) {
        return null;
    }
}

function adminAlerts()
{
    foreach (adminTakeFlash() as $f) {
        $tipe = $f['tipe'] ?? 'info';
        $map  = ['sukses' => ['alert-ok', 'check'], 'error' => ['alert-err', 'alert'], 'info' => ['alert-info', 'info'], 'warn' => ['alert-warn', 'alert']];
        $m    = $map[$tipe] ?? $map['info'];
        echo '<div class="alert ' . $m[0] . '" data-autohide role="status">'
            . adminIco($m[1], 17)
            . '<div>' . e($f['pesan']) . '</div></div>' . "\n";
    }
}

function adminLayoutStart($judul, $sub, $menuAktif)
{
    $GLOBALS['ADMIN_LAYOUT_OPEN'] = true;
    adminHeader('Content-Type: text/html; charset=UTF-8');
    adminHeader('X-Frame-Options: SAMEORIGIN');
    adminHeader('Referrer-Policy: same-origin');
    adminHeader('Cache-Control: no-store');

    $user = adminCurrentUser();
    $menu = adminMenuItems();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo e($judul); ?> — Admin Informasi RSUD Malangbong</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'><rect width='48' height='48' rx='12' fill='%231b5e20'/><path d='M24 11v26M11 24h26' stroke='white' stroke-width='7' stroke-linecap='round'/></svg>">
<?php adminCss(); ?>
</head>
<body>
<div class="shell">
  <div class="scrim" data-scrim></div>
  <aside class="sidebar">
    <div class="side-brand">
      <span class="lg"><?php echo adminLogo(38); ?></span>
      <span>
        <b>Admin Informasi</b>
        <span>RSUD Malangbong</span>
      </span>
    </div>

    <div class="side-title">Konten</div>
    <nav class="nav">
      <?php foreach (['dashboard', 'slide', 'informasi', 'panduan'] as $k): $it = $menu[$k]; $cnt = adminMenuCount($k); ?>
        <a href="admin.php?page=<?php echo e($k); ?>"<?php echo $menuAktif === $k ? ' class="aktif"' : ''; ?>>
          <?php echo adminIco($it['ikon'], 17); ?>
          <span><?php echo e($it['label']); ?></span>
          <?php if ($cnt !== null && $k !== 'dashboard'): ?><span class="cnt"><?php echo (int) $cnt; ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="side-title">Sistem</div>
    <nav class="nav">
      <?php foreach (['pengaturan', 'akun', 'sistem'] as $k): $it = $menu[$k]; ?>
        <a href="admin.php?page=<?php echo e($k); ?>"<?php echo $menuAktif === $k ? ' class="aktif"' : ''; ?>>
          <?php echo adminIco($it['ikon'], 17); ?>
          <span><?php echo e($it['label']); ?></span>
        </a>
      <?php endforeach; ?>
      <form method="post" action="admin.php" data-confirm="Keluar dari panel admin?">
        <?php echo adminCsrfField(); ?>
        <input type="hidden" name="aksi" value="logout">
        <button class="btn btn-s" type="submit" style="width:100%;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.22);color:#eaf5eb;justify-content:flex-start">
          <?php echo adminIco('logout', 16); ?> Keluar
        </button>
      </form>
    </nav>

    <div class="side-foot">
      Masuk sebagai<br><b style="color:#fff"><?php echo e($user['nama_lengkap'] ?: $user['username']); ?></b><br>
      <?php echo e(date('d M Y, H:i')); ?> WIB
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <button class="burger" type="button" data-burger aria-label="Buka menu"><?php echo adminIco('menu', 18); ?></button>
      <div>
        <h1><?php echo e($judul); ?></h1>
        <div class="sub"><?php echo e($sub); ?></div>
      </div>
      <div class="spacer"></div>
      <a class="btn btn-s" href="informasi.php" target="_blank" rel="noopener"><?php echo adminIco('external', 15); ?> Lihat Halaman</a>
      <a class="btn btn-s btn-p" href="admin.php?page=dashboard"><?php echo adminIco('refresh', 15); ?> Muat Ulang</a>
    </div>

    <main class="content">
      <?php adminAlerts(); ?>
    <?php
}

function adminLayoutEnd()
{
    ?>
    </main>
  </div>
</div>
<?php adminJs(); ?>
</body>
</html>
    <?php
}

// ===========================================================================
// L. HALAMAN LOGIN
// ===========================================================================

function adminViewLogin()
{
    adminHeader('Content-Type: text/html; charset=UTF-8');
    adminHeader('Cache-Control: no-store');
    adminHeader('X-Frame-Options: DENY');
    $db = adminDbStatus();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Masuk — Admin Informasi RSUD Malangbong</title>
<?php adminCss(); ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-hd">
      <div class="lg"><?php echo adminLogo(40); ?></div>
      <h1>Panel Admin Informasi</h1>
      <p>Aplikasi Mobile RSUD Malangbong</p>
    </div>

    <div class="login-bd">
      <?php adminAlerts(); ?>

      <?php if (!$db['ok']): ?>
        <div class="alert alert-err">
          <?php echo adminIco('db', 17); ?>
          <div>
            <b>Database admin belum tersambung.</b><br>
            <span style="font-weight:500"><?php echo e($db['pesan']); ?></span><br>
            <span class="mono">Periksa backend/admin-config.php</span>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="admin.php?page=login" autocomplete="on">
        <?php echo adminCsrfField(); ?>
        <input type="hidden" name="aksi" value="login">
        <input type="hidden" name="page" value="login">

        <div class="field">
          <label for="username">Username / Email <span class="req">*</span></label>
          <input type="text" id="username" name="username" value="<?php echo e(adminPost('username')); ?>"
                 autocomplete="username" required autofocus placeholder="nama@contoh.go.id">
        </div>

        <div class="field">
          <label for="password">Password <span class="req">*</span></label>
          <div class="row" style="gap:8px">
            <input type="password" id="password" name="password" autocomplete="current-password" required
                   placeholder="••••••••" style="flex:1">
            <button type="button" class="btn btn-s" data-toggle-pass="#password">Lihat</button>
          </div>
        </div>

        <button class="btn btn-p" type="submit" style="width:100%;justify-content:center;padding:11px">
          <?php echo adminIco('lock', 16); ?> Masuk ke Panel
        </button>
      </form>

      <p class="hint" style="margin-top:12px;text-align:center">
        Akun dikunci sementara setelah <?php echo (int) ADMIN_MAX_LOGIN_GAGAL; ?> kali salah password.
      </p>
    </div>

    <div class="login-ft">
      Database: <span class="mono"><?php echo e($GLOBALS['ADMIN_DB_CONFIG']['name']); ?></span>
      &nbsp;•&nbsp; &copy; <?php echo date('Y'); ?> RSUD Malangbong
    </div>
  </div>
</div>
<?php adminJs(); ?>
</body>
</html>
    <?php
}

// ===========================================================================
// M. DASHBOARD
// ===========================================================================

/** Statistik folder upload. */
function adminUploadStats()
{
    $jumlah = 0; $bytes = 0;
    if (is_dir(ADMIN_UPLOAD_DIR)) {
        foreach ((array) scandir(ADMIN_UPLOAD_DIR) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = ADMIN_UPLOAD_DIR . DIRECTORY_SEPARATOR . $f;
            if (is_file($p) && !in_array($f, ['.htaccess', 'index.html'], true)) {
                $jumlah++;
                $bytes += (int) @filesize($p);
            }
        }
    }
    return ['jumlah' => $jumlah, 'bytes' => $bytes];
}

/** Tabel inti yang belum ada (untuk peringatan di dashboard). */
function adminMissingTables()
{
    $kurang = [];
    foreach (adminCoreTables() as $t) {
        if (!adminTableExists($t)) $kurang[] = $t;
    }
    return $kurang;
}

function adminViewDashboard()
{
    adminLayoutStart('Dashboard', adminMenuItems()['dashboard']['sub'], 'dashboard');

    $db     = adminDbStatus();
    $kurang = $db['ok'] ? adminMissingTables() : adminCoreTables();
    $up     = adminUploadStats();
    ?>

    <?php if (!$db['ok']): ?>
      <div class="alert alert-err">
        <?php echo adminIco('db', 18); ?>
        <div>
          <b>Database admin belum tersambung.</b><br>
          <?php echo e($db['pesan']); ?><br>
          <span class="mono">Konfigurasi: backend/admin-config.php</span>
        </div>
      </div>
    <?php elseif (!empty($kurang)): ?>
      <div class="alert alert-warn">
        <?php echo adminIco('alert', 18); ?>
        <div>
          <b>Skema database belum lengkap.</b> Tabel yang belum ada:
          <span class="mono"><?php echo e(implode(', ', $kurang)); ?></span>.<br>
          Import file <span class="mono">backend/sql/admin_info_rsudmobile.sql</span>,
          atau jalankan <a href="admin.php?page=sistem">Cek Sistem → Instalasi otomatis</a>.
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-ok" data-autohide>
        <?php echo adminIco('check', 18); ?>
        <div>Semua sistem siap. Konten yang Anda ubah di sini langsung tampil pada tab <b>Informasi</b> di aplikasi.</div>
      </div>
    <?php endif; ?>

    <div class="grid grid-4" style="margin-bottom:16px">
      <?php
      $kartu = [
          ['slide',     'Slide',       'image',  'Gambar slider di atas halaman'],
          ['informasi', 'Informasi',   'cards',  'Kartu pengumuman & berita'],
          ['panduan',   'Panduan',     'list',   'Item dropdown panduan'],
          ['tarif',     'Tarif',       'tarif',  'Biaya layanan di tab Tarif'],
      ];
      foreach ($kartu as $c):
          $total = adminMenuCount($c[0]);
          $aktif = null;
          try { $aktif = adminCount($c[0], true); } catch (Throwable $ex) { $aktif = null; }
      ?>
        <a class="card stat" href="admin.php?page=<?php echo e($c[0]); ?>" style="text-decoration:none;color:inherit">
          <span class="ic"><?php echo adminIco($c[2], 21); ?></span>
          <span>
            <b><?php echo $total === null ? '–' : (int) $total; ?></b>
            <span><?php echo e($c[1]); ?><?php echo $aktif !== null ? ' • ' . (int) $aktif . ' aktif' : ''; ?></span>
            <span style="font-size:10.5px;color:var(--muted2)"><?php echo e($c[3]); ?></span>
          </span>
        </a>
      <?php endforeach; ?>
      <div class="card stat">
        <span class="ic"><?php echo adminIco('folder', 21); ?></span>
        <span>
          <b><?php echo (int) $up['jumlah']; ?></b>
          <span>Gambar terunggah</span>
          <span style="font-size:10.5px;color:var(--muted2)"><?php echo e(adminFormatBytes($up['bytes'])); ?> di folder uploads/</span>
        </span>
      </div>
    </div>

    <div class="grid grid-2">
      <section class="card">
        <div class="card-hd">
          <div><h2>Aksi Cepat</h2><div class="sub">Pekerjaan yang paling sering dilakukan</div></div>
        </div>
        <div class="card-bd">
          <div class="row">
            <a class="btn btn-p" href="admin.php?page=slide&baru=1"><?php echo adminIco('plus', 16); ?> Tambah Slide</a>
            <a class="btn" href="admin.php?page=tarif&baru=1"><?php echo adminIco('plus', 16); ?> Tambah Tarif</a>
            <a class="btn" href="admin.php?page=informasi&baru=1"><?php echo adminIco('plus', 16); ?> Tambah Informasi</a>
            <a class="btn" href="admin.php?page=panduan&baru=1"><?php echo adminIco('plus', 16); ?> Tambah Panduan</a>
          </div>
          <div class="row" style="margin-top:10px">
            <a class="btn" href="admin.php?page=pengaturan"><?php echo adminIco('gear', 16); ?> Ubah Kontak &amp; Footer</a>
            <a class="btn" href="informasi.php" target="_blank" rel="noopener"><?php echo adminIco('eye', 16); ?> Pratinjau Halaman</a>
            <a class="btn" href="admin.php?page=sistem"><?php echo adminIco('shield', 16); ?> Cek Sistem</a>
          </div>
          <p class="hint" style="margin-top:12px">
            Gambar yang diunggah otomatis diperkecil ke maksimal <?php echo (int) ADMIN_MAX_IMAGE_WIDTH; ?> px
            dan dibatasi <?php echo e(adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES)); ?> per file.
          </p>
        </div>
      </section>

      <section class="card">
        <div class="card-hd">
          <div><h2>Status Sistem</h2><div class="sub">Ringkas — detail lengkap di menu Cek Sistem</div></div>
        </div>
        <div class="card-bd">
          <?php
          $gd      = function_exists('imagecreatefromstring');
          $finfo   = function_exists('finfo_open');
          $tulis   = adminUploadDirWritable();
          $baris   = [
              ['Database admin', $db['ok'] ? 'Tersambung' : 'GAGAL', $db['ok']],
              ['Tabel lengkap', empty($kurang) ? 'Ya (' . count(adminCoreTables()) . ' tabel)' : 'Kurang: ' . implode(', ', $kurang), empty($kurang)],
              ['Folder upload', $tulis ? 'Bisa ditulis' : 'TIDAK bisa ditulis', $tulis],
              ['Ekstensi GD (perkecil gambar)', $gd ? 'Tersedia' : 'Tidak ada — gambar disimpan apa adanya', $gd],
              ['Deteksi mime (finfo)', $finfo ? 'Tersedia' : 'Tidak ada — pakai getimagesize', $finfo],
              ['Versi PHP', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='),],
          ];
          foreach ($baris as $b): ?>
            <div class="kv">
              <span class="k"><?php echo e($b[0]); ?></span>
              <span class="v">
                <span class="badge <?php echo $b[2] ? 'badge-ok' : 'badge-err'; ?>"><?php echo e($b[1]); ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <?php
    // konten terbaru
    try {
        $terbaru = adminAll('SELECT * FROM informasi ORDER BY diperbarui_pada DESC LIMIT 5');
    } catch (Throwable $ex) {
        $terbaru = [];
    }
    try {
        $log = adminAll('SELECT * FROM admin_log ORDER BY id DESC LIMIT 8');
    } catch (Throwable $ex) {
        $log = [];
    }
    ?>
    <div class="grid grid-2" style="margin-top:16px">
      <section class="card">
        <div class="card-hd">
          <div><h2>Kartu Informasi Terbaru</h2><div class="sub">5 yang terakhir diperbarui</div></div>
          <div class="right"><a class="btn btn-s" href="admin.php?page=informasi">Kelola</a></div>
        </div>
        <div class="card-bd" style="padding:6px 16px 12px">
          <?php if (empty($terbaru)): ?>
            <p class="hint" style="padding:10px 0">Belum ada kartu informasi.</p>
          <?php else: foreach ($terbaru as $r): ?>
            <div class="kv">
              <span class="k">
                <span class="badge badge-cat"><?php echo e($r['kategori']); ?></span>
                <span style="color:#16211a;font-weight:700;margin-left:6px"><?php echo e(adminRingkas($r['judul'], 48)); ?></span>
              </span>
              <span class="v" style="font-weight:600;color:var(--muted)">
                <?php echo (int) $r['status_aktif'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
              </span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="card">
        <div class="card-hd">
          <div><h2>Aktivitas Terakhir</h2><div class="sub">Jejak perubahan konten</div></div>
        </div>
        <div class="card-bd" style="padding:6px 16px 12px">
          <?php if (empty($log)): ?>
            <p class="hint" style="padding:10px 0">Belum ada aktivitas tercatat.</p>
          <?php else: foreach ($log as $l): ?>
            <div class="kv">
              <span class="k">
                <b style="color:#16211a"><?php echo e($l['aktivitas']); ?></b>
                <?php echo e($l['keterangan']); ?>
              </span>
              <span class="v" style="font-weight:600;color:var(--muted);white-space:nowrap">
                <?php echo e(date('d/m H:i', (int) strtotime((string) $l['waktu']))); ?>
              </span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
    </div>

    <?php
    adminLayoutEnd();
}

// ===========================================================================
// N. HALAMAN ENTITAS (slide / informasi / panduan)
// ===========================================================================

/** Komponen field upload gambar + pratinjau + centang hapus. */
function adminFieldGambar($entitas, $row)
{
    $gambar = (string) ($row['gambar'] ?? '');
    $url    = adminImageUrl($gambar);
    $prevId = 'prev-' . $entitas;
    ?>
    <div class="field">
      <label>Gambar <?php echo $entitas === 'slide' ? '(disarankan 1200 × 600 px)' : '(disarankan 800 × 500 px)'; ?></label>
      <div class="drop">
        <div class="prev">
          <div id="<?php echo e($prevId); ?>">
            <?php if ($gambar !== '' && is_file(adminImagePath($gambar))): ?>
              <img src="<?php echo e($url); ?>" alt="Gambar saat ini">
            <?php else: ?>
              <div class="noprev"><?php echo adminIco('image', 24); ?></div>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:220px">
            <input type="file" name="gambar" accept="image/jpeg,image/png,image/webp,image/gif"
                   data-preview="#<?php echo e($prevId); ?>"
                   data-max="<?php echo (int) ADMIN_MAX_UPLOAD_BYTES; ?>"
                   data-max-label="<?php echo e(adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES)); ?>">
            <div class="ket">
              JPG, PNG, WEBP, atau GIF — maks <?php echo e(adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES)); ?>.
              Gambar besar diperkecil otomatis ke <?php echo (int) ADMIN_MAX_IMAGE_WIDTH; ?> px.
              <?php if ($gambar !== ''): ?>
                File sekarang: <span class="mono"><?php echo e($gambar); ?></span>
              <?php endif; ?>
            </div>
            <?php if ($gambar !== ''): ?>
              <label class="switch" style="margin-top:9px">
                <input type="checkbox" name="hapus_gambar" value="1">
                <span class="track"></span>
                <span class="txt"><b>Hapus gambar ini</b><span>Centang bila ingin menampilkan kartu tanpa gambar.</span></span>
              </label>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php
}

/** Field yang spesifik per entitas. */
function adminEntityFields($entitas, $row)
{
    if ($entitas === 'slide') {
        ?>
        <div class="two">
          <div class="field">
            <label for="judul">Judul slide <span class="req">*</span></label>
            <input type="text" id="judul" name="judul" maxlength="180" required
                   value="<?php echo e($row['judul'] ?? ''); ?>" placeholder="Contoh: Pendaftaran Online Lebih Cepat">
          </div>
          <div class="field">
            <label for="subjudul">Subjudul / keterangan singkat</label>
            <input type="text" id="subjudul" name="subjudul" maxlength="255"
                   value="<?php echo e($row['subjudul'] ?? ''); ?>" placeholder="Contoh: Booking poliklinik dari rumah">
          </div>
        </div>
        <?php adminFieldGambar('slide', $row); ?>
        <div class="two">
          <div class="field">
            <label for="warna_latar">Warna latar (bila tanpa gambar)</label>
            <div class="row" style="gap:8px">
              <input type="color" value="<?php echo e(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($row['warna_latar'] ?? '')) ? $row['warna_latar'] : '#1b5e20'); ?>" data-sync="#warna_latar" aria-label="Pemilih warna">
              <input type="text" id="warna_latar" name="warna_latar" maxlength="20" style="flex:1"
                     value="<?php echo e($row['warna_latar'] ?? ''); ?>" placeholder="#1b5e20">
            </div>
            <div class="ket">Dipakai sebagai latar slide bila gambar belum diunggah.</div>
          </div>
          <div class="field">
            <label for="tautan">Tautan (opsional)</label>
            <input type="text" id="tautan" name="tautan" maxlength="255"
                   value="<?php echo e($row['tautan'] ?? ''); ?>" placeholder="https://…">
            <div class="ket">Slide bisa diklik menuju tautan ini. Kosongkan bila tidak perlu.</div>
          </div>
        </div>
        <?php
        return;
    }

    if ($entitas === 'informasi') {
        ?>
        <div class="two">
          <div class="field">
            <label for="judul">Judul informasi <span class="req">*</span></label>
            <input type="text" id="judul" name="judul" maxlength="200" required
                   value="<?php echo e($row['judul'] ?? ''); ?>" placeholder="Contoh: Jadwal Poli Anak Libur Nasional">
          </div>
          <div class="field">
            <label for="kategori">Kategori</label>
            <input type="text" id="kategori" name="kategori" maxlength="60" list="daftar-kategori"
                   value="<?php echo e($row['kategori'] ?? 'Pengumuman'); ?>" placeholder="Pengumuman">
            <datalist id="daftar-kategori">
              <?php foreach (adminKategoriInfo() as $k): ?>
                <option value="<?php echo e($k); ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <div class="ket">Tampil sebagai label kecil di atas judul. Boleh mengetik kategori baru.</div>
          </div>
        </div>
        <?php adminFieldGambar('informasi', $row); ?>
        <div class="field">
          <label for="konten">Isi informasi</label>
          <textarea id="konten" name="konten" data-autogrow data-counter="#hitung-konten"
                    placeholder="Tulis isi pengumuman di sini…"><?php echo e($row['konten'] ?? ''); ?></textarea>
          <div class="ket">
            Baris diawali <span class="mono">-</span> menjadi daftar bullet, baris diawali angka menjadi daftar bernomor,
            dan <span class="mono">**teks**</span> menjadi tebal.
            <span id="hitung-konten" class="mono"></span>
          </div>
        </div>
        <div class="two">
          <div class="field">
            <label for="tanggal">Tanggal informasi</label>
            <input type="date" id="tanggal" name="tanggal" value="<?php echo e($row['tanggal'] ?? date('Y-m-d')); ?>">
            <div class="ket">Ditampilkan di bawah judul kartu.</div>
          </div>
          <div class="field">
            <label for="tautan">Tautan "Selengkapnya" (opsional)</label>
            <input type="text" id="tautan" name="tautan" maxlength="255"
                   value="<?php echo e($row['tautan'] ?? ''); ?>" placeholder="https://…">
          </div>
        </div>
        <?php
        return;
    }

    if ($entitas === 'tarif') {
        $tarifNilai = isset($row['tarif']) ? (int) $row['tarif'] : '';
        ?>
        <div class="two">
          <div class="field">
            <label for="nama_layanan">Nama layanan <span class="req">*</span></label>
            <input type="text" id="nama_layanan" name="nama_layanan" maxlength="200" required
                   value="<?php echo e($row['nama_layanan'] ?? ''); ?>" placeholder="Contoh: Konsultasi Dokter Spesialis">
          </div>
          <div class="field">
            <label for="kategori">Kategori</label>
            <input type="text" id="kategori" name="kategori" maxlength="60" list="daftar-kategori-tarif"
                   value="<?php echo e($row['kategori'] ?? 'Lainnya'); ?>" placeholder="Rawat Jalan">
            <datalist id="daftar-kategori-tarif">
              <?php foreach (adminKategoriTarif() as $k): ?>
                <option value="<?php echo e($k); ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <div class="ket">Tarif dikelompokkan per kategori di halaman informasi. Boleh mengetik kategori baru.</div>
          </div>
        </div>
        <div class="two">
          <div class="field">
            <label for="tarif">Tarif (Rp) <span class="req">*</span></label>
            <input type="text" id="tarif" name="tarif" inputmode="numeric" maxlength="12"
                   value="<?php echo e($tarifNilai === '' ? '' : (string) $tarifNilai); ?>" placeholder="75000">
            <div class="ket">
              Angka saja, tanpa titik/koma — titik ribuan ditambahkan otomatis saat ditampilkan
              (<span class="mono"><?php echo e($tarifNilai === '' || $tarifNilai === 0 ? 'Rp 0' : adminFormatRupiah($tarifNilai)); ?></span>).
            </div>
          </div>
          <div class="field">
            <label for="satuan">Satuan</label>
            <input type="text" id="satuan" name="satuan" maxlength="60" list="daftar-satuan-tarif"
                   value="<?php echo e($row['satuan'] ?? 'per kunjungan'); ?>" placeholder="per kunjungan">
            <datalist id="daftar-satuan-tarif">
              <?php foreach (adminSatuanTarif() as $s): ?>
                <option value="<?php echo e($s); ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <div class="ket">Contoh: per hari, per tindakan, per pemeriksaan.</div>
          </div>
        </div>
        <div class="field">
          <label for="keterangan">Keterangan (opsional)</label>
          <input type="text" id="keterangan" name="keterangan" maxlength="255"
                 value="<?php echo e($row['keterangan'] ?? ''); ?>" placeholder="Contoh: belum termasuk obat dan tindakan medis">
          <div class="ket">Catatan singkat di bawah nama layanan.</div>
        </div>
        <?php
        return;
    }

    // panduan
    ?>
    <div class="two">
      <div class="field">
        <label for="judul">Judul panduan <span class="req">*</span></label>
        <input type="text" id="judul" name="judul" maxlength="180" required
               value="<?php echo e($row['judul'] ?? ''); ?>" placeholder="Contoh: Cara Booking Kunjungan">
        <div class="ket">Judul inilah yang terlihat saat dropdown tertutup.</div>
      </div>
      <div class="field">
        <label for="ikon">Ikon</label>
        <select id="ikon" name="ikon">
          <?php foreach (adminPanduanIcons() as $k => $label): ?>
            <option value="<?php echo e($k); ?>"<?php echo (($row['ikon'] ?? 'info') === $k) ? ' selected' : ''; ?>><?php echo e($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="isi">Isi panduan <span class="req">*</span></label>
      <textarea id="isi" name="isi" data-autogrow data-counter="#hitung-isi"
                placeholder="Langkah 1&#10;Langkah 2"><?php echo e($row['isi'] ?? ''); ?></textarea>
      <div class="ket">
        Setiap baris menjadi satu langkah. Baris diawali <span class="mono">-</span> tampil sebagai bullet.
        <span id="hitung-isi" class="mono"></span>
      </div>
    </div>
    <?php
}

/** Form tambah/ubah satu entitas. */
function adminEntityForm($entitas, $row)
{
    $e  = adminEntity($entitas);
    $id = (int) ($row['id'] ?? 0);
    ?>
    <section class="card" id="form">
      <div class="card-hd">
        <div>
          <h2><?php echo $id > 0 ? 'Ubah ' . e($e['label']) : 'Tambah ' . e($e['label']) . ' Baru'; ?></h2>
          <div class="sub"><?php echo $id > 0 ? 'ID #' . $id . ' — perubahan langsung terlihat di aplikasi.' : 'Isi kolom bertanda * lalu simpan.'; ?></div>
        </div>
        <div class="right">
          <a class="btn btn-s" href="admin.php?page=<?php echo e($entitas); ?>">Batal</a>
        </div>
      </div>
      <div class="card-bd">
        <form method="post" action="admin.php?page=<?php echo e($entitas); ?>" enctype="multipart/form-data">
          <?php echo adminCsrfField(); ?>
          <input type="hidden" name="aksi" value="simpan">
          <input type="hidden" name="entitas" value="<?php echo e($entitas); ?>">
          <input type="hidden" name="page" value="<?php echo e($entitas); ?>">
          <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

          <?php adminEntityFields($entitas, $row); ?>

          <div class="two">
            <div class="field">
              <label for="urutan">Urutan tampil</label>
              <input type="number" id="urutan" name="urutan" min="0" max="32000" step="10"
                     value="<?php echo e(isset($row['urutan']) ? (int) $row['urutan'] : adminNextUrutan($entitas)); ?>">
              <div class="ket">Angka kecil tampil lebih dulu. Bisa juga diubah lewat tombol ▲ ▼ pada tabel.</div>
            </div>
            <div class="field">
              <label>Status</label>
              <label class="switch">
                <input type="checkbox" name="status_aktif" value="1" <?php echo (!isset($row['status_aktif']) || (int) $row['status_aktif'] === 1) ? 'checked' : ''; ?>>
                <span class="track"></span>
                <span class="txt"><b>Tampilkan di aplikasi</b><span>Matikan untuk menyembunyikan sementara tanpa menghapus data.</span></span>
              </label>
            </div>
          </div>

          <div class="row" style="margin-top:6px">
            <button class="btn btn-p" type="submit"><?php echo adminIco('check', 16); ?> Simpan</button>
            <a class="btn" href="admin.php?page=<?php echo e($entitas); ?>">Kembali ke daftar</a>
            <a class="btn" href="informasi.php" target="_blank" rel="noopener"><?php echo adminIco('eye', 16); ?> Pratinjau</a>
          </div>
        </form>
      </div>
    </section>
    <?php
}

/** Thumb gambar atau placeholder berwarna. */
function adminThumb($row, $fallbackWarna = '')
{
    $gambar = (string) ($row['gambar'] ?? '');
    if ($gambar !== '' && is_file(adminImagePath($gambar))) {
        return '<img class="thumb" src="' . e(adminImageUrl($gambar)) . '?v=' . e((string) ($row['diperbarui_pada'] ?? '')) . '" alt="" loading="lazy">';
    }
    $warna = $fallbackWarna !== '' ? $fallbackWarna : (string) ($row['warna_latar'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $warna)) $warna = '#2e7d32';
    return '<span class="thumb-ph" style="background:' . e($warna) . '">' . adminIco('image', 18) . '</span>';
}

/** Tombol aksi satu baris (satu form, banyak button dengan name=aksi). */
function adminRowActions($entitas, $row, $posisi, $jumlah)
{
    $id = (int) $row['id'];
    ?>
    <form method="post" action="admin.php?page=<?php echo e($entitas); ?>" class="aksi">
      <?php echo adminCsrfField(); ?>
      <input type="hidden" name="entitas" value="<?php echo e($entitas); ?>">
      <input type="hidden" name="page" value="<?php echo e($entitas); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

      <button class="btn btn-s btn-ico" type="submit" name="aksi" value="urutan_naik" title="Naikkan urutan"
              <?php echo $posisi <= 1 ? 'disabled' : ''; ?>><?php echo adminIco('up', 15); ?></button>
      <button class="btn btn-s btn-ico" type="submit" name="aksi" value="urutan_turun" title="Turunkan urutan"
              <?php echo $posisi >= $jumlah ? 'disabled' : ''; ?>><?php echo adminIco('down', 15); ?></button>

      <button class="btn btn-s" type="submit" name="aksi" value="toggle"
              title="Klik untuk <?php echo (int) $row['status_aktif'] === 1 ? 'menyembunyikan' : 'menampilkan'; ?>"
              style="<?php echo (int) $row['status_aktif'] === 1
                  ? 'color:var(--ok);border-color:#c6e7cf;background:var(--okbg)'
                  : 'color:#6b7280;border-color:var(--line);background:#f4f5f4'; ?>">
        <?php echo (int) $row['status_aktif'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
      </button>

      <a class="btn btn-s btn-ico" href="admin.php?page=<?php echo e($entitas); ?>&edit=<?php echo (int) $id; ?>" title="Ubah">
        <?php echo adminIco('edit', 15); ?>
      </a>
      <button class="btn btn-s btn-ico btn-d" type="submit" name="aksi" value="hapus" title="Hapus"
              data-confirm="Hapus <?php echo e(adminRingkas(adminJudulBaris($row), 40)); ?>? Tindakan ini tidak bisa dibatalkan.">
        <?php echo adminIco('trash', 15); ?>
      </button>
    </form>
    <?php
}

/** Tabel daftar entitas. */
function adminEntityTable($entitas)
{
    $e    = adminEntity($entitas);
    $rows = adminRows($entitas);
    $n    = count($rows);
    ?>
    <section class="card">
      <div class="card-hd">
        <div>
          <h2>Daftar <?php echo e($e['labelBny']); ?></h2>
          <div class="sub"><?php echo (int) $n; ?> baris — urut dari yang paling atas tampil lebih dulu</div>
        </div>
        <div class="right">
          <a class="btn btn-s btn-p" href="admin.php?page=<?php echo e($entitas); ?>&baru=1#form">
            <?php echo adminIco('plus', 15); ?> Tambah
          </a>
        </div>
      </div>

      <?php if ($n === 0): ?>
        <div class="kosong">
          <div class="ic"><?php echo adminIco($entitas === 'panduan' ? 'list' : ($entitas === 'slide' ? 'image' : ($entitas === 'tarif' ? 'tarif' : 'cards')), 24); ?></div>
          <b>Belum ada <?php echo e(strtolower($e['labelBny'])); ?></b>
          <p>Tambahkan data pertama lewat tombol di atas. Selama masih kosong, halaman informasi.php
             menampilkan pesan bawaan sehingga tidak pernah error.</p>
        </div>
      <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th style="width:36px">No</th>
                <?php if (!empty($e['punyaGambar'])): ?><th style="width:88px">Gambar</th><?php endif; ?>
                <th><?php
                    if ($entitas === 'panduan')   echo 'Judul &amp; isi dropdown';
                    elseif ($entitas === 'tarif') echo 'Layanan';
                    else                          echo 'Judul';
                ?></th>
                <?php if ($entitas === 'informasi'): ?><th style="width:150px">Kategori / Tanggal</th><?php endif; ?>
                <?php if ($entitas === 'slide'): ?><th style="width:180px">Tautan</th><?php endif; ?>
                <?php if ($entitas === 'tarif'): ?>
                  <th style="width:170px">Kategori</th>
                  <th style="width:140px;text-align:right">Tarif</th>
                <?php endif; ?>
                <th style="width:120px">Diperbarui</th>
                <th style="width:230px;text-align:right">Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php $i = 0; foreach ($rows as $row): $i++; ?>
              <tr>
                <td class="mono" style="color:var(--muted)"><?php echo (int) $i; ?></td>

                <?php if ($entitas === 'slide'): ?>
                  <td><?php echo adminThumb($row); ?></td>
                  <td>
                    <div class="cell-judul"><?php echo e($row['judul']); ?></div>
                    <?php if (!empty($row['subjudul'])): ?><div class="cell-sub"><?php echo e($row['subjudul']); ?></div><?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($row['tautan'])): ?>
                      <a href="<?php echo eUrl($row['tautan']); ?>" target="_blank" rel="noopener" class="mono" style="font-size:11px">
                        <?php echo e(adminRingkas($row['tautan'], 30)); ?> ↗
                      </a>
                    <?php else: ?><span class="hint">—</span><?php endif; ?>
                  </td>

                <?php elseif ($entitas === 'informasi'): ?>
                  <td><?php echo adminThumb($row); ?></td>
                  <td>
                    <div class="cell-judul"><?php echo e($row['judul']); ?></div>
                    <div class="cell-sub"><?php echo e(adminRingkas($row['konten'] ?? '', 110)); ?></div>
                  </td>
                  <td>
                    <span class="badge badge-cat"><?php echo e($row['kategori']); ?></span>
                    <div class="cell-sub"><?php echo e($row['tanggal'] ? adminTanggalIndo($row['tanggal']) : 'tanpa tanggal'); ?></div>
                  </td>

                <?php elseif ($entitas === 'tarif'): ?>
                  <td>
                    <div class="cell-judul"><?php echo e($row['nama_layanan']); ?></div>
                    <?php if (!empty($row['keterangan'])): ?>
                      <div class="cell-sub"><?php echo e(adminRingkas($row['keterangan'], 110)); ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge badge-cat"><?php echo e($row['kategori']); ?></span></td>
                  <td class="mono" style="text-align:right;white-space:nowrap">
                    <?php echo e(adminFormatRupiah($row['tarif'])); ?>
                    <?php if (!empty($row['satuan'])): ?>
                      <div class="cell-sub" style="text-align:right"><?php echo e($row['satuan']); ?></div>
                    <?php endif; ?>
                  </td>

                <?php else: ?>
                  <td>
                    <div class="row" style="gap:8px;flex-wrap:nowrap">
                      <span style="color:var(--brand);flex:none"><?php echo adminIco($row['ikon'] ?: 'info', 18); ?></span>
                      <span>
                        <span class="cell-judul"><?php echo e($row['judul']); ?></span>
                        <span class="cell-sub"><?php echo e(adminRingkas($row['isi'] ?? '', 110)); ?></span>
                      </span>
                    </div>
                  </td>
                <?php endif; ?>

                <td class="mono" style="color:var(--muted);font-size:11px">
                  <?php echo e($row['diperbarui_pada'] ? date('d/m/Y H:i', (int) strtotime((string) $row['diperbarui_pada'])) : '—'); ?>
                </td>
                <td><?php adminRowActions($entitas, $row, $i, $n); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

/** Halaman lengkap satu entitas. */
function adminViewEntity($entitas)
{
    $e        = adminEntity($entitas);
    $menu     = adminMenuItems();
    $editId   = (int) adminGet('edit', 0);
    $row      = null;
    $showForm = adminGet('baru', '') === '1';

    if ($editId > 0) {
        $row = adminRow($entitas, $editId);
        if ($row) {
            $showForm = true;
        } else {
            adminFlash('error', 'Data dengan ID #' . $editId . ' tidak ditemukan.');
        }
    }

    adminLayoutStart($e['labelBny'], $menu[$entitas]['sub'] ?? '', $entitas);
    if ($showForm) adminEntityForm($entitas, is_array($row) ? $row : []);
    adminEntityTable($entitas);
    adminLayoutEnd();
}

// ===========================================================================
// O. HALAMAN PENGATURAN
// ===========================================================================

function adminViewPengaturan()
{
    $menu = adminMenuItems();
    adminLayoutStart('Pengaturan Halaman', $menu['pengaturan']['sub'], 'pengaturan');

    $nilai = adminSettings();
    ?>
    <form method="post" action="admin.php?page=pengaturan">
      <?php echo adminCsrfField(); ?>
      <input type="hidden" name="aksi" value="pengaturan_simpan">
      <input type="hidden" name="page" value="pengaturan">

      <div class="stack">
        <?php foreach (adminSettingFields() as $kelompok => $fields): ?>
          <section class="card">
            <div class="card-hd">
              <div>
                <h2><?php echo e($kelompok); ?></h2>
                <div class="sub"><?php echo count($fields); ?> pengaturan</div>
              </div>
            </div>
            <div class="card-bd">
              <?php foreach ($fields as $f):
                  $kunci = $f['kunci'];
                  $val   = (string) ($nilai[$kunci] ?? '');
              ?>
                <?php if ($f['tipe'] === 'switch'): ?>
                  <label class="switch" style="margin-bottom:10px">
                    <input type="hidden" name="set[<?php echo e($kunci); ?>]" value="0">
                    <input type="checkbox" name="set[<?php echo e($kunci); ?>]" value="1" <?php echo $val === '1' ? 'checked' : ''; ?>>
                    <span class="track"></span>
                    <span class="txt">
                      <b><?php echo e($f['label']); ?></b>
                      <?php if (!empty($f['ket'])): ?><span><?php echo e($f['ket']); ?></span><?php endif; ?>
                    </span>
                  </label>
                <?php elseif ($f['tipe'] === 'color'): ?>
                  <div class="field">
                    <label for="set-<?php echo e($kunci); ?>"><?php echo e($f['label']); ?></label>
                    <div class="row" style="gap:8px;max-width:280px">
                      <input type="color" value="<?php echo e(preg_match('/^#[0-9a-fA-F]{6}$/', $val) ? $val : '#1b5e20'); ?>"
                             data-sync="#set-<?php echo e($kunci); ?>" aria-label="Pemilih warna">
                      <input type="text" id="set-<?php echo e($kunci); ?>" name="set[<?php echo e($kunci); ?>]"
                             value="<?php echo e($val); ?>" maxlength="20" placeholder="#1b5e20">
                    </div>
                    <?php if (!empty($f['ket'])): ?><div class="ket"><?php echo e($f['ket']); ?></div><?php endif; ?>
                  </div>
                <?php elseif ($f['tipe'] === 'number'): ?>
                  <div class="field" style="max-width:280px">
                    <label for="set-<?php echo e($kunci); ?>"><?php echo e($f['label']); ?></label>
                    <input type="number" id="set-<?php echo e($kunci); ?>" name="set[<?php echo e($kunci); ?>]"
                           value="<?php echo e($val !== '' ? (int) $val : 5000); ?>" min="1000" max="60000" step="500">
                    <?php if (!empty($f['ket'])): ?><div class="ket"><?php echo e($f['ket']); ?></div><?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="field">
                    <label for="set-<?php echo e($kunci); ?>"><?php echo e($f['label']); ?></label>
                    <input type="text" id="set-<?php echo e($kunci); ?>" name="set[<?php echo e($kunci); ?>]"
                           value="<?php echo e($val); ?>" maxlength="500">
                    <?php if (!empty($f['ket'])): ?><div class="ket"><?php echo e($f['ket']); ?></div><?php endif; ?>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>

        <div class="card">
          <div class="card-bd row">
            <button class="btn btn-p" type="submit"><?php echo adminIco('check', 16); ?> Simpan Pengaturan</button>
            <a class="btn" href="informasi.php" target="_blank" rel="noopener"><?php echo adminIco('eye', 16); ?> Pratinjau informasi.php</a>
            <span class="hint">Perubahan langsung terlihat setelah halaman informasi dimuat ulang.</span>
          </div>
        </div>
      </div>
    </form>
    <?php
    adminLayoutEnd();
}

// ===========================================================================
// P. HALAMAN AKUN
// ===========================================================================

function adminViewAkun()
{
    $menu = adminMenuItems();
    $user = adminCurrentUser();
    adminLayoutStart('Akun Saya', $menu['akun']['sub'], 'akun');
    ?>
    <div class="grid grid-2">
      <section class="card">
        <div class="card-hd">
          <div><h2>Profil Administrator</h2><div class="sub">Nama dan email penanggung jawab panel</div></div>
        </div>
        <div class="card-bd">
          <form method="post" action="admin.php?page=akun">
            <?php echo adminCsrfField(); ?>
            <input type="hidden" name="aksi" value="akun_simpan">
            <input type="hidden" name="page" value="akun">

            <div class="field">
              <label>Username (login)</label>
              <input type="text" value="<?php echo e($user['username']); ?>" readonly style="background:#f6f7f6;color:var(--muted)">
              <div class="ket">Username tidak dapat diubah dari panel ini demi keamanan jejak audit.</div>
            </div>
            <div class="field">
              <label for="nama_lengkap">Nama lengkap</label>
              <input type="text" id="nama_lengkap" name="nama_lengkap" maxlength="150"
                     value="<?php echo e($user['nama_lengkap']); ?>" placeholder="Nama petugas pengelola konten">
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" maxlength="180" value="<?php echo e($user['email']); ?>" placeholder="nama@rsud-malangbong.garutkab.go.id">
            </div>
            <div class="kv"><span class="k">Role</span><span class="v"><?php echo e($user['role']); ?></span></div>
            <div class="kv"><span class="k">Login terakhir</span><span class="v"><?php echo e($user['terakhir_login'] ? date('d/m/Y H:i', (int) strtotime((string) $user['terakhir_login'])) : 'baru sekarang'); ?></span></div>
            <div class="kv"><span class="k">Akun dibuat</span><span class="v"><?php echo e($user['dibuat_pada'] ? date('d/m/Y', (int) strtotime((string) $user['dibuat_pada'])) : '—'); ?></span></div>

            <div class="row" style="margin-top:14px">
              <button class="btn btn-p" type="submit"><?php echo adminIco('check', 16); ?> Simpan Profil</button>
            </div>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card-hd">
          <div><h2>Ganti Password</h2><div class="sub">Disarankan diganti secara berkala</div></div>
        </div>
        <div class="card-bd">
          <form method="post" action="admin.php?page=akun" autocomplete="off">
            <?php echo adminCsrfField(); ?>
            <input type="hidden" name="aksi" value="password_ganti">
            <input type="hidden" name="page" value="akun">

            <div class="field">
              <label for="password_lama">Password lama <span class="req">*</span></label>
              <div class="row" style="gap:8px">
                <input type="password" id="password_lama" name="password_lama" required autocomplete="current-password" style="flex:1">
                <button type="button" class="btn btn-s" data-toggle-pass="#password_lama">Lihat</button>
              </div>
            </div>
            <div class="field">
              <label for="password_baru">Password baru <span class="req">*</span></label>
              <div class="row" style="gap:8px">
                <input type="password" id="password_baru" name="password_baru" required minlength="8" autocomplete="new-password" style="flex:1">
                <button type="button" class="btn btn-s" data-toggle-pass="#password_baru">Lihat</button>
              </div>
              <div class="ket">Minimal 8 karakter. Gabungkan huruf besar, huruf kecil, dan angka.</div>
            </div>
            <div class="field">
              <label for="password_konfirmasi">Ulangi password baru <span class="req">*</span></label>
              <input type="password" id="password_konfirmasi" name="password_konfirmasi" required minlength="8" autocomplete="new-password">
            </div>

            <div class="row">
              <button class="btn btn-p" type="submit"><?php echo adminIco('lock', 16); ?> Ganti Password</button>
            </div>
          </form>

          <div class="alert alert-info" style="margin-top:16px;margin-bottom:0">
            <?php echo adminIco('info', 17); ?>
            <div>
              Setelah password diganti, semua sesi lain tetap aktif sampai menganggur
              <?php echo (int) (ADMIN_SESSION_IDLE / 60); ?> menit. Untuk keamanan, keluar-masuk kembali sesudah mengganti password.
            </div>
          </div>
        </div>
      </section>
    </div>
    <?php
    adminLayoutEnd();
}

// ===========================================================================
// Q. GENERATOR FILE SQL (dipakai tombol unduh & file di backend/sql/)
// ===========================================================================

/** Rapikan indentasi pernyataan CREATE TABLE agar enak dibaca di phpMyAdmin. */
function adminSqlIndent($sql)
{
    $baris = explode("\n", (string) $sql);
    $hasil = [];
    foreach ($baris as $i => $ln) {
        $ln = rtrim($ln);
        if (trim($ln) === '') continue;
        if ($i === 0) { $hasil[] = $ln; continue; }
        $t = ltrim($ln);
        $hasil[] = (substr($t, 0, 1) === ')' ? '' : '    ') . $t;
    }
    return implode("\n", $hasil);
}

/** Escape nilai menjadi literal SQL yang aman. */
function adminSqlQuote($nilai)
{
    if ($nilai === null) return 'NULL';
    $s = (string) $nilai;
    $s = str_replace(["\\", "'"], ["\\\\", "''"], $s);
    return "'" . $s . "'";
}

/**
 * Susun isi file SQL lengkap (MySQL/MariaDB) untuk database admin:
 * buat database → buat tabel → isi pengaturan default → akun admin → data contoh.
 * Semua pernyataan idempoten: aman dijalankan berulang tanpa menduplikasi data.
 */
function adminSqlDump()
{
    global $ADMIN_DB_CONFIG;
    $db   = $ADMIN_DB_CONFIG['name'];
    $out  = '';

    $out .= "-- ============================================================================\n";
    $out .= "-- admin_info_rsudmobile.sql\n";
    $out .= "-- Skema + data awal database ADMIN konten informasi Aplikasi Mobile\n";
    $out .= "-- RSUD Malangbong (MySQL / MariaDB).\n";
    $out .= "--\n";
    $out .= "-- Database ini TERPISAH dari database SIMRS yang dipakai backend/api.php.\n";
    $out .= "--\n";
    $out .= "-- Cara import (pilih salah satu):\n";
    $out .= "--   1) phpMyAdmin  : tab \"Import\" → pilih file ini → Go\n";
    $out .= "--   2) Terminal    : mysql -h 192.168.22.251 -u rsudmalangbong -p < admin_info_rsudmobile.sql\n";
    $out .= "--   3) Panel admin : menu \"Cek Sistem\" → tombol \"Jalankan instalasi otomatis\"\n";
    $out .= "--\n";
    $out .= "-- Aman dijalankan berulang kali (CREATE TABLE IF NOT EXISTS + INSERT dengan\n";
    $out .= "-- syarat NOT EXISTS), sehingga tidak menduplikasi data yang sudah ada.\n";
    $out .= "-- ============================================================================\n\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $out .= "SET time_zone = '+07:00';\n\n";
    $out .= "CREATE DATABASE IF NOT EXISTS `" . $db . "`\n";
    $out .= "  DEFAULT CHARACTER SET utf8mb4\n  DEFAULT COLLATE utf8mb4_unicode_ci;\n\n";
    $out .= "USE `" . $db . "`;\n\n";

    // ---- tabel ----
    $out .= "-- ---------------------------------------------------------------------------\n";
    $out .= "-- STRUKTUR TABEL\n";
    $out .= "-- ---------------------------------------------------------------------------\n\n";
    foreach (adminSchemaStatements('mysql') as $sql) {
        $out .= adminSqlIndent($sql) . ";\n\n";
    }

    // ---- pengaturan default ----
    $out .= "-- ---------------------------------------------------------------------------\n";
    $out .= "-- PENGATURAN HALAMAN INFORMASI (nilai default)\n";
    $out .= "-- ---------------------------------------------------------------------------\n\n";
    $defaults = adminDefaultSettings();
    foreach (adminSettingFields() as $kelompok => $fields) {
        $out .= "-- " . $kelompok . "\n";
        foreach ($fields as $f) {
            $kunci = $f['kunci'];
            $nilai = (string) ($defaults[$kunci] ?? '');
            $out .= "INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ("
                . adminSqlQuote($kunci) . ', '
                . adminSqlQuote($nilai) . ', '
                . adminSqlQuote($f['label']) . ', '
                . adminSqlQuote($kelompok) . ', '
                . adminSqlQuote($f['tipe']) . ', NOW());' . "\n";
        }
        $out .= "\n";
    }

    // ---- akun admin ----
    $hash = adminDefaultHash();
    $out .= "-- ---------------------------------------------------------------------------\n";
    $out .= "-- AKUN ADMIN PERTAMA\n";
    $out .= "--   username : " . ADMIN_DEFAULT_USERNAME . "\n";
    $out .= "--   password : " . ADMIN_DEFAULT_PASSWORD . "   <-- GANTI setelah login pertama!\n";
    $out .= "-- ---------------------------------------------------------------------------\n\n";
    $out .= "INSERT IGNORE INTO admin_users\n";
    $out .= "  (username, password_hash, nama_lengkap, email, role, status_aktif, login_gagal, dibuat_pada, diperbarui_pada)\n";
    $out .= "VALUES ("
        . adminSqlQuote(ADMIN_DEFAULT_USERNAME) . ', '
        . adminSqlQuote($hash) . ', '
        . adminSqlQuote('Administrator Konten Informasi') . ', '
        . adminSqlQuote(ADMIN_DEFAULT_USERNAME) . ', '
        . "'admin', 1, 0, NOW(), NOW());\n\n";

    // ---- data contoh ----
    $out .= "-- ---------------------------------------------------------------------------\n";
    $out .= "-- DATA CONTOH (hanya masuk bila judulnya belum ada)\n";
    $out .= "-- ---------------------------------------------------------------------------\n\n";

    $out .= "-- Slide gambar (gambar kosong — unggah lewat panel admin)\n";
    foreach (adminContohSlide() as $i => $s) {
        $out .= "INSERT INTO slide (judul, subjudul, gambar, tautan, warna_latar, urutan, status_aktif, dibuat_pada, diperbarui_pada)\n";
        $out .= 'SELECT ' . adminSqlQuote($s['judul']) . ', ' . adminSqlQuote($s['subjudul']) . ", '', "
            . adminSqlQuote($s['tautan']) . ', ' . adminSqlQuote($s['warna']) . ', ' . (($i + 1) * 10) . ", 1, NOW(), NOW() FROM DUAL\n";
        $out .= 'WHERE NOT EXISTS (SELECT 1 FROM slide WHERE judul = ' . adminSqlQuote($s['judul']) . ");\n";
    }
    $out .= "\n";

    $out .= "-- Kartu informasi\n";
    foreach (adminContohInformasi() as $i => $inf) {
        $out .= "INSERT INTO informasi (kategori, judul, konten, gambar, tautan, tanggal, urutan, status_aktif, dibuat_pada, diperbarui_pada)\n";
        $out .= 'SELECT ' . adminSqlQuote($inf['kategori']) . ', ' . adminSqlQuote($inf['judul']) . ', '
            . adminSqlQuote($inf['konten']) . ", '', " . adminSqlQuote($inf['tautan']) . ', CURDATE(), '
            . (($i + 1) * 10) . ", 1, NOW(), NOW() FROM DUAL\n";
        $out .= 'WHERE NOT EXISTS (SELECT 1 FROM informasi WHERE judul = ' . adminSqlQuote($inf['judul']) . ");\n";
    }
    $out .= "\n";

    $out .= "-- Panduan pemakaian (tampil sebagai dropdown di aplikasi)\n";
    foreach (adminContohPanduan() as $i => $p) {
        $out .= "INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)\n";
        $out .= 'SELECT ' . adminSqlQuote($p['judul']) . ', ' . adminSqlQuote($p['isi']) . ', '
            . adminSqlQuote($p['ikon']) . ', ' . (($i + 1) * 10) . ", 1, NOW(), NOW() FROM DUAL\n";
        $out .= 'WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = ' . adminSqlQuote($p['judul']) . ");\n";
    }
    $out .= "\n";

    $out .= "-- Tarif layanan (angka hanya CONTOH — ganti dengan tarif resmi rumah sakit)\n";
    foreach (adminContohTarif() as $i => $t) {
        $out .= "INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)\n";
        $out .= 'SELECT ' . adminSqlQuote($t['kategori']) . ', ' . adminSqlQuote($t['nama_layanan']) . ', '
            . adminSqlQuote($t['satuan']) . ', ' . ((int) $t['tarif']) . ', ' . adminSqlQuote($t['keterangan']) . ', '
            . (($i + 1) * 10) . ", 1, NOW(), NOW() FROM DUAL\n";
        $out .= 'WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = ' . adminSqlQuote($t['nama_layanan']) . ");\n";
    }
    $out .= "\n";

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
    $out .= "-- Selesai. Silakan buka backend/admin.php lalu login.\n";

    return $out;
}

/** Kirim file SQL sebagai unduhan. */
function adminServeSql()
{
    $nama = 'admin_info_rsudmobile.sql';
    adminHeader('Content-Type: application/sql; charset=UTF-8');
    adminHeader('Content-Disposition: attachment; filename="' . $nama . '"');
    adminHeader('Cache-Control: no-store');
    echo adminSqlDump();
    if (!RSUD_ADMIN_TEST_MODE) exit;
}

// ===========================================================================
// R. HALAMAN CEK SISTEM
// ===========================================================================

function adminViewSistem()
{
    global $ADMIN_DB_CONFIG;
    $menu   = adminMenuItems();
    $db     = adminDbStatus();
    $up     = adminUploadStats();
    $tulis  = adminUploadDirWritable();
    $kurang = $db['ok'] ? adminMissingTables() : adminCoreTables();

    adminLayoutStart('Cek Sistem', $menu['sistem']['sub'], 'sistem');
    ?>

    <?php if (!$db['ok']): ?>
      <div class="alert alert-err"><?php echo adminIco('db', 18); ?><div><b>Database tidak tersambung.</b><br><?php echo e($db['pesan']); ?></div></div>
    <?php elseif (!empty($kurang)): ?>
      <div class="alert alert-warn"><?php echo adminIco('alert', 18); ?>
        <div><b>Tabel belum lengkap:</b> <span class="mono"><?php echo e(implode(', ', $kurang)); ?></span>.
        Jalankan instalasi otomatis di bawah atau import file SQL.</div>
      </div>
    <?php else: ?>
      <div class="alert alert-ok"><?php echo adminIco('check', 18); ?><div>Database tersambung dan seluruh tabel tersedia.</div></div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['ADMIN_SETUP_LAPORAN'])): ?>
      <section class="card" style="margin-bottom:16px">
        <div class="card-hd"><div><h2>Hasil Instalasi</h2><div class="sub">Laporan langkah demi langkah</div></div></div>
        <div class="card-bd" style="padding:6px 16px 12px">
          <?php foreach ($GLOBALS['ADMIN_SETUP_LAPORAN'] as $l): ?>
            <div class="kv">
              <span class="k"><?php echo e($l['label']); ?></span>
              <span class="v">
                <span class="badge <?php echo !empty($l['ok']) ? 'badge-ok' : 'badge-err'; ?>"><?php echo !empty($l['ok']) ? 'OK' : 'GAGAL'; ?></span>
                <span style="color:var(--muted);font-weight:600"><?php echo e($l['pesan']); ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <div class="grid grid-2">
      <section class="card">
        <div class="card-hd"><div><h2>Koneksi Database Admin</h2><div class="sub">Konfigurasi di backend/admin-config.php</div></div></div>
        <div class="card-bd">
          <div class="kv"><span class="k">Driver</span><span class="v mono"><?php echo e($ADMIN_DB_CONFIG['driver']); ?></span></div>
          <div class="kv"><span class="k">Host</span><span class="v mono"><?php echo e($ADMIN_DB_CONFIG['driver'] === 'sqlite' ? $ADMIN_DB_CONFIG['sqlite_file'] : $ADMIN_DB_CONFIG['host'] . ':' . $ADMIN_DB_CONFIG['port']); ?></span></div>
          <div class="kv"><span class="k">Nama database</span><span class="v mono"><?php echo e($ADMIN_DB_CONFIG['name']); ?></span></div>
          <div class="kv"><span class="k">User</span><span class="v mono"><?php echo e($ADMIN_DB_CONFIG['user']); ?></span></div>
          <div class="kv"><span class="k">Status</span><span class="v"><span class="badge <?php echo $db['ok'] ? 'badge-ok' : 'badge-err'; ?>"><?php echo $db['ok'] ? 'TERSAMBUNG' : 'GAGAL'; ?></span></span></div>
          <?php if ($db['ok']): ?>
            <div class="kv"><span class="k">Versi server</span><span class="v mono"><?php
                try { echo e((string) adminValue('SELECT VERSION()', [], '-')); } catch (Throwable $ex) { echo 'n/a'; }
            ?></span></div>
          <?php endif; ?>
          <p class="hint" style="margin-top:10px">
            Database admin <b>terpisah</b> dari database SIMRS (PostgreSQL) yang dipakai <span class="mono">api.php</span>.
            Ubah kredensial lewat env <span class="mono">RSUD_ADMIN_DB_*</span> atau langsung di file konfigurasi.
          </p>
        </div>
      </section>

      <section class="card">
        <div class="card-hd"><div><h2>Folder Upload Gambar</h2><div class="sub">Tempat penyimpanan berkas</div></div></div>
        <div class="card-bd">
          <div class="kv"><span class="k">Path</span><span class="v mono"><?php echo e(ADMIN_UPLOAD_DIR); ?></span></div>
          <div class="kv"><span class="k">URL relatif</span><span class="v mono"><?php echo e(ADMIN_UPLOAD_URL); ?>/</span></div>
          <div class="kv"><span class="k">Bisa ditulis</span><span class="v"><span class="badge <?php echo $tulis ? 'badge-ok' : 'badge-err'; ?>"><?php echo $tulis ? 'YA' : 'TIDAK'; ?></span></span></div>
          <div class="kv"><span class="k">Jumlah gambar</span><span class="v"><?php echo (int) $up['jumlah']; ?> file (<?php echo e(adminFormatBytes($up['bytes'])); ?>)</span></div>
          <div class="kv"><span class="k">Batas per file</span><span class="v"><?php echo e(adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES)); ?></span></div>
          <div class="kv"><span class="k">upload_max_filesize (php.ini)</span><span class="v mono"><?php echo e((string) ini_get('upload_max_filesize')); ?></span></div>
          <div class="kv"><span class="k">post_max_size (php.ini)</span><span class="v mono"><?php echo e((string) ini_get('post_max_size')); ?></span></div>
          <?php if ((int) @adminIniBytes((string) ini_get('post_max_size')) < ADMIN_MAX_UPLOAD_BYTES): ?>
            <div class="alert alert-warn" style="margin:12px 0 0">
              <?php echo adminIco('alert', 17); ?>
              <div><span class="mono">post_max_size</span> PHP lebih kecil dari batas upload panel
              (<?php echo e(adminFormatBytes(ADMIN_MAX_UPLOAD_BYTES)); ?>). Naikkan di php.ini bila ingin mengunggah gambar besar.</div>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="card" style="margin-top:16px">
      <div class="card-hd"><div><h2>Tabel Database</h2><div class="sub"><?php echo count(adminCoreTables()); ?> tabel inti</div></div></div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Tabel</th><th>Fungsi</th><th style="width:120px">Status</th><th style="width:110px">Jumlah baris</th></tr></thead>
          <tbody>
            <?php
            $fungsi = [
                'admin_users' => 'Akun administrator panel',
                'pengaturan'  => 'Pengaturan halaman informasi (key-value)',
                'slide'       => 'Slider gambar di bagian atas halaman',
                'informasi'   => 'Kartu pengumuman / berita bergambar',
                'panduan'     => 'Item dropdown panduan pemakaian',
                'admin_log'   => 'Jejak aktivitas & percobaan login',
            ];
            foreach (adminCoreTables() as $t):
                $ada = adminTableExists($t);
                $n   = null;
                if ($ada) { try { $n = (int) adminValue('SELECT COUNT(*) FROM ' . $t, [], 0); } catch (Throwable $ex) { $n = null; } }
            ?>
              <tr>
                <td class="mono"><b><?php echo e($t); ?></b></td>
                <td style="color:var(--muted)"><?php echo e($fungsi[$t] ?? ''); ?></td>
                <td><span class="badge <?php echo $ada ? 'badge-ok' : 'badge-err'; ?>"><?php echo $ada ? 'ADA' : 'BELUM ADA'; ?></span></td>
                <td class="mono"><?php echo $n === null ? '—' : (int) $n; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div class="grid grid-2" style="margin-top:16px">
      <section class="card">
        <div class="card-hd"><div><h2>Instalasi Otomatis</h2><div class="sub">Bila file SQL belum diimport</div></div></div>
        <div class="card-bd">
          <p class="hint" style="margin-bottom:12px">
            Tombol ini membuat tabel yang belum ada, mengisi pengaturan default, membuat akun admin pertama,
            dan (opsional) menambahkan data contoh. Aman dijalankan berulang — data yang sudah ada tidak ditimpa.
          </p>
          <form method="post" action="admin.php?page=sistem" data-confirm="Jalankan instalasi skema database admin?">
            <?php echo adminCsrfField(); ?>
            <input type="hidden" name="aksi" value="setup">
            <input type="hidden" name="page" value="sistem">
            <label class="switch" style="margin-bottom:12px">
              <input type="hidden" name="isi_contoh" value="0">
              <input type="checkbox" name="isi_contoh" value="1" checked>
              <span class="track"></span>
              <span class="txt"><b>Sertakan data contoh</b><span>Slide, kartu informasi, dan panduan awal.</span></span>
            </label>
            <button class="btn btn-p" type="submit" <?php echo $db['ok'] ? '' : 'disabled'; ?>>
              <?php echo adminIco('db', 16); ?> Jalankan Instalasi
            </button>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card-hd"><div><h2>File SQL</h2><div class="sub">Import manual lewat phpMyAdmin / mysql</div></div></div>
        <div class="card-bd">
          <p class="hint" style="margin-bottom:10px">
            File siap import tersedia di repo: <span class="mono">backend/sql/admin_info_rsudmobile.sql</span>.
            Versi terbaru (mengikuti kode panel) juga bisa diunduh langsung:
          </p>
          <a class="btn" href="admin.php?page=sistem&unduh=sql"><?php echo adminIco('download', 16); ?> Unduh .sql</a>
          <div class="kotak-kode" style="margin-top:12px">mysql -h <?php echo e($ADMIN_DB_CONFIG['host']); ?> -P <?php echo (int) $ADMIN_DB_CONFIG['port']; ?> \
  -u <?php echo e($ADMIN_DB_CONFIG['user']); ?> -p &lt; admin_info_rsudmobile.sql</div>
          <div class="kv" style="margin-top:12px"><span class="k">Ekstensi PHP terpasang</span>
            <span class="v mono" style="font-size:11px">
              gd:<?php echo function_exists('imagecreatefromstring') ? 'ya' : 'tidak'; ?>
              • finfo:<?php echo function_exists('finfo_open') ? 'ya' : 'tidak'; ?>
              • mbstring:<?php echo extension_loaded('mbstring') ? 'ya' : 'tidak'; ?>
              • pdo_<?php echo e($ADMIN_DB_CONFIG['driver']); ?>:<?php echo extension_loaded('pdo_' . $ADMIN_DB_CONFIG['driver']) ? 'ya' : 'tidak'; ?>
            </span>
          </div>
        </div>
      </section>
    </div>

    <?php
    try {
        $log = adminAll('SELECT * FROM admin_log ORDER BY id DESC LIMIT 20');
    } catch (Throwable $ex) {
        $log = [];
    }
    ?>
    <section class="card" style="margin-top:16px">
      <div class="card-hd"><div><h2>Jejak Aktivitas</h2><div class="sub">20 entri terakhir dari tabel admin_log</div></div></div>
      <?php if (empty($log)): ?>
        <div class="kosong"><b>Belum ada catatan</b><p>Aktivitas login dan perubahan konten akan tercatat di sini.</p></div>
      <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th style="width:140px">Waktu</th><th style="width:200px">Pengguna</th><th style="width:180px">Aktivitas</th><th>Keterangan</th><th style="width:130px">IP</th></tr></thead>
            <tbody>
              <?php foreach ($log as $l): ?>
                <tr>
                  <td class="mono" style="font-size:11px"><?php echo e(date('d/m/Y H:i:s', (int) strtotime((string) $l['waktu']))); ?></td>
                  <td><?php echo e($l['username']); ?></td>
                  <td><span class="badge badge-cat"><?php echo e($l['aktivitas']); ?></span></td>
                  <td style="color:var(--muted)"><?php echo e($l['keterangan']); ?></td>
                  <td class="mono" style="font-size:11px;color:var(--muted)"><?php echo e($l['ip']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
    adminLayoutEnd();
}

/** Ubah nilai php.ini seperti "8M" menjadi byte. */
function adminIniBytes($val)
{
    $val = trim((string) $val);
    if ($val === '') return 0;
    $n   = (int) $val;
    $sat = strtolower(substr($val, -1));
    if ($sat === 'g') $n *= 1024;
    if ($sat === 'm' || $sat === 'g') $n *= 1024;
    if ($sat === 'k' || $sat === 'm' || $sat === 'g') $n *= 1024;
    return (int) $n;
}

// ===========================================================================
// S. HALAMAN ERROR & ROUTER UTAMA
// ===========================================================================

function adminViewError(Throwable $ex)
{
    $sudahAdaLayout = !empty($GLOBALS['ADMIN_LAYOUT_OPEN']);
    if (!$sudahAdaLayout) {
        adminLayoutStart('Terjadi Kesalahan', 'Halaman tidak dapat ditampilkan', adminGet('page', 'dashboard'));
    }
    ?>
    <div class="alert alert-err">
      <?php echo adminIco('alert', 18); ?>
      <div>
        <b>Terjadi kesalahan saat memproses halaman ini.</b><br>
        <?php echo e($ex->getMessage()); ?>
      </div>
    </div>
    <section class="card">
      <div class="card-bd">
        <p class="hint" style="margin-bottom:12px">
          Penyebab paling umum: tabel database admin belum dibuat, atau folder upload tidak bisa ditulis.
        </p>
        <div class="row">
          <a class="btn btn-p" href="admin.php?page=sistem"><?php echo adminIco('shield', 16); ?> Buka Cek Sistem</a>
          <a class="btn" href="admin.php?page=dashboard"><?php echo adminIco('dashboard', 16); ?> Kembali ke Dashboard</a>
          <a class="btn" href="informasi.php" target="_blank" rel="noopener"><?php echo adminIco('eye', 16); ?> Lihat Halaman Informasi</a>
        </div>
      </div>
    </section>
    <?php
    adminLayoutEnd();
}

function adminRun()
{
    adminSessionStart();
    adminEnsureUploadDir();

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // ---- semua aksi tulis lewat POST ----
    if ($method === 'POST') {
        adminHandlePost();
        if (!RSUD_ADMIN_TEST_MODE) return;  // normal: adminRedirect() sudah exit
    }

    $page = (string) adminGet('page', 'dashboard');
    if ($page === '') $page = 'dashboard';

    // ---- unduh file SQL ----
    if ($page === 'sistem' && adminGet('unduh', '') === 'sql') {
        if (!adminIsLoggedIn()) {
            adminRedirect('admin.php?page=login');
            return;
        }
        adminServeSql();
        return;
    }

    if (adminGet('keluar', '') === '1') {
        adminFlash('info', 'Anda telah keluar dari panel admin.');
    }

    // ---- belum login → tampilkan form login ----
    if (!adminIsLoggedIn()) {
        if ($page !== 'login' && $page !== 'dashboard' && $method === 'GET' && !RSUD_ADMIN_TEST_MODE) {
            adminFlash('info', 'Silakan login untuk mengelola konten informasi.');
        }
        adminViewLogin();
        return;
    }

    if ($page === 'login') {
        adminRedirect('admin.php?page=dashboard');
        return;
    }

    // ---- halaman-halaman panel ----
    try {
        switch ($page) {
            case 'slide':
            case 'informasi':
            case 'panduan':
            case 'tarif':
                adminViewEntity($page);
                break;
            case 'pengaturan':
                adminViewPengaturan();
                break;
            case 'akun':
                adminViewAkun();
                break;
            case 'sistem':
                adminViewSistem();
                break;
            case 'dashboard':
            default:
                adminViewDashboard();
                break;
        }
    } catch (Throwable $ex) {
        adminViewError($ex);
    }
}

// Jalankan panel — kecuali saat dipakai untuk pengujian (backend/tests).
if (!defined('RSUD_ADMIN_NO_RUN')) {
    adminRun();
}
