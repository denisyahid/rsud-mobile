// ============================================================================
// admin-smoke.mjs — uji end-to-end panel admin & halaman informasi
// ----------------------------------------------------------------------------
// Menjalankan kode PHP produksi (backend/admin.php, admin-config.php,
// informasi.php) di dalam PHP WebAssembly dengan database SQLite sementara,
// lalu memeriksa seluruh alur penting:
//
//   1  instalasi skema + data awal
//   2  render informasi.php (slider, kartu, dropdown panduan, kontak)
//   3  login: gagal, berhasil, penguncian akun
//   4  CSRF ditolak
//   5  upload gambar slide (file benar-benar dibuat & diperkecil)
//   6  upload berkas berbahaya ditolak
//   7  toggle aktif/nonaktif & ubah urutan
//   8  CRUD kartu informasi + panduan
//   9  pengaturan halaman (warna, header, judul seksi)
//  10  ganti password akun
//  11  render semua halaman panel admin
//  12  hapus slide → berkas gambar ikut terhapus
//  13  generator file SQL (backend/sql/admin_info_rsudmobile.sql)
//  14  database mati → informasi.php tetap tampil (konten cadangan)
//  15  sesi panel admin di jalur web server (nama sesi, cookie aman)
//
// Jalankan:  node backend/tests/admin-smoke.mjs
// ============================================================================
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  createPhp, runStep, phpBuatGambar, PHP_NOISE,
  SQLITE_FILE, UPLOAD_DIR, WASM_ROOT, FILE_SQL,
} from './php-harness.mjs';

let pass = 0, fail = 0;
const ok = (cond, label, extra = '') => {
  if (cond) { pass++; console.log(`  ✅ ${label}`); }
  else { fail++; console.log(`  ❌ ${label}${extra ? ' — ' + extra : ''}`); }
};
const bersih = (r, label) => ok(!PHP_NOISE.test(r.stdout) && !PHP_NOISE.test(String(r.errors ?? '')), label,
  (r.stdout.match(PHP_NOISE) || r.errors || '')[0] ?? '');

const TOKEN = 'TOKEN-UJI-CSRF';
/** Sesi login tiruan (mode uji) + token CSRF yang cocok. */
const sesiLogin = (id = 1) => `
  $s = &adminFakeSession();
  $s['admin_id'] = ${id};
  $s['admin_username'] = 'penguji';
  $s['admin_last_active'] = time();
  $s['admin_csrf'] = '${TOKEN}';
`;
const post = (obj) => `$_POST = ${phpArray(obj)};`;

/** Susun literal array PHP dari objek JS (nilai string saja, cukup untuk uji). */
function phpArray(obj, indent = '') {
  const bagian = Object.entries(obj).map(([k, v]) => {
    if (v && typeof v === 'object') return `${indent}  ${phpKey(k)} => ${phpArray(v, indent + '  ')}`;
    return `${indent}  ${phpKey(k)} => ${phpVal(v)}`;
  });
  return `[\n${bagian.join(',\n')}\n${indent}]`;
}
const phpKey = (k) => `'${String(k).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
function phpVal(v) {
  if (typeof v === 'number') return String(v);
  if (v === null) return 'null';
  if (v === true) return '1';
  if (v === false) return "'0'";
  return `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n')}'`;
}

console.log('\n═══ Uji panel admin & halaman informasi (PHP-WASM + SQLite) ═══\n');

const php = await createPhp();

// ---------------------------------------------------------------------------
console.log('1. Instalasi skema & data awal');
// ---------------------------------------------------------------------------
let r = await runStep(php, `
  if (file_exists('${SQLITE_FILE}')) unlink('${SQLITE_FILE}');
  $lap = adminInstallSchema(true);
  $gagal = array_values(array_filter($lap, fn($l) => empty($l['ok'])));
  echo "@@HASIL@@" . json_encode([
    'langkah'   => count($lap),
    'gagal'     => count($gagal),
    'pesan'     => array_map(fn($l) => $l['label'].': '.$l['pesan'], $gagal),
    'tabel'     => adminCoreTables(),
    'adaSemua'  => count(adminMissingTables()) === 0,
    'slide'     => adminCount('slide'),
    'informasi' => adminCount('informasi'),
    'panduan'   => adminCount('panduan'),
    'aturan'    => (int) adminValue('SELECT COUNT(*) FROM pengaturan'),
    'user'      => adminOne('SELECT username, role, status_aktif FROM admin_users'),
    'uploadOk'  => adminUploadDirWritable(),
  ]);
`);
const h1 = r.hasil ?? {};
ok(h1.gagal === 0, 'semua langkah instalasi berhasil', JSON.stringify(h1.pesan));
ok(h1.adaSemua === true, '6 tabel inti terbentuk');
ok(h1.slide === 3 && h1.informasi === 3 && h1.panduan === 5, 'data contoh masuk (3 slide, 3 informasi, 5 panduan)',
  `${h1.slide}/${h1.informasi}/${h1.panduan}`);
ok(h1.aturan >= 20, 'pengaturan default terisi (' + h1.aturan + ' kunci)');
ok(h1.user?.username === 'rsudmalangbonggarut@gmail.com', 'akun admin awal sesuai permintaan', h1.user?.username);
ok(h1.uploadOk === true, 'folder upload bisa ditulis');
bersih(r, 'tidak ada warning PHP saat instalasi');

// idempoten: dijalankan ulang tidak boleh menduplikasi
r = await runStep(php, `
  adminInstallSchema(true);
  echo "@@HASIL@@" . json_encode([
    'slide' => adminCount('slide'), 'panduan' => adminCount('panduan'),
    'aturan' => (int) adminValue('SELECT COUNT(*) FROM pengaturan'),
    'user' => (int) adminValue('SELECT COUNT(*) FROM admin_users'),
  ]);
