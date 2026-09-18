// ============================================================================
// php-harness.mjs — menjalankan PHP asli (WebAssembly) dari Node untuk menguji
// backend/admin.php, backend/admin-config.php, dan backend/informasi.php.
//
// Pengujian memakai driver SQLite sementara; seluruh pernyataan SQL, validasi,
// upload gambar, sesi, CSRF, dan rendering yang diuji adalah kode produksi yang
// sama persis (hanya driver PDO-nya yang berbeda).
// ============================================================================
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const BACKEND = join(__dirname, '..');

export const WASM_ROOT = '/app';                       // folder backend di FS WebAssembly
export const SQLITE_FILE = '/tmp/admin_test.sqlite';
export const UPLOAD_DIR = '/tmp/uploads_test';
export const FILE_SQL = join(BACKEND, 'sql', 'admin_info_rsudmobile.sql');

export const FILES = ['admin-config.php', 'admin.php', 'informasi.php'];

/**
 * Kode pembuka tiap langkah uji.
 * @param {object} o
 * @param {string} o.driver        'sqlite' (default) | 'mysql' (untuk menguji DB mati)
 * @param {string} o.sqliteFile
 * @param {string} o.uploadDir
 * @param {boolean} o.muatAdmin    require admin.php (default true)
 */
export function bootstrap(o = {}) {
  const driver = o.driver ?? 'sqlite';
  const sqliteFile = o.sqliteFile ?? SQLITE_FILE;
  const uploadDir = o.uploadDir ?? UPLOAD_DIR;
  return `<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
putenv('RSUD_ADMIN_DB_DRIVER=${driver}');
putenv('RSUD_ADMIN_SQLITE_FILE=${sqliteFile}');
putenv('RSUD_ADMIN_UPLOAD_DIR=${uploadDir}');
putenv('RSUD_ADMIN_DB_HOST=127.0.0.1');
putenv('RSUD_ADMIN_DB_PORT=3306');
define('RSUD_ADMIN_TEST_MODE', true);
define('RSUD_ADMIN_NO_RUN', true);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '${WASM_ROOT}/admin.php';
${o.muatAdmin === false ? '' : `require '${WASM_ROOT}/admin.php';`}
`;
}

export async function createPhp() {
  const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: process.pid } }));
  await php.mkdirTree(WASM_ROOT);
  await php.mkdirTree(UPLOAD_DIR);
  await php.mkdirTree('/tmp');
  for (const f of FILES) {
    php.writeFile(`${WASM_ROOT}/${f}`, readFileSync(join(BACKEND, f), 'utf8'));
  }
  return php;
}

/**
 * Jalankan satu langkah PHP.
 * Kode langkah boleh diakhiri `echo "@@HASIL@@" . json_encode([...]);`
 * supaya hasilnya bisa dibaca sebagai JSON di sisi Node.
 */
export async function runStep(php, code, opts = {}) {
  const full = bootstrap(opts) + '\n' + code;
  const res = await php.run({ code: full });
  const text = Buffer.from(Object.values(res.bytes ?? {})).toString('utf8');

  let hasil = null;
  const m = text.match(/@@HASIL@@([\s\S]*)$/);
  if (m) {
    try { hasil = JSON.parse(m[1].trim()); } catch { hasil = { parseError: m[1].slice(0, 400) }; }
  }
  return {
    stdout: text.replace(/@@HASIL@@[\s\S]*$/, ''),
    hasil,
    errors: res.errors || '',
    exitCode: res.exitCode,
    httpStatusCode: res.httpStatusCode,
  };
}

/** Pola yang menandakan bug PHP (warning/notice/fatal bocor ke output). */
export const PHP_NOISE = /(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|Undefined (variable|array key|index)|Trying to access)/;

/** Potongan PHP untuk membuat berkas gambar uji lewat GD. */
export const phpBuatGambar = (path, w = 640, h = 360, warna = [27, 94, 32]) => `
  (function(){
    $im = imagecreatetruecolor(${w}, ${h});
    $c  = imagecolorallocate($im, ${warna[0]}, ${warna[1]}, ${warna[2]});
    imagefilledrectangle($im, 0, 0, ${w}, ${h}, $c);
    $putih = imagecolorallocate($im, 255, 255, 255);
    imagefilledellipse($im, ${Math.floor(w / 2)}, ${Math.floor(h / 2)}, 120, 120, $putih);
    imagepng($im, '${path}', 6);
    imagedestroy($im);
  })();
`;
