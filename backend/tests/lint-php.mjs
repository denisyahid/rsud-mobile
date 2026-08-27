import phpParser from 'php-parser';
import { readFileSync } from 'node:fs';

const file = new URL('../api.php', import.meta.url).pathname;
const src = readFileSync(file, 'utf8');

const parser = new phpParser({ parser: { extractDoc: true }, ast: { withPositions: true } });
try {
  const ast = parser.parseCode(src);
  console.log(`✅ backend/api.php syntax valid (AST top-level statements: ${ast.children?.length})`);
  process.exit(0);
} catch (e) {
  console.error('❌ PHP syntax error:', e.message);
  process.exit(1);
}