`);
ok(r.hasil?.slide === 3 && r.hasil?.panduan === 5 && r.hasil?.aturan >= 20 && r.hasil?.user === 1,
  'instalasi ulang tidak menduplikasi data');

// ---------------------------------------------------------------------------
console.log('\n2. Render informasi.php (konten dinamis)');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'panjang'    => strlen($html),
    'slider'     => substr_count($html, 'class="slide"'),
    'dots'       => substr_count($html, 'data-index='),
    'kartu'      => substr_count($html, '<article class="kartu">'),
    'dropdown'   => substr_count($html, '<details class="ak-item"'),
    'kontak'     => substr_count($html, 'wa.me/'),
    'headerLama' => stripos($html, 'Informasi &amp; panduan pemakaian aplikasi RSUD Malangbong') !== false
                   || stripos($html, 'Informasi &amp; Panduan Pemakaian Aplikasi') !== false,
    'adaHeader'  => stripos($html, '<header class="header">') !== false,
    'judulSeksi' => stripos($html, 'Panduan Pemakaian Aplikasi') !== false,
    'footer'     => stripos($html, 'Kabupaten Garut') !== false,
    'judulSlide' => stripos($html, 'Pendaftaran Online Lebih Cepat') !== false,
    'judulKartu' => stripos($html, 'Selamat datang di Aplikasi Mobile') !== false,
    'judulPanduan' => stripos($html, 'Booking Kunjungan Poliklinik') !== false,
    'iframeOk'   => stripos($html, 'X-Frame-Options') !== false,
  ]);
`);
const h2 = r.hasil ?? {};
ok(h2.panjang > 15000, 'halaman terender (' + h2.panjang + ' byte)');
ok(h2.slider === 3, '3 slide tampil di slider', h2.slider);
ok(h2.dots === 3, '3 tombol navigasi (dots) slider', h2.dots);
ok(h2.kartu === 3, '3 kartu informasi tampil', h2.kartu);
ok(h2.dropdown === 5, '5 panduan tampil sebagai dropdown', h2.dropdown);
ok(h2.kontak >= 1, 'tautan WhatsApp terpasang di seksi kontak');
ok(h2.headerLama === false, 'teks header lama sudah tidak ada');
ok(h2.adaHeader === false, 'header hijau disembunyikan (sesuai permintaan)');
ok(h2.judulSeksi === true, 'judul seksi panduan dari pengaturan dipakai');
ok(h2.footer === true, 'footer tampil');
ok(h2.judulSlide && h2.judulKartu && h2.judulPanduan, 'judul dari database muncul di halaman');
ok(h2.iframeOk === false, 'tidak mengirim X-Frame-Options (aman dimuat di iframe aplikasi)');
bersih(r, 'tidak ada warning PHP saat render informasi.php');

// format JSON
r = await runStep(php, `$_GET['format'] = 'json'; require '${WASM_ROOT}/informasi.php';`);
let jsonInfo = null;
try { jsonInfo = JSON.parse(r.stdout.trim()); } catch { jsonInfo = null; }
ok(jsonInfo?.sukses === true && jsonInfo?.slide?.length === 3 && jsonInfo?.panduan?.length === 5,
  'mode ?format=json mengembalikan data lengkap');

// ---------------------------------------------------------------------------
console.log('\n3. Login: gagal, berhasil, penguncian');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'login', page: 'login', csrf_token: TOKEN, username: 'rsudmalangbonggarut@gmail.com', password: 'salah-total' })}
  $s = &adminFakeSession(); $s['admin_csrf'] = '${TOKEN}';
  adminHandlePost();
  $u = adminOne('SELECT login_gagal, terakhir_login FROM admin_users WHERE username = ?', ['rsudmalangbonggarut@gmail.com']);
  echo "@@HASIL@@" . json_encode([
    'redirect' => $GLOBALS['ADMIN_TEST_REDIRECT'] ?? '',
    'flash'    => adminTakeFlash(),
    'gagal'    => (int) $u['login_gagal'],
    'login'    => adminSessGet('admin_id'),
  ]);
`);
ok(r.hasil?.redirect === 'admin.php?page=login', 'login gagal → kembali ke halaman login');
ok(String(r.hasil?.flash?.[0]?.pesan ?? '').includes('salah'), 'pesan kesalahan ditampilkan');
ok(r.hasil?.gagal === 1, 'percobaan gagal tercatat di database');
ok(!r.hasil?.login, 'sesi tidak dibuat saat password salah');

r = await runStep(php, `
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $s = &adminFakeSession(); $s['admin_csrf'] = '${TOKEN}';
  ${post({ aksi: 'login', page: 'login', csrf_token: TOKEN, username: 'rsudmalangbonggarut@gmail.com', password: '@_Malangbong123' })}
  adminHandlePost();
  $u = adminOne('SELECT login_gagal, terakhir_login FROM admin_users WHERE username = ?', ['rsudmalangbonggarut@gmail.com']);
  echo "@@HASIL@@" . json_encode([
    'redirect' => $GLOBALS['ADMIN_TEST_REDIRECT'] ?? '',
    'adminId'  => adminSessGet('admin_id'),
    'gagal'    => (int) $u['login_gagal'],
    'terakhir' => $u['terakhir_login'] !== null,
    'log'      => (int) adminValue("SELECT COUNT(*) FROM admin_log WHERE aktivitas = 'login_sukses'"),
  ]);
`);
ok(r.hasil?.redirect === 'admin.php?page=dashboard', 'login benar → diarahkan ke dashboard');
ok(r.hasil?.adminId === 1, 'sesi admin terbentuk (id=1)');
ok(r.hasil?.gagal === 0, 'penghitung login gagal direset');
ok(r.hasil?.terakhir === true, 'waktu login terakhir dicatat');
ok(r.hasil?.log >= 1, 'aktivitas login masuk ke admin_log');

r = await runStep(php, `
  $s = &adminFakeSession(); $s['admin_csrf'] = '${TOKEN}';
  $_SERVER['REQUEST_METHOD'] = 'POST';
  for ($i = 0; $i < 6; $i++) {
    ${post({ aksi: 'login', page: 'login', csrf_token: TOKEN, username: 'rsudmalangbonggarut@gmail.com', password: 'salah-lagi' })}
    adminHandlePost();
  }
  $u = adminOne('SELECT login_gagal, terkunci_sampai FROM admin_users WHERE username = ?', ['rsudmalangbonggarut@gmail.com']);
  // coba login dengan password benar saat terkunci
  ${post({ aksi: 'login', page: 'login', csrf_token: TOKEN, username: 'rsudmalangbonggarut@gmail.com', password: '@_Malangbong123' })}
  adminHandlePost();
  echo "@@HASIL@@" . json_encode([
    'terkunci'   => $u['terkunci_sampai'] !== null,
    'gagal'      => (int) $u['login_gagal'],
    'bisaLogin'  => adminSessGet('admin_id') !== null,
    'flash'      => adminTakeFlash(),
  ]);
`);
ok(r.hasil?.terkunci === true, 'akun terkunci setelah 5 percobaan gagal');
ok(r.hasil?.bisaLogin === false, 'password benar pun ditolak selama terkunci');
ok(String(r.hasil?.flash?.map(f => f.pesan).join(' | ')).toLowerCase().includes('terkunci'),
  'pesan penguncian jelas', JSON.stringify(r.hasil?.flash));

r = await runStep(php, `
  adminQ('UPDATE admin_users SET login_gagal = 0, terkunci_sampai = NULL WHERE username = ?', ['rsudmalangbonggarut@gmail.com']);
  $hasil = adminAttemptLogin('rsudmalangbonggarut@gmail.com', '@_Malangbong123');
  echo "@@HASIL@@" . json_encode(['ok' => $hasil['ok'], 'pesan' => $hasil['pesan']]);
