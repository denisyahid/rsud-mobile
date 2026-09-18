// ============================================================================
// generate-sql.mjs — membuat ulang backend/sql/admin_info_rsudmobile.sql
// langsung dari kode PHP produksi (fungsi adminSqlDump() di backend/admin.php),
// sehingga file SQL tidak pernah melenceng dari skema/nilai default di panel.
//
// Jalankan:  node backend/tests/generate-sql.mjs
// ============================================================================
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { createPhp, runStep, FILE_SQL } from './php-harness.mjs';

const php = await createPhp();
const r = await runStep(php, `echo adminSqlDump();`, { driver: 'mysql' });

if (r.exitCode !== 0 || !r.stdout.includes('CREATE TABLE IF NOT EXISTS')) {
  console.error('❌ Gagal membuat file SQL:', r.stdout.slice(0, 400), r.errors);
  process.exit(1);
}

mkdirSync(dirname(FILE_SQL), { recursive: true });
let lama = null;
try { lama = readFileSync(FILE_SQL, 'utf8'); } catch { lama = null; }

writeFileSync(FILE_SQL, r.stdout, 'utf8');

console.log(`✅ File SQL ditulis: ${FILE_SQL}`);
console.log(`   ${r.stdout.split('\n').length} baris • ${r.stdout.length} byte`);
console.log(lama === null ? '   (file baru)' : (lama === r.stdout ? '   (isi tidak berubah)' : '   (isi diperbarui)'));
process.exit(0);
