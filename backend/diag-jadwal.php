<?php
// ============================================================================
// diag-jadwal.php — ALAT DIAGNOSIS jadwal dokter (sekali pakai, hapus setelah itu)
// ----------------------------------------------------------------------------
// Buka di browser: https://<server>/<path>/diag-jadwal.php
// Menampilkan: tabel kandidat jadwal yang ada, kolomnya, nilai kolom hari/tanggal,
// dan hasil deteksi backend. TIDAK menampilkan data pasien.
//
// Akses dibatasi: hanya IP privat/LAN, atau sertakan ?key= nilai env RSUD_DIAG_KEY.
// ============================================================================
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: text/html; charset=utf-8');

$key   = getenv('RSUD_DIAG_KEY') ?: '';
$ip    = $_SERVER['REMOTE_ADDR'] ?? '';
$priv  = (bool)preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.)/', $ip);
if (!$priv && (!($key && hash_equals($key, (string)($_GET['key'] ?? ''))))) {
    http_response_code(403);
    exit('Akses ditolak. Buka dari jaringan internal/LAN atau sertakan ?key=RSUD_DIAG_KEY.');
}

$DB_HOST = getenv('RSUD_DB_HOST') ?: '192.168.22.81';
$DB_PORT = getenv('RSUD_DB_PORT') ?: '5792';
$DB_NAME = getenv('RSUD_DB_NAME') ?: 'rsud_malangbong';
$DB_USER = getenv('RSUD_DB_USER') ?: 'postgres';
$DB_PASS = getenv('RSUD_DB_PASS') ?: 'Tr4nsm3d!c MaRe#T3aM';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function box($title, $html, $color = '#1B5E20') {
    echo "<div style='border:1px solid #ccc;border-radius:12px;padding:14px 18px;margin:14px 0;background:#fff;'>";
    echo "<h3 style='margin:0 0 8px;color:" . h($color) . ";font-family:monospace'>" . h($title) . "</h3>{$html}</div>";
}

echo "<body style='font-family:system-ui,Segoe UI,sans-serif;background:#f2f5f2;margin:20px'>";
echo "<h2>🔍 Diagnosis Jadwal Dokter — RSUD Mobile</h2>";