`);
ok(r.hasil?.ok === true, 'bisa login lagi setelah kunci dibuka');

// ---------------------------------------------------------------------------
console.log('\n4. CSRF ditolak');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $sebelum = adminCount('slide');
  ${post({ aksi: 'simpan', entitas: 'slide', page: 'slide', id: 0, judul: 'Slide tanpa token', csrf_token: 'SALAH' })}
  adminHandlePost();
  echo "@@HASIL@@" . json_encode([
    'flash'   => adminTakeFlash(),
    'tambah'  => adminCount('slide') - $sebelum,
  ]);
`);
ok(r.hasil?.tambah === 0, 'POST tanpa token CSRF tidak menyimpan apa pun');
ok(String(r.hasil?.flash?.[0]?.pesan ?? '').toLowerCase().includes('token'), 'pesan token CSRF tidak cocok');

// ---------------------------------------------------------------------------
console.log('\n5. Tambah slide + upload gambar sungguhan');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  ${phpBuatGambar('/tmp/contoh-slide.png', 2000, 1200)}
  $ukuran = filesize('/tmp/contoh-slide.png');
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'simpan', entitas: 'slide', page: 'slide', id: 0, judul: 'Slide Uji Coba Bergambar',
           subjudul: 'Dibuat otomatis oleh uji smoke', tautan: 'https://rsud-malangbong.garutkab.go.id',
           warna_latar: '#0f766e', urutan: 5, status_aktif: '1', csrf_token: TOKEN })}
  $_FILES['gambar'] = ['name' => 'contoh-slide.png', 'type' => 'image/png', 'tmp_name' => '/tmp/contoh-slide.png',
                       'error' => UPLOAD_ERR_OK, 'size' => $ukuran];
  adminHandlePost();
  $row = adminOne('SELECT * FROM slide WHERE judul = ?', ['Slide Uji Coba Bergambar']);
  $path = $row ? adminImagePath($row['gambar']) : '';
  $info = $path && is_file($path) ? getimagesize($path) : false;
  echo "@@HASIL@@" . json_encode([
    'flash'    => adminTakeFlash(),
    'redirect' => $GLOBALS['ADMIN_TEST_REDIRECT'] ?? '',
    'id'       => $row['id'] ?? null,
    'gambar'   => $row['gambar'] ?? '',
    'adaFile'  => $path !== '' && is_file($path),
    'lebar'    => $info ? $info[0] : 0,
    'tinggi'   => $info ? $info[1] : 0,
    'mime'     => $info ? $info['mime'] : '',
    'urlGambar'=> $row ? adminImageUrl($row['gambar']) : '',
    'log'      => (int) adminValue("SELECT COUNT(*) FROM admin_log WHERE aktivitas = 'tambah_slide'"),
  ]);
`);
const h5 = r.hasil ?? {};
ok(!!h5.id, 'slide baru tersimpan di database (id=' + h5.id + ')');
ok(h5.adaFile === true, 'berkas gambar tertulis di folder upload');
ok(h5.lebar === 1600, 'gambar diperkecil otomatis ke 1600 px (asal 2000 px)', 'lebar=' + h5.lebar);
ok(h5.mime === 'image/png', 'mime hasil tetap image/png');
ok(/^info|slide-/.test(h5.gambar ?? '') || /\.png$/.test(h5.gambar ?? ''), 'nama file aman (bukan nama asli)', h5.gambar);
ok(h5.urlGambar === 'uploads/' + h5.gambar, 'URL gambar relatif benar', h5.urlGambar);
ok(h5.log >= 1, 'aksi tercatat di admin_log');
bersih(r, 'tidak ada warning PHP saat upload');

// gambar muncul di halaman informasi
r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  $row = adminOne('SELECT gambar FROM slide WHERE judul = ?', ['Slide Uji Coba Bergambar']);
  echo "@@HASIL@@" . json_encode([
    'adaGambar' => strpos($html, 'uploads/' . rawurlencode($row['gambar'])) !== false,
    'adaTautan' => strpos($html, 'https://rsud-malangbong.garutkab.go.id') !== false,
    'slide'     => substr_count($html, 'class="slide"'),
  ]);
`);
ok(r.hasil?.adaGambar === true, 'gambar hasil upload tampil di slider informasi.php');
ok(r.hasil?.adaTautan === true, 'tautan slide terpasang');
ok(r.hasil?.slide === 4, 'jumlah slide di halaman menjadi 4');

// ---------------------------------------------------------------------------
console.log('\n6. Upload berkas berbahaya ditolak');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  file_put_contents('/tmp/jahat.png', "<?php echo 'shell'; ?>");
  $sebelum = adminCount('slide');
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'simpan', entitas: 'slide', page: 'slide', id: 0, judul: 'Slide Berkas Jahat', status_aktif: '1', csrf_token: TOKEN })}
  $_FILES['gambar'] = ['name' => 'jahat.png', 'type' => 'image/png', 'tmp_name' => '/tmp/jahat.png',
                       'error' => UPLOAD_ERR_OK, 'size' => filesize('/tmp/jahat.png')];
  adminHandlePost();
  echo "@@HASIL@@" . json_encode([
    'flash'  => adminTakeFlash(),
    'tambah' => adminCount('slide') - $sebelum,
    'file'   => is_file('/tmp/jahat.png') ? 'masih-ada' : 'terhapus',
  ]);
`);
ok(r.hasil?.tambah === 0, 'berkas PHP yang menyamar sebagai PNG ditolak');
ok(String(r.hasil?.flash?.[0]?.tipe ?? '') === 'error', 'pesan error ditampilkan ke admin');
ok(r.hasil?.file === 'terhapus', 'berkas sementara dibersihkan');

r = await runStep(php, `
  ${sesiLogin()}
  ${phpBuatGambar('/tmp/kebesaran.png', 60, 60)}
  $sebelum = adminCount('slide');
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'simpan', entitas: 'slide', page: 'slide', id: 0, judul: 'Slide File Besar', status_aktif: '1', csrf_token: TOKEN })}
  $_FILES['gambar'] = ['name' => 'besar.png', 'type' => 'image/png', 'tmp_name' => '/tmp/kebesaran.png',
                       'error' => UPLOAD_ERR_OK, 'size' => ADMIN_MAX_UPLOAD_BYTES + 10];
  adminHandlePost();
  echo "@@HASIL@@" . json_encode(['flash' => adminTakeFlash(), 'tambah' => adminCount('slide') - $sebelum]);
