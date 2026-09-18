// ============================================================================
// penjamin-smoke.mjs — uji logika PENJAMIN (Umum vs Asuransi KAI) di api.php
// ----------------------------------------------------------------------------
// api.php dimuat dalam mode RSUD_API_NO_RUN (tanpa koneksi PostgreSQL & tanpa
// penanganan request), lalu seluruh fungsi penjamin diuji terhadap SQLite —
// termasuk deteksi otomatis id kelompok "Asuransi/KAI" dari nama di master
// kelompokpasien_m, override lewat env, dan penentuan tagihan registrasi.
//
// Jalankan:  node backend/tests/penjamin-smoke.mjs
// ============================================================================
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const API_PHP = readFileSync(join(__dirname, '..', 'api.php'), 'utf8');

let pass = 0, fail = 0;
const ok = (cond, label, extra = '') => {
  if (cond) { pass++; console.log(`  ✅ ${label}`); }
  else { fail++; console.log(`  ❌ ${label}${extra ? ' — ' + extra : ''}`); }
};

/**
 * Jalankan satu skenario: membuat PDO SQLite berisi master kelompokpasien_m,
 * memuat api.php dalam mode uji, lalu menjalankan $kodeUji.
 */
async function skenario(nama, { kelompok = [], kolomNama = 'kelompokpasien', env = {}, kodeUji }) {
  const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: process.pid } }));
  await php.mkdirTree('/app');
  php.writeFile('/app/api.php', API_PHP);

  const envLines = Object.entries(env).map(([k, v]) => `putenv('${k}=${v}');`).join('\n');
  const nilai = kelompok.map(k => `(${k[0]}, '${String(k[1]).replace(/'/g, "''")}', true)`).join(', ');

  const code = `<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
${envLines}
define('RSUD_API_NO_RUN', true);
require '/app/api.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE kelompokpasien_m (id INTEGER PRIMARY KEY, ${kolomNama} TEXT, statusenabled INTEGER DEFAULT 1)");
${nilai ? `$pdo->exec("INSERT INTO kelompokpasien_m (id, ${kolomNama}, statusenabled) VALUES ${nilai}");` : ''}

$hasil = (function () use ($pdo) { ${kodeUji} })();
echo "@@HASIL@@" . json_encode($hasil);
`;
  const res = await php.run({ code });
  const text = Buffer.from(Object.values(res.bytes ?? {})).toString('utf8');
  const m = text.match(/@@HASIL@@([\s\S]*)$/);
  let hasil = null;
  if (m) { try { hasil = JSON.parse(m[1].trim()); } catch { hasil = { parseError: m[1].slice(0, 300) }; } }
  const bersih = text.replace(/@@HASIL@@[\s\S]*$/, '');
  if (bersih.trim() || String(res.errors ?? '').trim()) {
    console.log(`  ⚠ output tak terduga pada "${nama}":`, bersih.slice(0, 300), String(res.errors ?? '').slice(0, 200));
  }
  return hasil;
}

const MASTER_LENGKAP = [
  [1, 'Umum'],
  [2, 'BPJS Kesehatan'],
  [3, 'Asuransi'],
  [4, 'Asuransi PT KAI'],
  [5, 'Jamkesda'],
];

console.log('\n═══ Uji penjamin Umum vs Asuransi (KAI) — api.php ═══\n');

// ---------------------------------------------------------------------------
console.log('1. Normalisasi kode penjamin dari input aplikasi');
// ---------------------------------------------------------------------------
let h = await skenario('normalisasi', {
  kelompok: MASTER_LENGKAP,
  kodeUji: `
    $input = ['umum', 'UMUM', '', null, 'reguler', 'kai', 'KAI', 'asuransi', 'asuransi_kai',
              'Asuransi (KAI)', 'ASURANSI-KAI', 'pt kai', 'kereta api', 'asuransi swasta',
              'bpjs', 'jkn', 'sembarang'];
    $out = [];
    foreach ($input as $i) $out[$i === null ? 'null' : (string) $i] = penjaminKode($i);
    return [
      'map'   => $out,
      'label' => [penjaminLabel('umum'), penjaminLabel('asuransi_kai'), penjaminLabel('KAI')],
      'biaya' => ['umum' => penjaminBiayaRegistrasi('umum'), 'kai' => penjaminBiayaRegistrasi('kai')],
      'tanggung' => ['umum' => penjaminDitanggungAsuransi('umum'), 'kai' => penjaminDitanggungAsuransi('asuransi_kai')],
    ];
  `,
});
ok(h?.map?.['umum'] === 'umum' && h?.map?.['UMUM'] === 'umum', '"umum"/"UMUM" → umum');
ok(h?.map?.[''] === 'umum' && h?.map?.['null'] === 'umum', 'input kosong/null → umum (default)');
ok(h?.map?.['kai'] === 'asuransi_kai' && h?.map?.['KAI'] === 'asuransi_kai', '"kai"/"KAI" → asuransi_kai');
ok(h?.map?.['asuransi'] === 'asuransi_kai' && h?.map?.['asuransi_kai'] === 'asuransi_kai', '"asuransi"/"asuransi_kai" → asuransi_kai');
ok(h?.map?.['Asuransi (KAI)'] === 'asuransi_kai' && h?.map?.['ASURANSI-KAI'] === 'asuransi_kai',
  '"Asuransi (KAI)"/"ASURANSI-KAI" → asuransi_kai');
