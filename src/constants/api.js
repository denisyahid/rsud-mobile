export const API_BASE = 'https://rsudmalangbong-altos-brainsphere-t110-f5.tail351109.ts.net/tm/rsud/api.php'; // PROD
//export const API_BASE = 'http://localhost/rsud-mobile/api.php'; // LOCAL DEV
// Verifikasi format NIK sebelum cek ke database (digunakan saat daftar pasien baru)
export const NIK_VERIFY_URL = 'https://api.glianalabs.com/v1/tools/verify-nik';
//export const API_BASE = 'https://rsudmalangbong-altos-brainsphere-t110-f5.tail351109.ts.net/deni/rsud-mobile/debug.php'; // DEBUG PROD
export const TOKEN_USER = 'Pasa Pirdaos, A.Md.A.K';
export const KD_PROFILE = 1;
export const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJwYXNhIiwic2Vzc2lvbklkIjoiN2FlODRkMWQtODE0Ny00Yzg2LTk3YmYtMjczZDQ4ZjhlNjZlIiwiZXhwIjoxNzgyMjc0NTk1fQ.s1y3_kHquIMFXLUrySNuyXWQarceI6VhAqUveszO9uhpnGoT_peADF4hdNAiZxhN7uycLVvuicgk_6XgkY3WOQ.MQ==';
export const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