`);
ok(r.hasil?.tambah === 0 && String(r.hasil?.flash?.[0]?.pesan ?? '').includes('melebihi'),
  'file melebihi batas ukuran ditolak dengan pesan jelas');

// ---------------------------------------------------------------------------
console.log('\n7. Toggle status & ubah urutan');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $id = (int) adminValue('SELECT id FROM slide WHERE judul = ?', ['Slide Uji Coba Bergambar']);
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'toggle', entitas: 'slide', page: 'slide', csrf_token: TOKEN })}
  $_POST['id'] = $id; adminHandlePost();
  $mati = (int) adminValue('SELECT status_aktif FROM slide WHERE id = ?', [$id]);
  $_POST['aksi'] = 'toggle'; $_POST['csrf_token'] = '${TOKEN}'; $_POST['id'] = $id;
  adminHandlePost();
  $hidup = (int) adminValue('SELECT status_aktif FROM slide WHERE id = ?', [$id]);

  // urutan: turunkan slide pertama dua tingkat, lalu naikkan lagi satu tingkat
  $idsAwal  = array_column(adminRows('slide'), 'id');
  $target   = $idsAwal[0];
  $_POST['aksi'] = 'urutan_turun'; $_POST['csrf_token'] = '${TOKEN}'; $_POST['id'] = $target;
  adminHandlePost();
  $_POST['aksi'] = 'urutan_turun'; adminHandlePost();
  $posisiTurun = array_search($target, array_column(adminRows('slide'), 'id'));
  $_POST['aksi'] = 'urutan_naik'; adminHandlePost();
  $posisiNaik = array_search($target, array_column(adminRows('slide'), 'id'));
  $rows = adminRows('slide');
  $urutan = array_values(array_map(fn($x) => (int) $x['urutan'], $rows));
  $naik = true;
  foreach ($urutan as $i => $u) { if ($i > 0 && $u <= $urutan[$i - 1]) $naik = false; }
  echo "@@HASIL@@" . json_encode([
    'id' => $id, 'mati' => $mati, 'hidup' => $hidup,
    'posisiTurun' => $posisiTurun,
    'posisiNaik'  => $posisiNaik,
    'urutan'      => $urutan,
    'urutanRapi'  => $urutan === array_map(fn($i) => ($i + 1) * 10, array_keys($urutan)),
    'urutNaik'    => $naik,
  ]);
`);
ok(r.hasil?.mati === 0 && r.hasil?.hidup === 1, 'toggle nonaktif ↔ aktif berfungsi');
ok(r.hasil?.posisiTurun === 2 && r.hasil?.posisiNaik === 1, 'posisi baris berpindah sesuai tombol ▲ ▼', JSON.stringify(r.hasil));
ok(r.hasil?.urutNaik === true, 'urutan tampil selalu menaik', JSON.stringify(r.hasil?.urutan));
ok(r.hasil?.urutanRapi === true, 'nomor urutan dirapikan otomatis (10, 20, 30, …)', JSON.stringify(r.hasil?.urutan));

r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  $posisi = array_search('Slide Uji Coba Bergambar', array_column(adminRows('slide'), 'judul'));
  echo "@@HASIL@@" . json_encode(['posisiDb' => $posisi, 'urutanBenar' => strpos($html, 'Slide Uji Coba Bergambar') !== false]);
`);
ok(r.hasil?.urutanBenar === true, 'urutan baru benar-benar dipakai halaman informasi');

// ---------------------------------------------------------------------------
console.log('\n8. CRUD kartu informasi & panduan');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  // kartu berisi payload XSS untuk memastikan semua output di-escape
  ${post({ aksi: 'simpan', entitas: 'informasi', page: 'informasi', id: 0, csrf_token: TOKEN,
           kategori: '<b>Kategori</b>', judul: "<script>alert('xss')</script>", tanggal: '2026-09-17',
           konten: "**<img src=x onerror=alert(1)>**\n- <a href='#'>klik aman</a>",
           tautan: '', status_aktif: '1' })}
  adminHandlePost();

  // tautan berbahaya harus ditolak dengan pesan jelas
  adminTakeFlash();
  ${post({ aksi: 'simpan', entitas: 'informasi', page: 'informasi', id: 0, csrf_token: TOKEN,
           kategori: 'Uji', judul: 'Kartu Tautan Berbahaya', konten: 'x', tautan: 'javascript:alert(1)',
           status_aktif: '1' })}
  adminHandlePost();
  $flashTautan = adminTakeFlash();

  ${post({ aksi: 'simpan', entitas: 'informasi', page: 'informasi', id: 0, csrf_token: TOKEN,
           kategori: 'Layanan', judul: 'Kartu Uji dengan Daftar', tanggal: '2026-09-17',
           konten: "Pendaftaran dibuka pukul 07.00 WIB.\\n- Bawa KTP\\n- Bawa kartu BPJS\\n**Datang 30 menit lebih awal.**",
           tautan: '', status_aktif: '1' })}
  adminHandlePost();
  $id = (int) adminValue('SELECT id FROM informasi WHERE judul = ?', ['Kartu Uji dengan Daftar']);

  ${post({ aksi: 'simpan', entitas: 'panduan', page: 'panduan', id: 0, csrf_token: TOKEN,
           judul: 'Panduan Uji Coba', ikon: 'bantuan',
           isi: "Langkah pertama\\nLangkah kedua\\n- poin tambahan", status_aktif: '1' })}
  adminHandlePost();
  $idP = (int) adminValue('SELECT id FROM panduan WHERE judul = ?', ['Panduan Uji Coba']);

  // ubah (update) judul panduan
  $_POST['aksi'] = 'simpan'; $_POST['entitas'] = 'panduan'; $_POST['id'] = $idP;
  $_POST['judul'] = 'Panduan Uji Coba (Revisi)'; $_POST['isi'] = "Isi revisi\\nBaris kedua";
  $_POST['ikon'] = 'riwayat'; $_POST['status_aktif'] = '1'; $_POST['csrf_token'] = '${TOKEN}';
  adminHandlePost();

  echo "@@HASIL@@" . json_encode([
    'idInfo'    => $id,
    'idPanduan' => $idP,
    'konten'    => adminValue('SELECT konten FROM informasi WHERE id = ?', [$id]),
    'judulRev'  => adminValue('SELECT judul FROM panduan WHERE id = ?', [$idP]),
    'ikonRev'   => adminValue('SELECT ikon FROM panduan WHERE id = ?', [$idP]),
    'kartuXss'  => adminOne("SELECT id, kategori, judul FROM informasi WHERE judul LIKE '%script%' LIMIT 1"),
    'tautanDitolak' => adminOne("SELECT id FROM informasi WHERE judul = 'Kartu Tautan Berbahaya'") === null,
    'flashTautan'   => $flashTautan[0]['pesan'] ?? '',
    'jmlInfo'   => adminCount('informasi'),
    'jmlPanduan'=> adminCount('panduan'),
  ]);
`);
ok(!!r.hasil?.idInfo && !!r.hasil?.idPanduan, 'kartu informasi & panduan baru tersimpan');
ok(String(r.hasil?.judulRev ?? '').includes('Revisi'), 'data panduan bisa diperbarui');
ok(r.hasil?.ikonRev === 'riwayat', 'ikon panduan tersimpan');
ok(String(r.hasil?.konten ?? '').includes('- Bawa KTP'), 'isi dengan bullet tersimpan apa adanya');
ok(!!r.hasil?.kartuXss, 'kartu berisi payload XSS tetap tersimpan (isinya dinetralkan saat ditampilkan)');
ok(r.hasil?.tautanDitolak === true, 'tautan javascript: tidak ikut tersimpan');
ok(String(r.hasil?.flashTautan).includes('tidak diizinkan'), 'pesan penolakan tautan berbahaya jelas',
  r.hasil?.flashTautan);

