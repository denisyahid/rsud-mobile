import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import { readFileSync } from 'node:fs';

const file = new URL('../api.php', import.meta.url).pathname;
const src = readFileSync(file, 'utf8');

const php = new PHP(await loadNodeRuntime('8.3'));
php.writeFile('/api.php', src);

// Sediakan stub minimal $_SERVER agar jalur CLI aman
const runner = await php.runStream({
  code: `<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_REQUEST['action'] = '';
include '/api.php';
`,
});

const out = await runner.stdoutText;
const errs = await runner.stderrText;
console.log('STDOUT:', String(out).slice(0, 400));
console.log('STDERR:', String(errs).slice(0, 600));

if (/Parse error/i.test(String(errs) + String(out))) {
  console.error('❌ PARSE ERROR');
  process.exit(1);
}
if (/Koneksi database gagal/.test(String(out))) {
  console.log('✅ api.php ter-parse penuh & jalur error DB bekerja');
  process.exit(0);
}
process.exit(2);
