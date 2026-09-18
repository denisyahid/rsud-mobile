// ============================================================================
// sql-lint.mjs — memeriksa sintaks MySQL file backend/sql/admin_info_rsudmobile.sql
// memakai node-sql-parser. Pernyataan yang tidak dikenal parser (mis. "SET NAMES")
// masuk daftar putih karena valid di MySQL/MariaDB tetapi belum didukung parser.
//
// Jalankan:  node backend/tests/sql-lint.mjs
// ============================================================================
import pkg from 'node-sql-parser';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const { Parser } = pkg;
const FILE = fileURLToPath(new URL('../sql/admin_info_rsudmobile.sql', import.meta.url));
const DAFTAR_PUTIH = [/^SET\s+NAMES/i, /^SET\s+FOREIGN_KEY_CHECKS/i, /^SET\s+time_zone/i, /^USE\s+/i];

const sql = readFileSync(FILE, 'utf8');
const parser = new Parser();

// Pecah per pernyataan: buang komentar baris, hormati kutip tunggal ('' = escape)
const tanpaKomentar = sql.split('\n').filter(l => !l.trim().startsWith('--')).join('\n');
const pernyataan = [];
let cur = '', dalamKutip = false;
for (let i = 0; i < tanpaKomentar.length; i++) {
  const c = tanpaKomentar[i];
  if (c === "'") {
    if (dalamKutip && tanpaKomentar[i + 1] === "'") { cur += "''"; i++; continue; }
    dalamKutip = !dalamKutip;
  }
  cur += c;
  if (c === ';' && !dalamKutip) { pernyataan.push(cur.trim()); cur = ''; }
}
if (cur.trim()) pernyataan.push(cur.trim());

let lulus = 0, putih = 0, gagal = 0;
for (const s of pernyataan) {
  if (DAFTAR_PUTIH.some(re => re.test(s))) { putih++; continue; }
  try {
    parser.astify(s.endsWith(';') ? s : `${s};`, { database: 'MySQL' });
    lulus++;
  } catch (e) {
    gagal++;
    console.error(`  ❌ ${s.slice(0, 110).replace(/\s+/g, ' ')}\n     → ${String(e.message).slice(0, 200)}`);
  }
}

console.log(`  Pernyataan : ${pernyataan.length}`);
console.log(`  Valid      : ${lulus}`);
console.log(`  Daftar putih (valid MySQL, tak didukung parser): ${putih}`);
console.log(`  Gagal      : ${gagal}`);
if (gagal === 0) console.log('\n✅ Sintaks MySQL file SQL admin valid.\n');
else console.log('\n❌ Ada pernyataan SQL yang tidak valid.\n');
process.exit(gagal === 0 ? 0 : 1);