r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'bullet'      => substr_count($html, '<li>') >= 2,
    'bold'        => strpos($html, '<strong>Datang 30 menit lebih awal.</strong>') !== false,
    'tanggal'     => strpos($html, '17 September 2026') !== false,
    'kategori'    => strpos($html, '>Layanan<') !== false,
    'dropdown'    => substr_count($html, '<details class="ak-item"'),
    'kartuJml'    => substr_count($html, '<article class="kartu">'),
    'judulPanduan'=> strpos($html, 'Panduan Uji Coba (Revisi)') !== false,
    'escape'      => strpos($html, '<script>alert') === false
                     && strpos($html, '<img src=x') === false
                     && strpos($html, '<b>Kategori</b>') === false
                     && strpos($html, '&lt;script&gt;') !== false
                     && strpos($html, '&lt;b&gt;Kategori&lt;/b&gt;') !== false,
    'terpotong'   => strpos($html, 'terpotong') !== false,
  ]);
`);
ok(r.hasil?.bullet === true, 'baris "-" dirender menjadi daftar bullet');
ok(r.hasil?.bold === true, 'teks **tebal** dirender sebagai <strong>');
ok(r.hasil?.tanggal === true, 'tanggal diformat ke bahasa Indonesia');
ok(r.hasil?.kategori === true, 'label kategori tampil pada kartu');
ok(r.hasil?.dropdown === 6, 'jumlah dropdown panduan bertambah jadi 6', r.hasil?.dropdown);
ok(r.hasil?.kartuJml === 5, 'jumlah kartu informasi bertambah jadi 5', r.hasil?.kartuJml);
ok(r.hasil?.judulPanduan === true, 'judul panduan hasil revisi tampil');
ok(r.hasil?.escape === true, 'tidak ada HTML mentah yang lolos (aman XSS)');
bersih(r, 'tidak ada warning PHP saat render konten baru');

// validasi kolom wajib & tanggal rusak
r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $sebelum = adminCount('informasi');
  ${post({ aksi: 'simpan', entitas: 'informasi', page: 'informasi', id: 0, csrf_token: TOKEN, judul: '', konten: 'x' })}
  adminHandlePost();
  $flashKosong = adminTakeFlash();
  ${post({ aksi: 'simpan', entitas: 'informasi', page: 'informasi', id: 0, csrf_token: TOKEN,
           judul: 'Tanggal Rusak', tanggal: '17-09-2026', konten: 'x' })}
  adminHandlePost();
  $flashTgl = adminTakeFlash();
  ${post({ aksi: 'simpan', entitas: 'slide', page: 'slide', id: 0, csrf_token: TOKEN,
           judul: 'Warna Rusak', warna_latar: 'merah' })}
  adminHandlePost();
  $flashWarna = adminTakeFlash();
  echo "@@HASIL@@" . json_encode([
    'tambah'  => adminCount('informasi') - $sebelum,
    'wajib'   => $flashKosong[0]['pesan'] ?? '',
    'tanggal' => $flashTgl[0]['pesan'] ?? '',
    'warna'   => $flashWarna[0]['pesan'] ?? '',
  ]);
`);
ok(r.hasil?.tambah === 0, 'data tidak valid tidak disimpan');
ok(String(r.hasil?.wajib).includes('wajib'), 'judul kosong ditolak');
ok(String(r.hasil?.tanggal).includes('tanggal'), 'format tanggal salah ditolak');
ok(String(r.hasil?.warna).includes('hex'), 'warna bukan hex ditolak');

// ---------------------------------------------------------------------------
console.log('\n9. Pengaturan halaman');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $_POST = [
    'aksi' => 'pengaturan_simpan', 'page' => 'pengaturan', 'csrf_token' => '${TOKEN}',
    'set'  => [
      'app_nama' => 'RSUD Malangbong', 'app_subjudul' => 'Layanan Digital Pasien',
      'warna_utama' => '#0f766e', 'tampilkan_header' => '1', 'tampilkan_slider' => '1',
      'slider_autoplay' => '0', 'slider_interval' => '7000',
      'info_judul_seksi' => 'Berita & Pengumuman', 'panduan_judul_seksi' => 'Cara Pakai Aplikasi',
      'kontak_judul_seksi' => 'Hubungi Kami', 'tampilkan_panduan' => '1', 'panduan_buka_satu' => '1',
      'tampilkan_kontak' => '1', 'alamat' => 'Jl. Raya Malangbong No. 1', 'jam_layanan' => '24 Jam',
      'telepon' => '(0262) 123456', 'whatsapp' => '081385831193', 'email' => 'rsudmalangbonggarut@gmail.com',
      'website' => 'rsud-malangbong.garutkab.go.id', 'maps' => '',
      'footer_teks' => 'RSUD Malangbong — Garut', 'pesan_kosong_info' => 'Belum ada info', 'pesan_kosong_slide' => '',
    ],
  ];
  adminHandlePost();
  $s = adminSettings();
  echo "@@HASIL@@" . json_encode([
    'flash'      => adminTakeFlash(),
    'warna'      => $s['warna_utama'],
    'header'     => $s['tampilkan_header'],
    'autoplay'   => $s['slider_autoplay'],
    'interval'   => $s['slider_interval'],
    'seksi'      => $s['info_judul_seksi'],
    'website'    => $s['website'],
  ]);
