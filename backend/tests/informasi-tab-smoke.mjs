// ============================================================================
// informasi-tab-smoke.mjs — uji perilaku navigasi tab di backend/informasi.php
// ----------------------------------------------------------------------------
// Halaman informasi.php dirender lebih dulu memakai PHP WebAssembly (kode
// produksi yang sama, database SQLite sementara), lalu HTML-nya dijalankan di
// jsdom untuk menguji perilaku yang tidak terlihat dari HTML statis:
//
//   1  tab pertama (Tarif Layanan) aktif saat halaman dibuka
//   2  klik tab memindah panel TANPA memuat ulang halaman
//   3  ARIA (aria-selected) dan hash URL ikut berpindah
//   4  navigasi papan ketik (panah kiri/kanan, Home/End)
//   5  tab terakhir diingat (localStorage) & bisa dibuka lewat #hash
//   6  pencarian tarif menyaring baris + kelompok, dan memunculkan pesan kosong
//
// Jalankan:  node backend/tests/informasi-tab-smoke.mjs
// ============================================================================
import { JSDOM } from 'jsdom';
import { createPhp, runStep, WASM_ROOT, SQLITE_FILE } from './php-harness.mjs';

let lulus = 0, gagal = 0;
const ok = (syarat, label, extra = '') => {
  if (syarat) { lulus++; console.log(`  ✅ ${label}`); }
  else { gagal++; console.log(`  ❌ ${label}${extra ? ' — ' + extra : ''}`); }
};

console.log('\n═══ Uji tab halaman informasi.php (PHP-WASM + jsdom) ═══\n');

// --- render halaman pakai kode PHP produksi -------------------------------
const php = await createPhp();
await runStep(php, `if (file_exists('${SQLITE_FILE}')) unlink('${SQLITE_FILE}'); adminInstallSchema(true);`);
const render = await runStep(php, `ob_start(); require '${WASM_ROOT}/informasi.php'; echo ob_get_clean();`);
const html = render.stdout;
ok(html.length > 20000 && !/Fatal error|Uncaught/i.test(html),
  `halaman informasi.php terender (${html.length} byte)`);

const buka = (url = 'http://server/informasi.php') =>
  new JSDOM(html, { runScripts: 'dangerously', url }).window;

const window = buka();
const { document } = window;
const panel = (id) => document.getElementById('panel-' + id);
const tab = (id) => document.getElementById('tab-' + id);

// --- 1. keadaan awal -------------------------------------------------------
ok(tab('tarif') && tab('panduan') && tab('kontak'), 'tiga tab tersedia (tarif, panduan, kontak)');
ok(tab('tarif').getAttribute('aria-selected') === 'true', 'tab Tarif Layanan aktif saat halaman dibuka');
ok(panel('tarif').hidden === false, 'panel tarif terlihat');
ok(panel('panduan').hidden === true && panel('kontak').hidden === true, 'panel lain tersembunyi');
ok(window.location.hash === '#tarif', 'hash URL diset ke #tarif', window.location.hash);

// --- 2-3. pindah tab tanpa reload -----------------------------------------
tab('panduan').click();
ok(panel('panduan').hidden === false, 'klik tab Panduan menampilkan panelnya');
ok(panel('tarif').hidden === true, 'panel tarif tersembunyi setelah pindah tab');
ok(tab('panduan').getAttribute('aria-selected') === 'true'
  && tab('tarif').getAttribute('aria-selected') === 'false', 'aria-selected ikut berpindah');
ok(window.location.hash === '#panduan', 'hash URL jadi #panduan', window.location.hash);

tab('kontak').click();
ok(panel('kontak').hidden === false && panel('panduan').hidden === true, 'klik tab Kontak menampilkan panelnya');
ok(document.querySelectorAll('#panel-kontak .kontak .row').length >= 4, 'isi kontak utuh di dalam panelnya');

// --- 4. papan ketik --------------------------------------------------------
tab('kontak').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
ok(panel('panduan').hidden === false, 'panah kiri pindah ke tab sebelumnya');
tab('panduan').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
ok(panel('kontak').hidden === false, 'panah kanan pindah ke tab berikutnya');
tab('kontak').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
ok(panel('tarif').hidden === false, 'tombol Home pindah ke tab pertama');
tab('tarif').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
ok(panel('kontak').hidden === false, 'tombol End pindah ke tab terakhir');

// --- 5. tab terakhir diingat + bisa dibuka lewat hash ----------------------
ok(window.localStorage.getItem('rsudTabInformasi') === 'kontak',
  'tab terakhir disimpan di localStorage', String(window.localStorage.getItem('rsudTabInformasi')));
ok(buka('http://server/informasi.php#panduan').document.getElementById('panel-panduan').hidden === false,
  'tautan informasi.php#panduan langsung membuka tab Panduan');

// --- 6. pencarian tarif ----------------------------------------------------
const cari = document.getElementById('cariTarif');
tab('tarif').click();
ok(!!cari, 'kotak pencarian tarif tersedia');

const cariIsi = (teks) => {
  cari.value = teks;
  cari.dispatchEvent(new window.Event('input', { bubbles: true }));
  return {
    baris: [...document.querySelectorAll('.tarif-item')].filter((x) => !x.hidden).length,
    grup:  [...document.querySelectorAll('.tarif-grup')].filter((g) => !g.hidden).length,
  };
};

const satu = cariIsi('radiologi');
ok(satu.baris === 1 && satu.grup === 1, 'cari "radiologi" menyaring jadi 1 baris dalam 1 kelompok',
  `${satu.baris}/${satu.grup}`);
const kosong = cariIsi('zzz-tidak-ada');
ok(kosong.baris === 0 && document.getElementById('tarifKosong').hidden === false,
  'kata kunci tanpa hasil memunculkan pesan "tidak ada yang cocok"');
const semua = cariIsi('');
ok(semua.baris === 10 && document.getElementById('tarifKosong').hidden === true,
  'mengosongkan pencarian menampilkan semua tarif lagi', String(semua.baris));

console.log(`\n  Lulus : ${lulus}`);
console.log(`  Gagal : ${gagal}`);
console.log(gagal === 0 ? '\n✅ Semua perilaku tab halaman informasi lulus.\n' : '\n❌ Ada perilaku tab yang gagal.\n');
process.exit(gagal === 0 ? 0 : 1);