ok(h?.map?.['pt kai'] === 'asuransi_kai' && h?.map?.['kereta api'] === 'asuransi_kai', '"pt kai"/"kereta api" → asuransi_kai');
ok(h?.map?.['bpjs'] === 'umum' && h?.map?.['sembarang'] === 'umum', 'kode tak dikenal → umum (tidak pernah error)');
ok(h?.label?.[0] === 'Umum' && h?.label?.[1] === 'Asuransi (KAI)' && h?.label?.[2] === 'Asuransi (KAI)',
  'label penjamin benar', JSON.stringify(h?.label));
ok(h?.biaya?.umum === 75000 && h?.biaya?.kai === 0, 'biaya registrasi: umum 75.000 / KAI 0');
ok(h?.tanggung?.umum === false && h?.tanggung?.kai === true, 'penanda "ditanggung asuransi" benar');

// ---------------------------------------------------------------------------
console.log('\n2. Deteksi otomatis id kelompok pasien dari master SIMRS');
// ---------------------------------------------------------------------------
h = await skenario('deteksi', {
  kelompok: MASTER_LENGKAP,
  kodeUji: `
    $umum = resolvePenjamin($pdo, 'umum');
    $kai  = resolvePenjamin($pdo, 'asuransi_kai');
    return [
      'umum'  => $umum,
      'kai'   => $kai,
      'dariId' => [
        1 => penjaminDariKelompokId($pdo, 1),
        2 => penjaminDariKelompokId($pdo, 2),
        3 => penjaminDariKelompokId($pdo, 3),
        4 => penjaminDariKelompokId($pdo, 4),
        0 => penjaminDariKelompokId($pdo, 0),
      ],
      'master' => daftarPenjaminMaster($pdo),
    ];
  `,
});
ok(h?.umum?.kelompok_id === 1 && h?.umum?.kode === 'umum', 'penjamin Umum → kelompok id 1', JSON.stringify(h?.umum));
ok(h?.umum?.biaya_registrasi === 75000, 'Umum tetap menagih registrasi Rp 75.000');
ok(h?.kai?.kelompok_id === 4 && h?.kai?.tersedia === true,
  'Asuransi KAI terdeteksi → kelompok id 4 ("Asuransi PT KAI")', JSON.stringify(h?.kai));
ok(h?.kai?.biaya_registrasi === 0, 'KAI tidak punya tagihan registrasi (Rp 0)');
ok(h?.kai?.kelompok_nama === 'Asuransi PT KAI', 'nama kelompok asuransi terbaca', h?.kai?.kelompok_nama);
ok(h?.dariId?.['4']?.kode === 'asuransi_kai' && h?.dariId?.['3']?.kode === 'asuransi_kai',
  'id kelompok 4 & 3 dikenali sebagai asuransi (dipakai saat check-in)');
ok(h?.dariId?.['1']?.kode === 'umum' && h?.dariId?.['2']?.kode === 'umum' && h?.dariId?.['0']?.kode === 'umum',
  'id kelompok Umum/BPJS/kosong diperlakukan sebagai umum (tagihan tetap ada)');
ok(Array.isArray(h?.master) && h.master.length === 2 && h.master[0].default === true && h.master[1].default === false,
  'get_masters menyediakan 2 pilihan penjamin dengan default = Umum');
ok(h?.master?.[1]?.tersedia === true && String(h.master[1].keterangan).includes('tidak ada tagihan'),
  'keterangan penjamin KAI menjelaskan bebas tagihan');

// ---------------------------------------------------------------------------
console.log('\n3. Master hanya punya "Asuransi" (tanpa kata KAI)');
// ---------------------------------------------------------------------------
h = await skenario('asuransi-saja', {
  kelompok: [[1, 'Umum'], [2, 'BPJS'], [7, 'Asuransi']],
  kodeUji: `return resolvePenjamin($pdo, 'kai');`,
});
ok(h?.kelompok_id === 7 && h?.tersedia === true, 'kelompok "Asuransi" (id 7) dipakai untuk KAI', JSON.stringify(h));