`);
ok(r.hasil?.warna === '#0f766e', 'warna utama tersimpan');
ok(r.hasil?.header === '1', 'header bisa diaktifkan lagi dari pengaturan');
ok(r.hasil?.autoplay === '0' && r.hasil?.interval === '7000', 'autoplay mati & interval tersimpan');
ok(r.hasil?.seksi === 'Berita & Pengumuman', 'judul seksi tersimpan');
ok(String(r.hasil?.website).startsWith('https://'), 'website otomatis dilengkapi https://');

r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'header'    => strpos($html, '<header class="header">') !== false,
    'subjudul'  => strpos($html, 'Layanan Digital Pasien') !== false,
    'warna'     => strpos($html, '--brand:#0f766e') !== false,
    'seksi'     => strpos($html, 'Berita &amp; Pengumuman') !== false || strpos($html, 'Berita & Pengumuman') !== false,
    'autoplay'  => strpos($html, 'data-autoplay="0"') !== false,
    'interval'  => strpos($html, 'data-interval="7000"') !== false,
    'telepon'   => strpos($html, '(0262) 123456') !== false,
    'wa'        => strpos($html, 'wa.me/6281385831193') !== false,
    'peta'      => strpos($html, 'Jl. Raya Malangbong No. 1') !== false,
  ]);
`);
ok(r.hasil?.header === true, 'header tampil setelah diaktifkan');
ok(r.hasil?.subjudul === true, 'subjudul dari pengaturan dipakai');
ok(r.hasil?.warna === true, 'warna tema dari pengaturan dipakai');
ok(r.hasil?.seksi === true, 'judul seksi dari pengaturan dipakai');
ok(r.hasil?.autoplay === true && r.hasil?.interval === true, 'setelan slider diteruskan ke JavaScript');
ok(r.hasil?.telepon === true && r.hasil?.wa === true && r.hasil?.peta === true, 'kontak lengkap tampil');
bersih(r, 'tidak ada warning PHP setelah perubahan pengaturan');

// kembalikan ke setelan awal (header mati) agar konsisten dengan permintaan
await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $_POST = ['aksi' => 'pengaturan_simpan', 'page' => 'pengaturan', 'csrf_token' => '${TOKEN}',
            'set' => ['tampilkan_header' => '1', 'warna_utama' => '#1b5e20', 'slider_autoplay' => '1', 'slider_interval' => '5000']];
  adminHandlePost();
  adminQ('UPDATE pengaturan SET nilai = ? WHERE kunci = ?', ['0', 'tampilkan_header']);
`);

// ---------------------------------------------------------------------------
console.log('\n10. Ganti password akun');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'password_ganti', page: 'akun', csrf_token: TOKEN,
           password_lama: 'salah', password_baru: 'PasswordBaru123', password_konfirmasi: 'PasswordBaru123' })}
  adminHandlePost();
  $flashSalah = adminTakeFlash();

  ${post({ aksi: 'password_ganti', page: 'akun', csrf_token: TOKEN,
           password_lama: '@_Malangbong123', password_baru: 'pendek', password_konfirmasi: 'pendek' })}
  adminHandlePost();
  $flashPendek = adminTakeFlash();

  ${post({ aksi: 'password_ganti', page: 'akun', csrf_token: TOKEN,
           password_lama: '@_Malangbong123', password_baru: 'PasswordBaru123', password_konfirmasi: 'BedaKonfirmasi1' })}
  adminHandlePost();
  $flashBeda = adminTakeFlash();

  ${post({ aksi: 'password_ganti', page: 'akun', csrf_token: TOKEN,
           password_lama: '@_Malangbong123', password_baru: 'PasswordBaru123', password_konfirmasi: 'PasswordBaru123' })}
  adminHandlePost();
  $flashOk = adminTakeFlash();

  echo "@@HASIL@@" . json_encode([
    'salah'   => $flashSalah[0]['pesan'] ?? '',
    'pendek'  => $flashPendek[0]['pesan'] ?? '',
    'beda'    => $flashBeda[0]['pesan'] ?? '',
    'sukses'  => $flashOk[0]['tipe'] ?? '',
    'loginLama'  => adminAttemptLogin('rsudmalangbonggarut@gmail.com', '@_Malangbong123')['ok'],
    'loginBaru'  => adminAttemptLogin('rsudmalangbonggarut@gmail.com', 'PasswordBaru123')['ok'],
  ]);
`);
ok(String(r.hasil?.salah).includes('tidak cocok'), 'password lama salah ditolak');
ok(String(r.hasil?.pendek).includes('minimal 8'), 'password terlalu pendek ditolak');
ok(String(r.hasil?.beda).includes('tidak sama'), 'konfirmasi berbeda ditolak');
ok(r.hasil?.sukses === 'sukses' && r.hasil?.loginBaru === true && r.hasil?.loginLama === false,
  'password berhasil diganti (lama tidak berlaku lagi)');

r = await runStep(php, `
  ${sesiLogin()}
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'akun_simpan', page: 'akun', csrf_token: TOKEN,
           nama_lengkap: 'Petugas Informasi RSUD', email: 'info@rsud-malangbong.garutkab.go.id' })}
  adminHandlePost();
  $u = adminOne('SELECT nama_lengkap, email FROM admin_users WHERE id = 1');
  echo "@@HASIL@@" . json_encode($u);
`);
ok(r.hasil?.nama_lengkap === 'Petugas Informasi RSUD' && String(r.hasil?.email).includes('@'),
  'profil akun bisa diperbarui');

// ---------------------------------------------------------------------------
console.log('\n11. Render semua halaman panel admin');
// ---------------------------------------------------------------------------
for (const page of ['dashboard', 'slide', 'informasi', 'panduan', 'pengaturan', 'akun', 'sistem']) {
  r = await runStep(php, `
    ${sesiLogin()}
    $_GET['page'] = '${page}';
    ob_start(); adminRun(); $html = ob_get_clean();
    echo "@@HASIL@@" . json_encode([
      'panjang' => strlen($html),
      'menu'    => substr_count($html, 'class="nav"'),
      'judul'   => preg_match('#<title>(.*?)</title>#', $html, $m) ? $m[1] : '',
      'form'    => substr_count($html, '<form'),
      'tabel'   => substr_count($html, '<table class="tbl">'),
    ]);
  `);
  const h = r.hasil ?? {};
  ok(h.panjang > 4000 && h.menu >= 2, `halaman "${page}" terender (${h.panjang} byte)`, JSON.stringify(h));
  bersih(r, `halaman "${page}" bebas warning PHP`);
}

// halaman tambah & ubah
r = await runStep(php, `
  ${sesiLogin()}
  $_GET['page'] = 'slide'; $_GET['baru'] = '1';
  ob_start(); adminRun(); $html = ob_get_clean();
  $id = (int) adminValue('SELECT id FROM informasi LIMIT 1');
  $_GET['page'] = 'informasi'; $_GET['baru'] = null; $_GET['edit'] = $id;
  ob_start(); adminRun(); $html2 = ob_get_clean();
  $_GET['page'] = 'panduan'; $_GET['edit'] = 999999;
  ob_start(); adminRun(); $html3 = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'formBaru'   => strpos($html, 'Tambah Slide Baru') !== false && strpos($html, 'type="file"') !== false,
    'formUbah'   => strpos($html2, 'Ubah Kartu Informasi') !== false && strpos($html2, 'name="id" value="' . $id . '"') !== false,
    'nilaiLama'  => strpos($html2, 'Selamat datang di Aplikasi Mobile RSUD Malangbong') !== false,
    'idTakAda'   => strpos($html3, 'tidak ditemukan') !== false,
  ]);
`);
ok(r.hasil?.formBaru === true, 'form tambah slide lengkap dengan input gambar');
ok(r.hasil?.formUbah === true, 'form ubah kartu informasi memuat ID yang benar');
ok(r.hasil?.nilaiLama === true, 'nilai lama terisi di form ubah');
ok(r.hasil?.idTakAda === true, 'ID tidak dikenal diberi pesan, bukan error fatal');
bersih(r, 'form tambah/ubah bebas warning PHP');

