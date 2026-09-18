<?php
/**
 * ============================================================================
 * informasi.php — Halaman "Informasi" Aplikasi Mobile RSUD Malangbong
 * ----------------------------------------------------------------------------
 * ISI HALAMAN SEPENUHNYA DINAMIS: diambil dari database admin_info_rsudmobile
 * (MySQL) dan dikelola lewat panel backend/admin.php.
 *
 *   • Slider gambar        ← tabel `slide`
 *   • Kartu informasi      ← tabel `informasi`   (bergambar, berkategori)
 *   • Panduan (dropdown)   ← tabel `panduan`    (accordion, hemat tempat)
 *   • Kontak, judul seksi, warna, footer ← tabel `pengaturan`
 *
 * Halaman ini dibuka di dalam tab "Informasi" aplikasi (iframe) maupun langsung
 * di browser. Bila database admin sedang tidak bisa dihubungi, halaman tetap
 * tampil dengan konten cadangan — tidak pernah menampilkan error ke pasien.
 *
 * Format lain: informasi.php?format=json  → data mentah untuk dipakai native.
 * ============================================================================
 */

require_once __DIR__ . '/admin-config.php';

// ===========================================================================
// 1. AMBIL DATA
// ===========================================================================

/**
 * Muat seluruh konten halaman.
 * @return array ['ok'=>bool, 'error'=>string, 'slide'=>[], 'informasi'=>[], 'panduan'=>[], 'pengaturan'=>[]]
 */
function infoMuatKonten()
{
    $hasil = [
        'ok'         => true,
        'error'      => '',
        'slide'      => [],
        'informasi'  => [],
        'panduan'    => [],
        'tarif'      => [],
        'pengaturan' => adminSettings(),   // sudah berisi default walau DB mati
    ];

    try {
        // Pastikan database benar-benar bisa dihubungi dulu. Bila tidak,
        // halaman memakai konten cadangan (lihat infoKontenCadangan).
        adminPdo();
    } catch (Throwable $ex) {
        $hasil['ok']    = false;
        $hasil['error'] = $ex->getMessage();
        return $hasil;
    }

    try {
        if (adminTableExists('slide')) {
            $hasil['slide'] = adminAll(
                'SELECT id, judul, subjudul, gambar, tautan, warna_latar
                   FROM slide
                  WHERE status_aktif = 1
                  ORDER BY urutan ASC, id ASC
                  LIMIT 20'
            );
        }
        if (adminTableExists('informasi')) {
            $hasil['informasi'] = adminAll(
                'SELECT id, kategori, judul, konten, gambar, tautan, tanggal
                   FROM informasi
                  WHERE status_aktif = 1
                  ORDER BY urutan ASC, tanggal DESC, id DESC
                  LIMIT 50'
            );
        }
        if (adminTableExists('panduan')) {
            $hasil['panduan'] = adminAll(
                'SELECT id, judul, isi, ikon
                   FROM panduan
                  WHERE status_aktif = 1
                  ORDER BY urutan ASC, id ASC
                  LIMIT 40'
            );
        }
        if (adminTableExists('tarif')) {
            $hasil['tarif'] = adminAll(
                'SELECT id, kategori, nama_layanan, satuan, tarif, keterangan
                   FROM tarif
                  WHERE status_aktif = 1
                  ORDER BY kategori ASC, urutan ASC, id ASC
                  LIMIT 400'
            );
        }
    } catch (Throwable $ex) {
        $hasil['ok']    = false;
        $hasil['error'] = $ex->getMessage();
    }

    return $hasil;
}

/**
 * Konten cadangan bila database admin belum tersedia — memastikan halaman
 * tetap berguna (tidak kosong, tidak error) saat pertama kali dipasang.
 */
function infoKontenCadangan()
{
    $contoh = [];
    if (function_exists('adminContohSlide'))     $contoh['slide']     = adminContohSlide();
    if (function_exists('adminContohInformasi')) $contoh['informasi'] = adminContohInformasi();
    if (function_exists('adminContohPanduan'))   $contoh['panduan']   = adminContohPanduan();
    if (function_exists('adminContohTarif'))     $contoh['tarif']     = adminContohTarif();

    $slide = [];
    foreach ($contoh['slide'] ?? [] as $i => $s) {
        $slide[] = [
            'id' => $i + 1, 'judul' => $s['judul'], 'subjudul' => $s['subjudul'],
            'gambar' => '', 'tautan' => $s['tautan'], 'warna_latar' => $s['warna'],
        ];
    }

    $info = [];
    foreach ($contoh['informasi'] ?? [] as $i => $s) {
        $info[] = [
            'id' => $i + 1, 'kategori' => $s['kategori'], 'judul' => $s['judul'],
            'konten' => $s['konten'], 'gambar' => '', 'tautan' => $s['tautan'],
            'tanggal' => date('Y-m-d'),
        ];
    }

    $panduan = [];
    foreach ($contoh['panduan'] ?? [] as $i => $s) {
        $panduan[] = ['id' => $i + 1, 'judul' => $s['judul'], 'isi' => $s['isi'], 'ikon' => $s['ikon']];
    }

    // Sengaja TANPA tarif: harga tidak boleh dikarang. Bila tabel tarif belum
    // ada atau database mati, tab tarif menampilkan pesan "sedang diperbarui"
    // (lihat pengaturan `pesan_kosong_tarif`), bukan angka contoh.
    return ['slide' => $slide, 'informasi' => $info, 'panduan' => $panduan];
}

/** Kelompokkan tarif berdasarkan kategori (urutan kemunculan dipertahankan). */
function infoTarifPerKategori(array $tarifList)
{
    $kelompok = [];
    foreach ($tarifList as $t) {
        $kat = trim((string) ($t['kategori'] ?? ''));
        if ($kat === '') $kat = 'Lainnya';
        if (!isset($kelompok[$kat])) $kelompok[$kat] = [];
        $kelompok[$kat][] = $t;
    }
    return $kelompok;
}

// ===========================================================================
// 2. HELPER TAMPILAN
// ===========================================================================

