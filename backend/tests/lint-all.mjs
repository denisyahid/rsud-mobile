// Lint sintaks semua file PHP backend memakai php-parser.
import phpParser from 'php-parser';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const parser = new phpParser({ parser: { extractDoc: true }, ast: { withPositions: true } });
const dir = new URL('..', import.meta.url).pathname;
const files = readdirSync(dir).filter(f => f.endsWith('.php')).sort();

let bad = 0;
for (const f of files) {
  const src = readFileSync(join(dir, f), 'utf8');
  try {
    const ast = parser.parseCode(src);
    console.log(`  ✅ ${f} — sintaks valid (${ast.children?.length} pernyataan top-level)`);
  } catch (e) {
    bad++;
    console.error(`  ❌ ${f} — ${e.message}`);
  }
}
console.log(bad === 0 ? `\n✅ Semua ${files.length} file PHP valid.` : `\n❌ ${bad} file bermasalah.`);
process.exit(bad === 0 ? 0 : 1);