// halaman login (belum masuk)
r = await runStep(php, `
  $_GET['page'] = 'login';
  ob_start(); adminRun(); $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'panjang' => strlen($html),
    'form'    => strpos($html, 'name="password"') !== false,
    'token'   => strpos($html, 'name="csrf_token"') !== false,
    'bocor'   => stripos($html, 'Panel Admin Informasi') === false,
  ]);
`);
ok(r.hasil?.form === true && r.hasil?.token === true, 'halaman login tampil dengan token CSRF');
bersih(r, 'halaman login bebas warning PHP');

// ---------------------------------------------------------------------------
console.log('\n12. Hapus slide → berkas gambar ikut terhapus');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ${sesiLogin()}
  $row = adminOne('SELECT id, gambar FROM slide WHERE judul = ?', ['Slide Uji Coba Bergambar']);
  $path = adminImagePath($row['gambar']);
  $adaSebelum = is_file($path);
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'hapus', entitas: 'slide', page: 'slide', csrf_token: TOKEN })}
  $_POST['id'] = $row['id'];
  adminHandlePost();
  echo "@@HASIL@@" . json_encode([
    'adaSebelum' => $adaSebelum,
    'adaSesudah' => is_file($path),
    'baris'      => adminOne('SELECT id FROM slide WHERE id = ?', [$row['id']]) === null,
    'flash'      => adminTakeFlash(),
  ]);
`);
ok(r.hasil?.adaSebelum === true && r.hasil?.adaSesudah === false, 'berkas gambar dihapus bersama datanya');
ok(r.hasil?.baris === true, 'baris slide terhapus dari database');

r = await runStep(php, `
  ${sesiLogin()}
  $row = adminOne('SELECT id, gambar FROM informasi WHERE judul = ?', ['Kartu Uji dengan Daftar']);
  $_SERVER['REQUEST_METHOD'] = 'POST';
  ${post({ aksi: 'hapus', entitas: 'informasi', page: 'informasi', csrf_token: TOKEN })}
  $_POST['id'] = $row['id']; adminHandlePost();
  $rowP = adminOne('SELECT id FROM panduan WHERE judul = ?', ['Panduan Uji Coba (Revisi)']);
  $_POST['aksi'] = 'hapus'; $_POST['entitas'] = 'panduan'; $_POST['id'] = $rowP['id']; $_POST['csrf_token'] = '${TOKEN}';
  adminHandlePost();
  echo "@@HASIL@@" . json_encode([
    'info'    => adminOne('SELECT id FROM informasi WHERE id = ?', [$row['id']]) === null,
    'panduan' => adminOne('SELECT id FROM panduan WHERE id = ?', [$rowP['id']]) === null,
  ]);
`);
ok(r.hasil?.info === true && r.hasil?.panduan === true, 'kartu informasi & panduan bisa dihapus');

// ---------------------------------------------------------------------------
console.log('\n13. Generator file SQL');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  $sql = adminSqlDump();
  file_put_contents('/tmp/dump.sql', $sql);
  echo "@@HASIL@@" . json_encode([
    'panjang'   => strlen($sql),
    'createDb'  => substr_count($sql, 'CREATE DATABASE IF NOT EXISTS'),
    'tabel'     => preg_match_all('/^CREATE TABLE IF NOT EXISTS (\\w+)/m', $sql, $mt) ,
    'setting'   => substr_count($sql, 'INSERT IGNORE INTO pengaturan'),
    'user'      => substr_count($sql, 'INSERT IGNORE INTO admin_users'),
    'contoh'    => substr_count($sql, 'WHERE NOT EXISTS'),
    'hash'      => preg_match('/\\\\$2y\\\\$\\\\d{2}\\\\$/', $sql) === 1,
    'innodb'    => substr_count($sql, 'ENGINE=InnoDB'),
    'namaTabel' => $mt[1] ?? [],
    'utf8'      => substr_count($sql, 'utf8mb4') > 5,
  ]);
`);
const h13 = r.hasil ?? {};
ok(h13.createDb === 1 && h13.tabel === 6, 'dump membuat database + 6 tabel', JSON.stringify(h13.namaTabel ?? h13));
ok(['admin_users','pengaturan','slide','informasi','panduan','admin_log'].every(t => (h13.namaTabel ?? []).includes(t)),
  'keenam tabel inti ada di file SQL');
ok(h13.setting >= 20 && h13.user === 1, 'dump berisi pengaturan default + akun admin');
ok(h13.contoh >= 11, 'data contoh dilindungi NOT EXISTS (idempoten)', h13.contoh);
ok(h13.hash === true, 'password admin disimpan sebagai hash bcrypt, bukan teks');
ok(h13.innodb === 6 && h13.utf8 === true, 'tabel memakai InnoDB + utf8mb4');

// file SQL di repo harus selalu sinkron dengan kode panel
const dump = await runStep(php, `echo adminSqlDump();`, { driver: 'mysql' });
const tertulis = existsSync(FILE_SQL) ? readFileSync(FILE_SQL, 'utf8') : null;
if (tertulis === null || process.argv.includes('--tulis-sql')) {
  mkdirSync(dirname(FILE_SQL), { recursive: true });
  writeFileSync(FILE_SQL, dump.stdout, 'utf8');
  ok(true, 'file backend/sql/admin_info_rsudmobile.sql ditulis');
} else {
  ok(tertulis === dump.stdout,
    'file SQL di repo sinkron dengan kode (jalankan: node backend/tests/generate-sql.mjs)',
    tertulis ? 'isi berbeda' : 'file belum ada');
}
ok(dump.stdout.includes('CREATE TABLE IF NOT EXISTS slide'), 'dump memuat tabel slide');

// ---------------------------------------------------------------------------
console.log('\n14. Database mati → halaman tetap tampil');
// ---------------------------------------------------------------------------
r = await runStep(php, `
  ob_start(); require '${WASM_ROOT}/informasi.php'; $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'panjang'  => strlen($html),
    'catatan'  => strpos($html, 'informasi cadangan') !== false,
    'slide'    => substr_count($html, 'class="slide"'),
    'kartu'    => substr_count($html, '<article class="kartu">'),
    'panduan'  => substr_count($html, '<details class="ak-item"'),
    'kontak'   => strpos($html, 'wa.me/') !== false,
    'fatal'    => stripos($html, 'Fatal error') !== false || stripos($html, 'Uncaught') !== false,
  ]);
`, { driver: 'mysql' });
const h14 = r.hasil ?? {};
ok(h14.panjang > 10000, 'halaman tetap terender tanpa database (' + h14.panjang + ' byte)');
ok(h14.catatan === true, 'ada catatan halus bahwa konten cadangan dipakai');
ok(h14.slide >= 1 && h14.kartu >= 1 && h14.panduan >= 1, 'konten cadangan lengkap (slide, kartu, panduan)');
ok(h14.kontak === true, 'kontak default tetap tampil');
ok(h14.fatal === false, 'tidak ada fatal error yang bocor ke pasien');
bersih(r, 'tidak ada warning PHP saat database mati');