try {
    $pdo = new PDO("pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // 1. Semua tabel yang mengandung 'jadwal'
    $rows = $pdo->query("SELECT table_name FROM information_schema.tables
                         WHERE table_schema = current_schema() AND table_name ILIKE '%jadwal%'
                         ORDER BY table_name")->fetchAll();
    $names = array_column($rows, 'table_name');
    box('1) Tabel yang mengandung kata "jadwal"',
        $names ? '<ul><li>' . implode('</li><li>', array_map('h', $names)) . '</li></ul>'
               : '<b style="color:#b71c1c">TIDAK ADA</b> — tabel jadwal memang tidak ada di skema ini!');

    // 2. Detail tiap tabel kandidat yang ditemukan
    $candidates = ['jadwaldokter_m','jadwal_dokter_m','jadwaldokter_t','jadwalpraktikdokter_m',
                   'jadwal_dokter','jadwaldokter','jadwal','jadwaldokter_harian',
                   'jadwal_praktik_dokter','jadwaldokter_spesifik'];
    if (getenv('RSUD_JADWAL_TABLE')) array_unshift($candidates, getenv('RSUD_JADWAL_TABLE'));
    $found = array_values(array_intersect($candidates, $names));
    $extra = array_values(array_diff($names, $candidates));

    foreach ($found as $t) {
        $safeT = str_replace("'", "''", $t);
        $cols = array_column($pdo->query("SELECT column_name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = '$safeT'")->fetchAll(), 'column_name');
        $n = $pdo->query("SELECT COUNT(*) AS c FROM \"$t\"")->fetch()['c'];
        $html = "<p><b>Jumlah baris:</b> $n</p><p><b>Kolom:</b> " . h(implode(', ', $cols)) . "</p>";
        // Sampel nilai hari
        foreach (['hari', 'tanggal', 'jammulai', 'jam_mulai', 'quota', 'kuota', 'objectpegawaifk', 'objectruanganfk'] as $c) {
            if (in_array($c, $cols, true)) {
                $vals = $pdo->query("SELECT DISTINCT \"$c\" FROM \"$t\" WHERE \"$c\" IS NOT NULL LIMIT 12")
                            ->fetchAll(PDO::FETCH_COLUMN);
                $html .= "<p><b>Nilai unik <code>" . h($c) . "</code> (maks 12):</b> " . h(implode(' | ', $vals)) . "</p>";
            }
        }
        box("2) Tabel kandidat: $t", $html);
    }

    // 3. Tabel jadwal di luar kandidat
    if ($extra) {
        box('3) Tabel "jadwal" DI LUAR kandidat backend (perlu konfigurasi manual)',
            '<ul><li>' . implode('</li><li>', array_map('h', $extra)) . '</li></ul>', '#e65100');
        foreach ($extra as $t) {
            $safeT = str_replace("'", "''", $t);
            $cols = array_column($pdo->query("SELECT column_name FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = '$safeT'")->fetchAll(), 'column_name');
            $n = $pdo->query("SELECT COUNT(*) AS c FROM \"$t\"")->fetch()['c'];
            $html = "<p><b>Jumlah baris:</b> $n</p><p><b>Kolom:</b> " . h(implode(', ', $cols)) . "</p>";
            foreach (['hari', 'tanggal', 'jammulai', 'jam_mulai', 'quota', 'kuota', 'objectpegawaifk', 'objectdokterfk', 'objectruanganfk', 'ruanganfk'] as $c) {
                if (in_array($c, $cols, true)) {
                    $vals = $pdo->query("SELECT DISTINCT \"$c\" FROM \"$t\" WHERE \"$c\" IS NOT NULL LIMIT 12")
                                ->fetchAll(PDO::FETCH_COLUMN);
                    $html .= "<p><b>Nilai unik <code>" . h($c) . "</code>:</b> " . h(implode(' | ', $vals)) . "</p>";
                }
            }
            box("— $t", $html, '#e65100');
        }
    }

    // 4. Cek poli (ruangan departemen 18)
    try {
        $poli = $pdo->query("SELECT id, namaruangan, prefixnoantrian FROM ruangan_m
                             WHERE objectdepartemenfk = 18 AND statusenabled = true ORDER BY namaruangan")->fetchAll();
        $html = '<table border=1 cellpadding=4 style="border-collapse:collapse;font-size:13px"><tr><th>ID</th><th>Nama</th><th>Prefix</th></tr>';
        foreach ($poli as $p) $html .= '<tr><td>' . h($p['id']) . '</td><td>' . h($p['namaruangan']) . '</td><td>' . h($p['prefixnoantrian']) . '</td></tr>';
        $html .= '</table>';
        box('4) Daftar poliklinik (ruangan_m departemen 18) — ID yang dipakai aplikasi', $html);
    } catch (Throwable $e) {
        box('4) Daftar poliklinik', 'Gagal: ' . h($e->getMessage()), '#b71c1c');
    }

    // 5. Kesimpulan deteksi
    if (!$names) {
        box('KESIMPULAN', 'Tidak ada tabel jadwal sama sekali. Jadwal dokter mungkin disimpan di server/database lain — kirimkan isi api.php yang berjalan (bagian jadwal) ke developer.', '#b71c1c');
    } elseif (!$found && $extra) {
        box('KESIMPULAN', 'Tabel jadwal ada tapi namanya di luar kandidat backend → set env <code>RSUD_JADWAL_TABLE=' . h($extra[0]) . '</code> pada PHP (atau beri tahu developer bila kolomnya juga berbeda).', '#e65100');
    } elseif ($found) {
        box('KESIMPULAN', 'Backend seharusnya mendeteksi tabel <code>' . h($found[0]) . '</code>. Bila aplikasi tetap bilang "tidak ada jadwal", kemungkinan: (a) nilai kolom hari/tanggal tidak cocok dengan tanggal yang dipilih, (b) ruangan_id di tabel jadwal ≠ ID poli di daftar atas, atau (c) kolom pegawai/ruangan berbeda nama — kirimkan output halaman ini ke developer.', '#1B5E20');
    }

    echo "<p style='color:#777;font-size:12px'>⚠️ Hapus file ini dari server setelah diagnosis selesai.</p>";
} catch (Throwable $e) {
    echo "<div style='color:#b71c1c;border:1px solid #b71c1c;padding:12px;border-radius:8px'>Koneksi/DB error: " . h($e->getMessage()) . "</div>";
}
echo "</body>";