/** Path/URL gambar yang benar-benar ada; kosong bila tidak ada. */
function infoGambarUrl($namaFile)
{
    $namaFile = basename((string) $namaFile);
    if ($namaFile === '') return '';
    $path = rtrim(ADMIN_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $namaFile;
    if (!is_file($path)) return '';
    return ADMIN_UPLOAD_URL . '/' . rawurlencode($namaFile);
}

/** Ikon SVG inline (tanpa CDN). */
function infoIco($nama, $ukuran = 18)
{
    $peta = [
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.8h.01"/>',
        'login'    => '<rect x="4.5" y="10" width="15" height="10.5" rx="2.2"/><path d="M8 10V7.2a4 4 0 018 0V10"/>',
        'pasien'   => '<circle cx="12" cy="7.2" r="3.4"/><path d="M5.5 20.5a6.5 6.5 0 0113 0"/><path d="M12 12.4v3.4M10.3 14.1h3.4"/>',
        'kalender' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.2"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'hasil'    => '<path d="M6.5 3h8l4.5 4.5v13H6.5z"/><path d="M14.5 3v4.5H19"/><path d="M9.5 13h5M9.5 16.5h3.5"/>',
        'riwayat'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
        'obat'     => '<rect x="2.5" y="9" width="19" height="6" rx="3"/><path d="M12 9v6"/>',
        'bayar'    => '<rect x="2.5" y="5" width="19" height="14" rx="2.2"/><path d="M2.5 10h19M6.5 15h4"/>',
        'tarif'    => '<path d="M20.5 12.5l-8 8a1.6 1.6 0 01-2.3 0l-6.2-6.2a1.6 1.6 0 01-.5-1.2V4.8A1.3 1.3 0 014.8 3.5h8.3c.4 0 .9.2 1.2.5l6.2 6.2c.6.6.6 1.7 0 2.3z"/><circle cx="8.2" cy="8.2" r="1.5"/><path d="M11.5 12.5h4M11.5 15.5h2.5"/>',
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'bantuan'  => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.6a2.5 2.5 0 114.9.8c0 1.6-2.5 2-2.5 3.4"/><path d="M12 17.2h.01"/>',
        'chevron'  => '<path d="M6 9.5l6 6 6-6"/>',
        'pin'      => '<path d="M12 21.5s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10.2" r="2.6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
        'phone'    => '<path d="M21.5 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 2 .7 2.8a2 2 0 01-.5 2.1L8 10a16 16 0 006 6l1.4-1.3a2 2 0 012.1-.5c.9.3 1.9.6 2.8.7a2 2 0 011.7 2z"/>',
        'wa'       => '<path d="M21 11.6a8.4 8.4 0 01-12.3 7.5L3.5 21l1.9-5A8.4 8.4 0 1121 11.6z"/><path d="M9 9.2c0 3 2 5 4.8 5.4.6.1 1.2-.4 1.2-1v-.6l-1.7-.6-.8.9a5.6 5.6 0 01-2-2l.9-.8-.6-1.7h-.6c-.7 0-1.2.6-1.2 1.4z"/>',
        'mail'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.2"/><path d="M3 7l9 6 9-6"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3.5 9.5h17M3.5 14.5h17"/><path d="M12 3a15 15 0 010 18a15 15 0 010-18z"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="M21 16.5l-4.5-4.5L7 21"/>',
        'book'     => '<path d="M5 4h11v16H6.5A1.5 1.5 0 015 18.5z"/><path d="M16 8h3v10.5A1.5 1.5 0 0117.5 20H16"/><path d="M8 8.5h5M8 12h5"/>',
        'alert'    => '<path d="M12 3.5l9 16.5H3z"/><path d="M12 10v4"/><path d="M12 17.2h.01"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14.5V19a1.5 1.5 0 01-1.5 1.5H5A1.5 1.5 0 013.5 19V7.5A1.5 1.5 0 015 6h4.5"/>',
        'arrowL'   => '<path d="M14.5 5.5L8 12l6.5 6.5"/>',
        'arrowR'   => '<path d="M9.5 5.5L16 12l-6.5 6.5"/>',
    ];
    $isi = $peta[$nama] ?? $peta['info'];
    return '<svg width="' . (int) $ukuran . '" height="' . (int) $ukuran
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $isi . '</svg>';
}

/** Warna acak namun konsisten (berdasarkan teks) untuk placeholder gambar. */
function infoWarnaDariTeks($teks)
{
    $palet = [
        ['#1b5e20', '#43a047'], ['#0f766e', '#14b8a6'], ['#1d4ed8', '#3b82f6'],
        ['#7c3aed', '#a78bfa'], ['#b45309', '#f59e0b'], ['#0e7490', '#22d3ee'],
        ['#9d174d', '#ec4899'], ['#3f6212', '#84cc16'],
    ];
    $h = 0;
    $s = (string) $teks;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $h = ($h * 31 + ord($s[$i])) % 100000;
    }
    return $palet[$h % count($palet)];
}

/** Normalisasi nomor WhatsApp → 62xxxx (untuk link wa.me). */
function infoNomorWa($nomor)
{
    $n = preg_replace('/[^0-9]/', '', (string) $nomor) ?? '';
    if ($n === '') return '';
    if (substr($n, 0, 1) === '0') $n = '62' . substr($n, 1);
    if (substr($n, 0, 2) === '62') return $n;
    return $n;
}

// ===========================================================================
// 3. OUTPUT JSON (opsional — untuk pemakaian native di masa depan)
// ===========================================================================