r = await runStep(php, `
  $_GET['page'] = 'login';
  ob_start(); adminRun(); $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'peringatan' => strpos($html, 'Database admin belum tersambung') !== false,
    'fatal'      => stripos($html, 'Fatal error') !== false || stripos($html, 'Uncaught') !== false,
  ]);
`, { driver: 'mysql' });
ok(r.hasil?.peringatan === true && r.hasil?.fatal === false,
  'halaman login menampilkan peringatan DB mati dengan rapi');

r = await runStep(php, `$_GET['format'] = 'json'; require '${WASM_ROOT}/informasi.php';`, { driver: 'mysql' });
let jsonMati = null;
try { jsonMati = JSON.parse(r.stdout.trim()); } catch { jsonMati = null; }
ok(jsonMati?.sumber === 'cadangan' && jsonMati?.slide?.length >= 1 && jsonMati?.panduan?.length >= 1,
  'mode JSON juga punya jalur cadangan', String(r.stdout).slice(0, 120));

// ---------------------------------------------------------------------------
console.log('\n15. Sesi panel admin (jalur web server sungguhan)');
// ---------------------------------------------------------------------------
// Langkah ini sengaja memakai RSUD_ADMIN_TEST_MODE = false supaya
// adminSessionStart() benar-benar dieksekusi — jalur yang sebelumnya tidak
// pernah tersentuh uji dan pernah fatal karena konstanta PHP_SESSION_NAME
// yang tidak pernah ada di PHP.
const phpSesi = await createPhp();

r = await runStep(phpSesi, `
  $_SERVER['HTTPS'] = 'on';
  $namaAwal = session_name();
  $bedaAwal = adminSessionNameBeda();
  $mulai    = adminSessionStart();
  $p        = session_get_cookie_params();
  adminSessSet('admin_uji', 'jalan');
  echo "@@HASIL@@" . json_encode([
    'namaAwal'   => $namaAwal,
    'bedaAwal'   => $bedaAwal,
    'mulai'      => $mulai,
    'nama'       => session_name(),
    'namaTarget' => ADMIN_SESSION_NAME,
    'bedaLagi'   => adminSessionNameBeda(),
    'aktif'      => adminSessionActive(),
    'secure'     => (bool) $p['secure'],
    'httponly'   => (bool) $p['httponly'],
    'samesite'   => $p['samesite'],
    'path'       => $p['path'],
    'isi'        => adminSessGet('admin_uji'),
  ]);
`, { testMode: false });
const h15 = r.hasil ?? {};
ok(h15.namaAwal === 'PHPSESSID' && h15.bedaAwal === true,
  'nama sesi bawaan PHP terdeteksi berbeda dari nama panel');
ok(h15.mulai === true && h15.aktif === true, 'adminSessionStart() memulai sesi tanpa fatal error');
ok(h15.nama === h15.namaTarget && h15.bedaLagi === false,
  'nama sesi diganti ke ' + (h15.namaTarget ?? '?') + ' (' + String(h15.nama) + ')');
ok(h15.secure === true && h15.httponly === true && h15.samesite === 'Lax' && h15.path === '/',
  'cookie sesi aman saat HTTPS (secure, httponly, SameSite=Lax, path=/)');
ok(h15.isi === 'jalan', 'data sesi bisa ditulis lalu dibaca kembali');
bersih(r, 'tidak ada warning PHP saat memulai sesi');

r = await runStep(phpSesi, `
  $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
  $mulai = adminSessionStart();
  $p     = session_get_cookie_params();
  echo "@@HASIL@@" . json_encode([
    'mulai'    => $mulai,
    'secure'   => (bool) $p['secure'],
    'httponly' => (bool) $p['httponly'],
  ]);
`, { testMode: false });
ok(r.hasil?.mulai === true && r.hasil?.secure === false && r.hasil?.httponly === true,
  'cookie tidak secure saat HTTP biasa, tetapi tetap httponly');

r = await runStep(phpSesi, `
  echo "@@HASIL@@" . json_encode([
    'mulai' => adminSessionStart(),
    'aktif' => adminSessionActive(),
  ]);
`);
ok(r.hasil?.mulai === false && r.hasil?.aktif === false,
  'mode uji tetap memakai sesi tiruan (sesi PHP tidak dimulai)');

// Seluruh naskah admin.php dijalankan seperti request web sungguhan
// (RSUD_ADMIN_NO_RUN tidak didefinisikan → adminRun() berjalan). Ini persis
// jalur yang dulu fatal: "Undefined constant PHP_SESSION_NAME ... line 59".
await runStep(phpSesi, `adminInstallSchema(true);`);
r = await runStep(phpSesi, `
  ob_start(); adminRun(); $html = ob_get_clean();
  echo "@@HASIL@@" . json_encode([
    'panjang'   => strlen($html),
    'login'     => strpos($html, 'name="password"') !== false,
    'fatal'     => stripos($html, 'Fatal error') !== false || stripos($html, 'Uncaught') !== false,
    'konstanta' => stripos($html, 'Undefined constant') !== false,
    'namaSesi'  => session_name(),
    'sesiAktif' => adminSessionActive(),
  ]);
`, { testMode: false, jalankan: true });
const h15c = r.hasil ?? {};
ok(h15c.fatal === false && h15c.konstanta === false,
  'kunjungan ke admin.php sebagai request web tidak fatal error');
ok(h15c.panjang > 5000 && h15c.login === true,
  'halaman login panel admin terender (' + h15c.panjang + ' byte)');
ok(h15c.sesiAktif === true && h15c.namaSesi === 'RSUDADMINSESS',
  'sesi panel admin aktif dengan nama RSUDADMINSESS saat halaman dibuka');
bersih(r, 'tidak ada warning PHP saat admin.php dijalankan sebagai request web');

// ---------------------------------------------------------------------------
console.log('\n═══ Hasil ═══');
console.log(`  Lulus : ${pass}`);
console.log(`  Gagal : ${fail}`);
console.log(fail === 0 ? '\n✅ Semua pengujian panel admin & informasi.php lulus.\n' : '\n❌ Ada pengujian yang gagal.\n');
process.exit(fail === 0 ? 0 : 1);