// ---------------------------------------------------------------------------
console.log('\n4. Master tidak punya kelompok asuransi sama sekali');
// ---------------------------------------------------------------------------
h = await skenario('tanpa-asuransi', {
  kelompok: [[1, 'Umum'], [2, 'BPJS Kesehatan']],
  kodeUji: `
    return [
      'kai'  => resolvePenjamin($pdo, 'asuransi_kai'),
      'umum' => resolvePenjamin($pdo, 'umum'),
      'dariId' => penjaminDariKelompokId($pdo, 1),
    ];
  `,
});
ok(h?.kai?.tersedia === false && h?.kai?.kelompok_id === null, 'KAI ditandai belum tersedia (tidak menebak id)');
ok(String(h?.kai?.pesan).includes('RSUD_KELOMPOK_PASIEN_ASURANSI_KAI'),
  'pesan error menjelaskan cara mengonfigurasi', h?.kai?.pesan);
ok(h?.umum?.kelompok_id === 1 && h?.umum?.tersedia === true, 'alur Umum tetap jalan normal');
ok(h?.dariId?.kode === 'umum', 'registrasi lama tetap dianggap umum');

// ---------------------------------------------------------------------------
console.log('\n5. Tabel kelompokpasien_m tidak ada (versi SIMRS berbeda)');
// ---------------------------------------------------------------------------
h = await skenario('tanpa-tabel', {
  kelompok: [],
  kodeUji: `
    $pdo->exec('DROP TABLE kelompokpasien_m');
    return [
      'umum' => resolvePenjamin($pdo, 'umum'),
      'kai'  => resolvePenjamin($pdo, 'asuransi_kai'),
      'rows' => kelompokPasienRows($pdo),
    ];
  `,
});
ok(h?.umum?.kelompok_id === 1 && h?.umum?.tersedia === true, 'Umum memakai id default 1 walau master tak terbaca');
ok(Array.isArray(h?.rows) && h.rows.length === 0, 'pembacaan master gagal dengan aman (tanpa fatal error)');
ok(h?.kai?.tersedia === false, 'KAI ditandai belum tersedia bila master tak ada');

// ---------------------------------------------------------------------------
console.log('\n6. Override lewat environment variable');
// ---------------------------------------------------------------------------
h = await skenario('env-override', {
  kelompok: [[1, 'Umum'], [2, 'BPJS'], [9, 'Karyawan Penjaminan Khusus']],
  env: { RSUD_KELOMPOK_PASIEN_ASURANSI_KAI: '9', RSUD_KELOMPOK_PASIEN_UMUM: '1', RSUD_REKANAN_ASURANSI_KAI: '42' },
  kodeUji: `
    $k = resolvePenjamin($pdo, 'kai');
    return ['kai' => $k, 'dariId9' => penjaminDariKelompokId($pdo, 9), 'cfg' => penjaminKonfigurasi()];
  `,
});
ok(h?.kai?.kelompok_id === 9 && h?.kai?.tersedia === true, 'env RSUD_KELOMPOK_PASIEN_ASURANSI_KAI dihormati', JSON.stringify(h?.kai));
ok(h?.kai?.rekanan_id === 42, 'env RSUD_REKANAN_ASURANSI_KAI dipakai untuk objectrekananfk');
ok(h?.dariId9?.kode === 'asuransi_kai', 'check-in mengenali kelompok dari env sebagai KAI (tanpa tagihan)');

// ---------------------------------------------------------------------------
console.log('\n7. Nama kolom master berbeda (namakelompokpasien)');
// ---------------------------------------------------------------------------
h = await skenario('kolom-beda', {
  kelompok: [[1, 'UMUM'], [6, 'ASURANSI KAI']],
  kolomNama: 'namakelompokpasien',
  kodeUji: `return ['kai' => resolvePenjamin($pdo, 'kai'), 'umum' => resolvePenjamin($pdo, 'umum')];`,
});
ok(h?.kai?.kelompok_id === 6, 'kolom namakelompokpasien tetap terdeteksi (id 6)', JSON.stringify(h?.kai));
ok(h?.umum?.kelompok_id === 1, 'kelompok UMUM terdeteksi dari nama');

// ---------------------------------------------------------------------------
console.log('\n8. Kelompok nonaktif tidak dipilih');
// ---------------------------------------------------------------------------
h = await skenario('nonaktif', {
  kelompok: [[1, 'Umum'], [2, 'BPJS'], [8, 'Asuransi KAI']],
  kodeUji: `
    $pdo->exec('UPDATE kelompokpasien_m SET statusenabled = 0 WHERE id = 8');
    return resolvePenjamin($pdo, 'kai');
  `,
});
ok(h?.tersedia === false, 'kelompok asuransi yang dinonaktifkan tidak dipakai');

console.log('\n═══ Hasil ═══');
console.log(`  Lulus : ${pass}`);
console.log(`  Gagal : ${fail}`);
console.log(fail === 0 ? '\n✅ Semua pengujian logika penjamin lulus.\n' : '\n❌ Ada pengujian yang gagal.\n');
process.exit(fail === 0 ? 0 : 1);