if (strtolower((string) ($_GET['format'] ?? '')) === 'json') {
    $data  = infoMuatKonten();
    $fallback = !$data['ok'] ? infoKontenCadangan() : null;

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode([
        'sukses'     => true,
        'sumber'     => $data['ok'] ? 'database' : 'cadangan',
        'dihasilkan' => date('c'),
        'pengaturan' => $data['pengaturan'],
        'slide'      => array_map(static function ($s) {
            $s['gambar_url'] = infoGambarUrl($s['gambar'] ?? '');
            return $s;
        }, $data['ok'] ? $data['slide'] : ($fallback['slide'] ?? [])),
        'informasi'  => array_map(static function ($s) {
            $s['gambar_url']  = infoGambarUrl($s['gambar'] ?? '');
            $s['tanggal_teks'] = adminTanggalIndo($s['tanggal'] ?? '');
            return $s;
        }, $data['ok'] ? $data['informasi'] : ($fallback['informasi'] ?? [])),
        'panduan'    => $data['ok'] ? $data['panduan'] : ($fallback['panduan'] ?? []),
        'tarif'      => array_map(static function ($t) {
            $t['tarif_teks'] = adminFormatRupiah($t['tarif'] ?? 0);
            return $t;
        }, $data['ok'] ? $data['tarif'] : []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===========================================================================
// 4. SIAPKAN DATA UNTUK DITAMPILKAN
// ===========================================================================

$data     = infoMuatKonten();
$set      = $data['pengaturan'];
$darDb    = (bool) $data['ok'];

if (!$darDb || (empty($data['slide']) && empty($data['informasi']) && empty($data['panduan']) && empty($data['tarif']))) {
    $cadangan = infoKontenCadangan();
    if (!$darDb) {
        $data['slide']     = $cadangan['slide'];
        $data['informasi'] = $cadangan['informasi'];
        $data['panduan']   = $cadangan['panduan'];
    } else {
        // database hidup tapi masih kosong → pakai contoh agar halaman tidak hampa
        if (empty($data['slide']))     $data['slide']     = $cadangan['slide'];
        if (empty($data['informasi'])) $data['informasi'] = $cadangan['informasi'];
        if (empty($data['panduan']))   $data['panduan']   = $cadangan['panduan'];
    }
    // Tarif sengaja tidak pernah diisi dari data contoh: lebih baik menampilkan
    // pesan "sedang diperbarui" daripada angka harga yang tidak resmi.
}

// Catatan: data kartu informasi ($data['informasi']) sengaja tidak dirender lagi —
// berita tampil lewat slider. Datanya tetap tersedia di ?format=json.
$slideList     = $data['slide'];
$panduanList   = $data['panduan'];
$tarifList     = $data['tarif'];

$warnaUtama    = (string) ($set['warna_utama'] ?? '#1b5e20');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $warnaUtama)) $warnaUtama = '#1b5e20';

$tampilHeader  = adminSettingBool('tampilkan_header', false);
$tampilSlider  = adminSettingBool('tampilkan_slider', true) && count($slideList) > 0;
$tampilTarif   = adminSettingBool('tampilkan_tarif', true);
$tampilPanduan = adminSettingBool('tampilkan_panduan', true);
$tampilKontak  = adminSettingBool('tampilkan_kontak', true);
$autoplay      = adminSettingBool('slider_autoplay', true);
$interval      = max(2000, min(60000, (int) ($set['slider_interval'] ?? 5000)));
$satuBuka      = adminSettingBool('panduan_buka_satu', true);

// ---------------------------------------------------------------------------
// Daftar tab di bawah slider: Tarif Layanan → Panduan → Kontak.
// Tab yang isinya dimatikan lewat pengaturan tidak ikut dirender.
// ---------------------------------------------------------------------------
$tabList = [];
if ($tampilTarif) {
    $tabList[] = [
        'id'    => 'tarif',
        'label' => (string) ($set['tarif_judul_seksi'] ?? 'Tarif Layanan'),
        'ikon'  => 'tarif',
        'jml'   => count($tarifList),
    ];
}
if ($tampilPanduan) {
    $tabList[] = [
        'id'    => 'panduan',
        'label' => (string) ($set['panduan_judul_seksi'] ?? 'Panduan'),
        'ikon'  => 'book',
        'jml'   => count($panduanList),
    ];
}
if ($tampilKontak) {
    $tabList[] = [
        'id'    => 'kontak',
        'label' => (string) ($set['kontak_judul_seksi'] ?? 'Kontak & Layanan'),
        'ikon'  => 'phone',
        'jml'   => 0,
    ];
}
$tarifKelompok = infoTarifPerKategori($tarifList);
$tarifCatatan  = trim((string) ($set['tarif_catatan'] ?? ''));
$pesanTarifKosong = trim((string) ($set['pesan_kosong_tarif'] ?? ''));
if ($pesanTarifKosong === '') {
    $pesanTarifKosong = 'Daftar tarif sedang diperbarui. Silakan hubungi petugas untuk informasi biaya.';
}
$tabIds        = array_column($tabList, 'id');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
// Catatan: header X-Frame-Options sengaja TIDAK dikirim agar halaman ini
// tetap bisa dimuat di dalam iframe tab "Informasi" aplikasi.
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="<?php echo e($warnaUtama); ?>">
<meta name="robots" content="noindex">
<title>Informasi — <?php echo e($set['app_nama'] ?? 'RSUD Malangbong'); ?></title>
<style>
  :root{
    --brand:<?php echo e($warnaUtama); ?>;
    --brand-dark:#123c16;
    --brand-soft:#e9f3ea;
    --ink:#1f2937; --muted:#6b7280; --line:#e7eae7;
    --bg:#f6f9f6; --white:#fff;
    --radius:16px;
    --shadow:0 1px 3px rgba(16,60,20,.07), 0 8px 24px rgba(16,60,20,.05);
  }
  *{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html{-webkit-text-size-adjust:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    background:var(--bg); color:var(--ink); min-height:100vh; -webkit-font-smoothing:antialiased;
  }
  img{display:block;max-width:100%}
  a{color:inherit}

  .container{max-width:660px;margin:0 auto;padding:14px 14px 34px}
  .stack{display:flex;flex-direction:column;gap:14px}

  /* ── header opsional (default: disembunyikan) ───────────── */
  .header{background:linear-gradient(180deg,var(--brand-dark),var(--brand));color:#fff;padding:20px 16px;display:flex;align-items:center;gap:12px}
  .header .logo{width:46px;height:46px;flex:none;background:#fff;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.18)}
  .header .logo svg{width:28px;height:28px}
  .header h1{font-size:16px;font-weight:800;letter-spacing:.2px}
  .header p{font-size:11.5px;opacity:.9;margin-top:2px}

  /* ── slider ─────────────────────────────────────────────── */
  .slider-wrap{position:relative;border-radius:var(--radius);overflow:hidden;background:#fff;box-shadow:var(--shadow);border:1px solid var(--line)}
  .slider{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .slider::-webkit-scrollbar{display:none}
  .slide{flex:0 0 100%;scroll-snap-align:center;position:relative}
  .slide a,.slide .no-link{display:block;text-decoration:none;color:inherit}
  .slide .media{position:relative;height:172px;background:#dfe7df;overflow:hidden}
  .slide .media img{width:100%;height:100%;object-fit:cover}
  .slide .ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.85)}
  .slide .ph svg{width:42px;height:42px;opacity:.85}
  .slide .caption{
    position:absolute;left:0;right:0;bottom:0;padding:34px 14px 13px;color:#fff;
    background:linear-gradient(180deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.62) 68%,rgba(0,0,0,.78) 100%);
  }
  .slide .caption b{display:block;font-size:14.5px;font-weight:800;line-height:1.35;text-shadow:0 1px 6px rgba(0,0,0,.4)}
  .slide .caption span{display:block;font-size:11.5px;opacity:.92;margin-top:3px;line-height:1.45;text-shadow:0 1px 5px rgba(0,0,0,.45)}
  .slider-nav{position:absolute;top:0;bottom:34px;width:38px;display:flex;align-items:center;justify-content:center;color:#fff;background:none;border:0;cursor:pointer;opacity:.75}
  .slider-nav:hover{opacity:1}
  .slider-nav.prev{left:0}
  .slider-nav.next{right:0}
  .slider-nav .bulat{width:30px;height:30px;border-radius:50%;background:rgba(0,0,0,.32);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
  .dots{display:flex;gap:6px;justify-content:center;align-items:center;padding:9px 8px 11px;background:#fff}
  .dots button{width:7px;height:7px;border-radius:999px;border:0;background:#cfd8cf;cursor:pointer;padding:0;transition:width .2s,background .2s}
  .dots button.aktif{width:20px;background:var(--brand)}

  /* ── judul seksi ────────────────────────────────────────── */
  .seksi-hd{display:flex;align-items:center;gap:8px;margin:2px 2px 0}
  .seksi-hd svg{color:var(--brand);flex:none}
  .seksi-hd h2{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--brand)}
  .seksi-hd .garis{flex:1;height:1px;background:var(--line)}
  .seksi-hd .jml{font-size:10.5px;font-weight:700;color:var(--muted);background:#fff;border:1px solid var(--line);padding:2px 8px;border-radius:999px}

  /* ── kartu informasi ────────────────────────────────────── */
  .kartu-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .kartu{background:var(--white);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}
  .kartu .media{position:relative;height:118px;background:#e7ece7;overflow:hidden}
  .kartu .media img{width:100%;height:100%;object-fit:cover}
  .kartu .ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.9)}
  .kartu .ph svg{width:30px;height:30px}
  .kartu .tag{
    position:absolute;top:8px;left:8px;background:rgba(255,255,255,.94);color:var(--brand);
    font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;padding:3px 8px;border-radius:999px;
    box-shadow:0 2px 6px rgba(0,0,0,.14);max-width:calc(100% - 16px);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .kartu .isi{padding:11px 12px 13px;display:flex;flex-direction:column;gap:5px;flex:1}
  .kartu h3{font-size:13.5px;font-weight:800;line-height:1.4}
  .kartu .tgl{font-size:10.5px;color:var(--muted);display:flex;align-items:center;gap:5px}
  .kartu .teks{font-size:12px;color:var(--muted);line-height:1.62}
  .kartu .teks p{margin-bottom:6px}
  .kartu .teks p:last-child{margin-bottom:0}
  .kartu .teks ul,.kartu .teks ol{margin:2px 0 6px 17px}
  .kartu .teks li{margin-bottom:3px}
  .kartu .teks.terpotong{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
  .kartu .selengkapnya{margin-top:auto;padding-top:7px}
  .kartu .selengkapnya button,.kartu .selengkapnya a{
    background:none;border:0;color:var(--brand);font-size:11.5px;font-weight:800;cursor:pointer;padding:0;
    display:inline-flex;align-items:center;gap:5px;text-decoration:none;
  }
  .kartu .selengkapnya button:hover,.kartu .selengkapnya a:hover{text-decoration:underline}
  .kartu .selengkapnya svg{transition:transform .2s}
  .kartu .selengkapnya button[aria-expanded="true"] svg{transform:rotate(180deg)}

  /* ── panduan (dropdown) ─────────────────────────────────── */
  .akordion{background:var(--white);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  .ak-item + .ak-item{border-top:1px solid var(--line)}
  .ak-item summary{
    list-style:none;cursor:pointer;display:flex;align-items:center;gap:11px;
    padding:13px 14px;transition:background .15s;
  }
  .ak-item summary::-webkit-details-marker{display:none}
  .ak-item summary:hover{background:#fafcfa}
  .ak-item .no{
    width:27px;height:27px;flex:none;border-radius:9px;background:var(--brand-soft);color:var(--brand);
    display:inline-flex;align-items:center;justify-content:center;font-size:11.5px;font-weight:800;
  }
  .ak-item .judul{flex:1;min-width:0}
  .ak-item .judul b{display:block;font-size:13.5px;font-weight:800;line-height:1.35}
  .ak-item .judul span{display:block;font-size:11px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ak-item .chev{flex:none;color:#9ca3af;transition:transform .22s}
  .ak-item[open] summary{background:var(--brand-soft)}
  .ak-item[open] .chev{transform:rotate(180deg);color:var(--brand)}
  .ak-item[open] .no{background:var(--brand);color:#fff}
  .ak-body{padding:2px 14px 14px 52px;font-size:12.3px;color:#4b5563;line-height:1.65}
  .ak-body p{margin-bottom:7px}
  .ak-body p:last-child{margin-bottom:0}
  .ak-body ol,.ak-body ul{margin:0 0 6px 17px}
  .ak-body li{margin-bottom:4px}
  .ak-body li::marker{color:var(--brand);font-weight:700}

  /* ── kontak ─────────────────────────────────────────────── */
  .kontak{background:var(--white);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  .kontak .row{display:flex;align-items:center;gap:11px;padding:12px 14px;border-top:1px solid #f1f3f1;text-decoration:none}
  .kontak .row:first-child{border-top:0}
  .kontak a.row:hover{background:#fafcfa}
  .kontak .ic{width:34px;height:34px;flex:none;border-radius:10px;background:var(--brand-soft);color:var(--brand);display:inline-flex;align-items:center;justify-content:center}
  .kontak b{display:block;font-size:12.5px;font-weight:700}
  .kontak span{display:block;font-size:11.5px;color:var(--muted);word-break:break-word}
  .kontak .go{margin-left:auto;color:#9ca3af;font-size:14px;flex:none}

  /* ── navigasi tab (Tarif / Panduan / Kontak) ────────────── */
  .tabnav{
    position:sticky;top:0;z-index:20;display:flex;gap:4px;padding:6px;
    background:rgba(255,255,255,.94);backdrop-filter:blur(8px);
    border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
  }
  .tabnav button{
    flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:3px;
    padding:8px 4px 7px;border:0;border-radius:11px;background:transparent;color:var(--muted);
    font:inherit;font-size:11px;font-weight:700;line-height:1.2;cursor:pointer;
    transition:background .18s,color .18s;
  }
  .tabnav button svg{flex:none}
  .tabnav button .lbl{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tabnav button:hover{background:#f2f6f2;color:var(--ink)}
  .tabnav button[aria-selected="true"]{background:var(--brand);color:#fff;box-shadow:0 2px 8px rgba(27,94,32,.24)}
  .tabnav button:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
  .tabpanel[hidden]{display:none}
  .tabpanel{animation:muncul .22s ease-out}
  @keyframes muncul{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.tabpanel{animation:none}}

  /* ── tarif layanan ──────────────────────────────────────── */
  .cari-tarif{position:relative;margin:10px 0 12px}
  .cari-tarif input{
    width:100%;padding:10px 12px 10px 34px;font:inherit;font-size:12.5px;color:var(--ink);
    background:var(--white);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);
  }
  .cari-tarif input:focus{outline:2px solid var(--brand);outline-offset:1px;border-color:var(--brand)}
  .cari-tarif svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)}
  .tarif-grup + .tarif-grup{margin-top:12px}
  .tarif-grup h3{
    display:flex;align-items:center;gap:7px;font-size:11px;font-weight:800;text-transform:uppercase;
    letter-spacing:.6px;color:var(--brand);margin:0 2px 6px;
  }
  .tarif-grup h3 .garis{flex:1;height:1px;background:var(--line)}
  .tarif-grup h3 .jml{font-size:10px;color:var(--muted);background:#fff;border:1px solid var(--line);padding:1px 7px;border-radius:999px;letter-spacing:0}
  .tarif-list{background:var(--white);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  .tarif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 13px;border-top:1px solid #f1f3f1}
  .tarif-item:first-child{border-top:0}
  .tarif-item .nama{flex:1;min-width:0}
  .tarif-item .nama b{display:block;font-size:12.8px;font-weight:700;line-height:1.4}
  .tarif-item .nama span{display:block;font-size:11px;color:var(--muted);margin-top:2px;line-height:1.5}
  .tarif-item .harga{flex:none;text-align:right;white-space:nowrap}
  .tarif-item .harga b{display:block;font-size:12.8px;font-weight:800;color:var(--brand);font-variant-numeric:tabular-nums}
  .tarif-item .harga span{display:block;font-size:10.5px;color:var(--muted);margin-top:2px}
  .tarif-item[hidden]{display:none}
  .tarif-grup[hidden]{display:none}
  .catatan-tarif{margin-top:10px;font-size:11px;color:var(--muted);line-height:1.6;background:#f4f7f4;border:1px dashed var(--line);border-radius:12px;padding:9px 11px}

  /* ── footer & catatan ───────────────────────────────────── */
  .footer{text-align:center;font-size:11px;color:#9ca3af;padding:18px 14px 30px;line-height:1.7}
  .footer b{color:var(--brand)}
  .catatan{
    display:flex;gap:9px;align-items:flex-start;background:#fff8e6;border:1px solid #f4e2b8;color:#7c5b12;
    border-radius:12px;padding:10px 12px;font-size:11.5px;line-height:1.5;
  }
  .catatan svg{flex:none;margin-top:1px}
  .kosong{background:#fff;border:1px dashed var(--line);border-radius:var(--radius);padding:22px 16px;text-align:center;color:var(--muted);font-size:12.5px}

  /* ── layar sempit ───────────────────────────────────────── */
  @media (max-width:520px){
    .kartu-grid{grid-template-columns:minmax(0,1fr)}
    .slide .media{height:158px}
    .kartu .media{height:132px}
    .slider-nav{display:none}
    .ak-body{padding-left:14px}
  }
  @media (prefers-reduced-motion:reduce){
    *{scroll-behavior:auto!important;transition:none!important}
  }
</style>
</head>
<body>

<?php if ($tampilHeader): ?>
  <header class="header">
    <span class="logo">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
        <rect width="48" height="48" rx="13" fill="#fff"/>
        <path d="M24 9v30M9 24h30" stroke="<?php echo e($warnaUtama); ?>" stroke-width="6" stroke-linecap="round"/>
        <circle cx="24" cy="24" r="9" fill="#2e7d32" stroke="#fff" stroke-width="3"/>
        <circle cx="24" cy="24" r="3" fill="#fff"/>
      </svg>
    </span>
    <div>
      <h1><?php echo e($set['app_nama'] ?? 'RSUD Malangbong'); ?></h1>
      <p><?php echo e($set['app_subjudul'] ?? ''); ?></p>
    </div>
  </header>
<?php endif; ?>

<main class="container">
  <div class="stack">

    <?php if (!$darDb): ?>
      <div class="catatan">
        <?php echo infoIco('alert', 16); ?>
        <div>Menampilkan informasi cadangan karena server konten sedang tidak dapat dihubungi.
        Muat ulang halaman beberapa saat lagi.</div>
      </div>
    <?php endif; ?>

    <!-- ══════════ SLIDER GAMBAR ══════════ -->
    <?php if ($tampilSlider && count($slideList) > 0): ?>
      <section aria-label="Slider informasi">
        <div class="slider-wrap">
          <div class="slider" id="slider"
               data-autoplay="<?php echo $autoplay ? '1' : '0'; ?>"
               data-interval="<?php echo (int) $interval; ?>">
            <?php foreach ($slideList as $i => $sl):
                $imgUrl = infoGambarUrl($sl['gambar'] ?? '');
                $warna  = (string) ($sl['warna_latar'] ?? '');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $warna)) {
                    [$c1, $c2] = infoWarnaDariTeks((string) ($sl['judul'] ?? $i));
                    $warna = $c1;
                    $grad  = 'linear-gradient(135deg,' . $c1 . ',' . $c2 . ')';
                } else {
                    $grad = 'linear-gradient(135deg,' . $warna . ',' . $warna . 'dd)';
                }
                $tautan = trim((string) ($sl['tautan'] ?? ''));
                $boleh  = $tautan !== '' && preg_match('#^(https?://|mailto:|tel:)#i', $tautan);
            ?>
              <div class="slide" role="group" aria-roledescription="slide" aria-label="<?php echo (int) ($i + 1); ?> dari <?php echo count($slideList); ?>">
                <?php if ($boleh): ?><a href="<?php echo eUrl($tautan); ?>"><?php else: ?><span class="no-link"><?php endif; ?>
                  <div class="media" style="background:<?php echo e($grad); ?>">
                    <?php if ($imgUrl !== ''): ?>
                      <img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($sl['judul']); ?>" loading="<?php echo $i === 0 ? 'eager' : 'lazy'; ?>" decoding="async">
                    <?php else: ?>
                      <div class="ph"><?php echo infoIco('image', 42); ?></div>
                    <?php endif; ?>
                    <div class="caption">
                      <b><?php echo e($sl['judul']); ?></b>
                      <?php if (!empty($sl['subjudul'])): ?><span><?php echo e($sl['subjudul']); ?></span><?php endif; ?>
                    </div>
                  </div>
                <?php if ($boleh): ?></a><?php else: ?></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (count($slideList) > 1): ?>
            <button class="slider-nav prev" type="button" data-arah="-1" aria-label="Slide sebelumnya">
              <span class="bulat"><?php echo infoIco('arrowL', 17); ?></span>
            </button>
            <button class="slider-nav next" type="button" data-arah="1" aria-label="Slide berikutnya">
              <span class="bulat"><?php echo infoIco('arrowR', 17); ?></span>
            </button>
            <div class="dots" id="dots" role="tablist" aria-label="Pilih slide">
              <?php foreach ($slideList as $i => $s): ?>
                <button type="button" role="tab" data-index="<?php echo (int) $i; ?>"
                        class="<?php echo $i === 0 ? 'aktif' : ''; ?>"
                        aria-label="Slide <?php echo (int) ($i + 1); ?>"
                        aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- ══════════ NAVIGASI TAB (Tarif Layanan / Panduan / Kontak) ══════════ -->
    <?php if (count($tabList) > 0): ?>
      <nav class="tabnav" id="tabnav" role="tablist" aria-label="Navigasi informasi"
           data-tab-awal="<?php echo e($tabIds[0] ?? ''); ?>">
        <?php foreach ($tabList as $i => $t): ?>
          <button type="button" role="tab" id="tab-<?php echo e($t['id']); ?>"
                  data-tab="<?php echo e($t['id']); ?>"
                  aria-controls="panel-<?php echo e($t['id']); ?>"
                  aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                  tabindex="<?php echo $i === 0 ? '0' : '-1'; ?>">
            <?php echo infoIco($t['ikon'], 18); ?>
            <span class="lbl"><?php echo e($t['label']); ?></span>
          </button>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <!-- ══════════ TAB 1 — TARIF LAYANAN ══════════ -->
    <?php if ($tampilTarif): ?>
      <section class="tabpanel" id="panel-tarif" role="tabpanel" aria-labelledby="tab-tarif" tabindex="0">
        <div class="seksi-hd">
          <?php echo infoIco('tarif', 15); ?>
          <h2><?php echo e($set['tarif_judul_seksi'] ?? 'Tarif Layanan'); ?></h2>
          <span class="garis"></span>
          <?php if (count($tarifList) > 0): ?><span class="jml"><?php echo count($tarifList); ?></span><?php endif; ?>
        </div>

        <?php if (count($tarifList) === 0): ?>
          <div class="kosong" style="margin-top:10px">
            <?php echo e($pesanTarifKosong); ?>
          </div>
        <?php else: ?>
          <?php if (count($tarifList) > 8): ?>
            <div class="cari-tarif">
              <?php echo infoIco('search', 15); ?>
              <input type="search" id="cariTarif" autocomplete="off"
                     placeholder="Cari nama layanan atau kategori…"
                     aria-label="Cari tarif layanan" aria-controls="daftarTarif">
            </div>
          <?php endif; ?>

          <div id="daftarTarif">
            <?php foreach ($tarifKelompok as $kat => $baris): ?>
              <div class="tarif-grup">
                <h3><span><?php echo e($kat); ?></span><span class="garis"></span><span class="jml"><?php echo count($baris); ?></span></h3>
                <div class="tarif-list">
                  <?php foreach ($baris as $t): ?>
                    <div class="tarif-item"
                         data-cari="<?php echo e(strtolower($t['nama_layanan'] . ' ' . $kat . ' ' . ($t['satuan'] ?? ''))); ?>">
                      <span class="nama">
                        <b><?php echo e($t['nama_layanan']); ?></b>
                        <?php if (!empty($t['keterangan'])): ?><span><?php echo e($t['keterangan']); ?></span><?php endif; ?>
                      </span>
                      <span class="harga">
                        <b><?php echo e(adminFormatRupiah($t['tarif'] ?? 0)); ?></b>
                        <?php if (!empty($t['satuan'])): ?><span><?php echo e($t['satuan']); ?></span><?php endif; ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="kosong" id="tarifKosong" style="margin-top:10px" hidden>
            Tidak ada layanan yang cocok dengan kata kunci tersebut.
          </div>
          <?php if ($tarifCatatan !== ''): ?>
            <div class="catatan-tarif"><?php echo e($tarifCatatan); ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- ══════════ TAB 2 — PANDUAN (DROPDOWN) ══════════ -->
    <?php if ($tampilPanduan): ?>
      <section class="tabpanel" id="panel-panduan" role="tabpanel" aria-labelledby="tab-panduan" tabindex="0" hidden>
        <div class="seksi-hd">
          <?php echo infoIco('book', 15); ?>
          <h2><?php echo e($set['panduan_judul_seksi'] ?? 'Panduan Pemakaian'); ?></h2>
          <span class="garis"></span>
          <?php if (count($panduanList) > 0): ?><span class="jml"><?php echo count($panduanList); ?></span><?php endif; ?>
        </div>

        <?php if (count($panduanList) === 0): ?>
          <div class="kosong" style="margin-top:10px">Belum ada panduan yang ditambahkan.</div>
        <?php else: ?>
          <div class="akordion" id="akordion" style="margin-top:10px" data-satu="<?php echo $satuBuka ? '1' : '0'; ?>">
            <?php foreach ($panduanList as $i => $p):
                $ringkas = adminRingkas($p['isi'] ?? '', 58);
            ?>
              <details class="ak-item"<?php echo $i === 0 ? ' open' : ''; ?>>
                <summary>
                  <span class="no"><?php echo infoIco(!empty($p['ikon']) ? $p['ikon'] : 'info', 15); ?></span>
                  <span class="judul">
                    <b><?php echo e($p['judul']); ?></b>
                    <?php if ($ringkas !== ''): ?><span><?php echo e($ringkas); ?></span><?php endif; ?>
                  </span>
                  <span class="chev"><?php echo infoIco('chevron', 17); ?></span>
                </summary>
                <div class="ak-body"><?php echo adminFormatTeks($p['isi'] ?? ''); ?></div>
              </details>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- ══════════ TAB 3 — KONTAK & LAYANAN ══════════ -->
    <?php if ($tampilKontak):
        $wa      = infoNomorWa($set['whatsapp'] ?? '');
        $barisK  = [];
        if (!empty($set['alamat'])) {
            $barisK[] = ['ikon' => 'pin', 'label' => 'Lokasi', 'nilai' => $set['alamat'],
                          'link' => (!empty($set['maps']) && preg_match('#^https?://#i', (string) $set['maps'])) ? $set['maps'] : ''];
        }
        if (!empty($set['jam_layanan'])) $barisK[] = ['ikon' => 'clock', 'label' => 'Jam Pelayanan', 'nilai' => $set['jam_layanan'], 'link' => ''];
        if (!empty($set['telepon']))     $barisK[] = ['ikon' => 'phone', 'label' => 'Telepon / IGD', 'nilai' => $set['telepon'], 'link' => 'tel:' . preg_replace('/[^0-9+]/', '', (string) $set['telepon'])];
        if ($wa !== '')                  $barisK[] = ['ikon' => 'wa', 'label' => 'Hotline WhatsApp', 'nilai' => $set['whatsapp'], 'link' => 'https://wa.me/' . $wa];
        if (!empty($set['email']))       $barisK[] = ['ikon' => 'mail', 'label' => 'Email', 'nilai' => $set['email'], 'link' => 'mailto:' . $set['email']];
        if (!empty($set['website']))     $barisK[] = ['ikon' => 'globe', 'label' => 'Website', 'nilai' => preg_replace('#^https?://#i', '', (string) $set['website']), 'link' => $set['website']];
    ?>
      <section class="tabpanel" id="panel-kontak" role="tabpanel" aria-labelledby="tab-kontak" tabindex="0" hidden>
        <div class="seksi-hd">
          <?php echo infoIco('phone', 15); ?>
          <h2><?php echo e($set['kontak_judul_seksi'] ?? 'Kontak & Layanan'); ?></h2>
          <span class="garis"></span>
        </div>

        <?php if (count($barisK) === 0): ?>
          <div class="kosong" style="margin-top:10px">Informasi kontak belum diisi.</div>
        <?php else: ?>
          <div class="kontak" style="margin-top:10px">
            <?php foreach ($barisK as $b):
                $tag = (!empty($b['link']) && preg_match('#^(https?://|mailto:|tel:)#i', (string) $b['link'])) ? 'a' : 'div';
            ?>
              <<?php echo $tag; ?> class="row"<?php if ($tag === 'a'): ?> href="<?php echo eUrl($b['link']); ?>"<?php echo preg_match('#^https?://#i', (string) $b['link']) ? ' target="_blank" rel="noopener"' : ''; ?><?php endif; ?>>
                <span class="ic"><?php echo infoIco($b['ikon'], 17); ?></span>
                <span style="min-width:0"><b><?php echo e($b['label']); ?></b><span><?php echo e($b['nilai']); ?></span></span>
                <?php if ($tag === 'a'): ?><span class="go">›</span><?php endif; ?>
              </<?php echo $tag; ?>>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

  </div>
</main>

<footer class="footer">
  <b><?php echo e($set['footer_teks'] ?? 'RSUD Malangbong — Kabupaten Garut, Jawa Barat'); ?></b><br>
  &copy; <?php echo date('Y'); ?> <?php echo e($set['app_nama'] ?? 'RSUD Malangbong'); ?>
</footer>

<script>
(function(){
  "use strict";

  /* ---------- slider: autoplay, dot, tombol panah, geser sentuh ---------- */
  var slider = document.getElementById('slider');
  if (slider) {
    var dots   = Array.prototype.slice.call(document.querySelectorAll('#dots button'));
    var jumlah = slider.querySelectorAll('.slide').length;
    var indeks = 0;
    var timer  = null;
    var jeda   = parseInt(slider.getAttribute('data-interval') || '5000', 10);
    var auto   = slider.getAttribute('data-autoplay') === '1';
    var sentuh = false;
    var kurangGerak = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function lebarSlide() {
      var s = slider.querySelector('.slide');
      return s ? s.getBoundingClientRect().width : slider.clientWidth;
    }
    function keIndeks(n, halus) {
      if (jumlah === 0) return;
      indeks = (n + jumlah) % jumlah;
      slider.scrollTo({ left: indeks * lebarSlide(), behavior: kurangGerak ? 'auto' : (halus === false ? 'auto' : 'smooth') });
      sinkronDot();
    }
    function sinkronDot() {
      dots.forEach(function(d, i){
        d.classList.toggle('aktif', i === indeks);
        d.setAttribute('aria-selected', i === indeks ? 'true' : 'false');
      });
    }
    function mulai() {
      if (!auto || jumlah < 2 || kurangGerak) return;
      berhenti();
      timer = setInterval(function(){ if (!sentuh && !document.hidden) keIndeks(indeks + 1); }, jeda);
    }
    function berhenti() { if (timer) { clearInterval(timer); timer = null; } }

    dots.forEach(function(d){
      d.addEventListener('click', function(){ keIndeks(parseInt(d.getAttribute('data-index'), 10)); mulai(); });
    });
    document.querySelectorAll('.slider-nav').forEach(function(btn){
      btn.addEventListener('click', function(){
        keIndeks(indeks + parseInt(btn.getAttribute('data-arah'), 10));
        mulai();
      });
    });

    var tunggu = null;
    slider.addEventListener('scroll', function(){
      if (tunggu) clearTimeout(tunggu);
      tunggu = setTimeout(function(){
        var w = lebarSlide();
        if (w > 0) {
          var baru = Math.round(slider.scrollLeft / w);
          if (baru !== indeks && baru >= 0 && baru < jumlah) { indeks = baru; sinkronDot(); }
        }
      }, 90);
    }, { passive: true });

    slider.addEventListener('pointerdown', function(){ sentuh = true; berhenti(); });
    slider.addEventListener('touchstart',  function(){ sentuh = true; berhenti(); }, { passive: true });
    ['pointerup','pointercancel','touchend'].forEach(function(ev){
      slider.addEventListener(ev, function(){ setTimeout(function(){ sentuh = false; mulai(); }, 2500); });
    });
    document.addEventListener('visibilitychange', function(){ document.hidden ? berhenti() : mulai(); });
    window.addEventListener('resize', function(){ keIndeks(indeks, false); });

    sinkronDot();
    mulai();
  }

  /* ---------- kartu: tombol "Selengkapnya" ---------- */
  document.querySelectorAll('.selengkapnya button[data-target]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var el = document.getElementById(btn.getAttribute('data-target'));
      if (!el) return;
      var buka = el.classList.toggle('terpotong') === false;
      btn.setAttribute('aria-expanded', buka ? 'true' : 'false');
      var lbl = btn.querySelector('.lbl');
      if (lbl) lbl.textContent = buka ? 'Sembunyikan' : 'Selengkapnya';
    });
  });

  /* ---------- panduan dropdown: satu terbuka pada satu waktu ---------- */
  var ak = document.getElementById('akordion');
  if (ak && ak.getAttribute('data-satu') === '1') {
    var items = Array.prototype.slice.call(ak.querySelectorAll('details.ak-item'));
    items.forEach(function(d){
      d.addEventListener('toggle', function(){
        if (!d.open) return;
        items.forEach(function(o){ if (o !== d && o.open) o.open = false; });
      });
    });
  }

  /* ---------- navigasi tab: Tarif / Panduan / Kontak ----------
     Berpindah "halaman" tanpa memuat ulang informasi.php: panel disembunyikan
     lewat atribut hidden, posisi terakhir diingat lewat hash + localStorage. */
  var nav = document.getElementById('tabnav');
  if (nav) {
    var tombol = Array.prototype.slice.call(nav.querySelectorAll('button[role="tab"]'));
    var KEY_TAB = 'rsudTabInformasi';

    function adaTab(id) {
      return tombol.some(function(b){ return b.getAttribute('data-tab') === id; });
    }
    function tabAwal() {
      var dariHash = (location.hash || '').replace('#', '');
      if (adaTab(dariHash)) return dariHash;
      try {
        var simpan = window.localStorage.getItem(KEY_TAB);
        if (adaTab(simpan)) return simpan;
      } catch (e) { /* localStorage diblokir (mis. mode privat) — abaikan */ }
      return nav.getAttribute('data-tab-awal') || (tombol[0] && tombol[0].getAttribute('data-tab'));
    }
    function buka(id, fokus) {
      if (!adaTab(id)) return;
      tombol.forEach(function(b){
        var aktif = b.getAttribute('data-tab') === id;
        b.setAttribute('aria-selected', aktif ? 'true' : 'false');
        b.setAttribute('tabindex', aktif ? '0' : '-1');
        var panel = document.getElementById('panel-' + b.getAttribute('data-tab'));
        if (panel) panel.hidden = !aktif;
        if (aktif && fokus) b.focus();
      });
      try { window.localStorage.setItem(KEY_TAB, id); } catch (e) {}
      if (location.hash !== '#' + id) {
        try { history.replaceState(null, '', '#' + id); } catch (e) { location.hash = id; }
      }
    }

    tombol.forEach(function(b, i){
      b.addEventListener('click', function(){ buka(b.getAttribute('data-tab')); });
      b.addEventListener('keydown', function(ev){
        var n = null;
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') n = (i + 1) % tombol.length;
        else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') n = (i - 1 + tombol.length) % tombol.length;
        else if (ev.key === 'Home') n = 0;
        else if (ev.key === 'End') n = tombol.length - 1;
        if (n === null) return;
        ev.preventDefault();
        buka(tombol[n].getAttribute('data-tab'), true);
      });
    });

    window.addEventListener('hashchange', function(){
      var id = (location.hash || '').replace('#', '');
      if (adaTab(id)) buka(id);
    });

    buka(tabAwal());
  }

  /* ---------- cari tarif (menyaring daftar tanpa memuat ulang) ---------- */
  var cari = document.getElementById('cariTarif');
  if (cari) {
    var grup    = Array.prototype.slice.call(document.querySelectorAll('#daftarTarif .tarif-grup'));
    var kosong  = document.getElementById('tarifKosong');
    var normalisasi = function (t) {
      return String(t).toLowerCase().replace(/\s+/g, ' ').trim();
    };
    cari.addEventListener('input', function(){
      var q = normalisasi(cari.value);
      var kata = q.split(' ').filter(function(k){ return k !== ''; });
      var totalTampil = 0;

      grup.forEach(function(g){
        var tampilGrup = 0;
        g.querySelectorAll('.tarif-item').forEach(function(item){
          var teks = normalisasi(item.getAttribute('data-cari') || '');
          var cocok = kata.every(function(k){ return teks.indexOf(k) !== -1; });
          item.hidden = !cocok;
          if (cocok) tampilGrup++;
        });
        g.hidden = tampilGrup === 0;
        totalTampil += tampilGrup;
      });

      if (kosong) kosong.hidden = totalTampil > 0;
    });
  }
})();
</script>

</body>
</html>
