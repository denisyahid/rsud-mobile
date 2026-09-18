// ============================================================================
// lint-constants.mjs — pastikan tidak ada konstanta PHP yang tidak dikenal
// ----------------------------------------------------------------------------
// Kasus nyata yang memotivasi pemeriksa ini: admin.php memakai
// `PHP_SESSION_NAME` (konstanta itu tidak pernah ada di PHP) sehingga setiap
// kunjungan ke panel admin berakhir dengan
//   Fatal error: Uncaught Error: Undefined constant "PHP_SESSION_NAME"
// Bug semacam ini lolos dari lint sintaks dan dari uji asap karena barisnya
// hanya dieksekusi lewat web server (SAPI non-CLI, mode uji nonaktif).
//
// Cara kerja:
//   1. Semua konstanta (identifier HURUF_KAPITAL) di backend/*.php dikumpulkan
//      dari AST, kecuali nama fungsi, nama kelas, `instanceof`, dan konstanta
//      milik kelas (PDO::ATTR_ERRMODE).
//   2. Konstanta yang didefinisikan sendiri (define()/const/, termasuk yang
//      diuji lewat defined()) dianggap sah.
//   3. Sisanya dicocokkan dengan daftar konstanta PHP sungguhan yang diambil
//      dari runtime PHP WebAssembly (get_defined_constants()).
//
// Jalankan:  node backend/tests/lint-constants.mjs
// ============================================================================
import phpParser from 'php-parser';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { createPhp, runStep } from './php-harness.mjs';

const parser = new phpParser({ ast: { withPositions: true } });
const dir = new URL('..', import.meta.url).pathname;
const files = readdirSync(dir).filter((f) => f.endsWith('.php')).sort();

const POLA_KONSTANTA = /^[A-Z][A-Z0-9_]*$/;

/**
 * Konstanta milik ekstensi yang tidak ikut terbangun di PHP WebAssembly tetapi
 * sah dipakai di server produksi. Kosongkan lagi bila ekstensinya tersedia.
 */
const DIABAIKAN = new Set([]);

/** Node induk yang properti `what`-nya berisi nama fungsi/kelas, bukan konstanta. */
const INDUK_NAMA_BUKAN_KONSTANTA = new Set([
  'call', 'new', 'staticlookup', 'propertystaticlookup', 'classconstant',
  'usegroup', 'namespace', 'use', 'instanceof',
]);

const dipakai = new Map();        // NAMA_KONSTANTA -> Set('file:line')
const didefinisikan = new Set();  // NAMA_KONSTANTA

function telusuri(node, file, induk, kunci) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) {
    for (const n of node) telusuri(n, file, induk, kunci);
    return;
  }
  const jenis = node.kind;

  if (jenis === 'name' && POLA_KONSTANTA.test(node.name ?? '')) {
    const lewati = (kunci === 'what' && INDUK_NAMA_BUKAN_KONSTANTA.has(induk))
      || (kunci === 'right' && String(induk).startsWith('bin:')); // $obj instanceof Kelas
    if (!lewati) {
      if (!dipakai.has(node.name)) dipakai.set(node.name, new Set());
      dipakai.get(node.name).add(node.loc ? `${file}:${node.loc.start.line}` : file);
    }
  }

  // define('X', ...) / defined('X')
  if (jenis === 'call' && node.what?.kind === 'name'
    && ['define', 'defined'].includes(node.what.name)) {
    const arg = node.arguments?.[0];
    if (arg?.kind === 'string' || arg?.kind === 'encapsed') didefinisikan.add(String(arg.value));
  }

  // const X = ...;
  if (jenis === 'constant') {
    const nama = typeof node.name === 'string' ? node.name : node.name?.name;
    if (typeof nama === 'string') didefinisikan.add(nama);
  }

  for (const [k, v] of Object.entries(node)) {
    if (['loc', 'position', 'isDoubleQuote', 'unicode', 'curly', 'resolution'].includes(k)) continue;
    telusuri(v, file, jenis === 'bin' ? `bin:${node.type}` : jenis, k);
  }
}

console.log('\n═══ Lint konstanta PHP (AST + runtime PHP-WASM) ═══\n');

for (const f of files) {
  try {
    telusuri(parser.parseCode(readFileSync(join(dir, f), 'utf8')).children, f, null, null);
  } catch (e) {
    console.error(`  ❌ ${f} — gagal dibaca AST-nya: ${e.message}`);
    process.exit(1);
  }
}

const php = await createPhp();
const r = await runStep(php, 'echo "@@HASIL@@" . json_encode(array_keys(get_defined_constants()));');
const punyaPhp = new Set(r.hasil ?? []);
if (punyaPhp.size < 100) {
  console.error(`  ❌ daftar konstanta PHP tidak terbaca dari runtime (dapat ${punyaPhp.size}).`);
  process.exit(1);
}

const asing = [...dipakai.keys()]
  .filter((c) => !punyaPhp.has(c) && !didefinisikan.has(c) && !DIABAIKAN.has(c))
  .sort();

console.log(`  ${files.length} file PHP diperiksa, ${dipakai.size} konstanta dipakai,`
  + ` ${didefinisikan.size} didefinisikan sendiri, ${punyaPhp.size} dikenal PHP runtime.`);

if (asing.length === 0) {
  console.log(`\n✅ Semua konstanta yang dipakai backend/*.php benar-benar ada.\n`);
  process.exit(0);
}

console.error(`\n❌ ${asing.length} konstanta tidak dikenal (akan memicu "Undefined constant"):`);
for (const c of asing) console.error(`     ${c} → ${[...dipakai.get(c)].join(', ')}`);
console.error('   Perbaiki namanya, atau definisikan konstantanya lebih dulu.\n');
process.exit(1);
