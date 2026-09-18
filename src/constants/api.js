export const API_BASE = 'https://rsudmalangbong-altos-brainsphere-t110-f5.tail351109.ts.net/rsud-mobile/backend/api.php'; // PROD
//export const API_BASE = 'https://rsudmalangbong-altos-brainsphere-t110-f5.tail351109.ts.net/tm/rsud/api.php'; // PROD LAMA
//export const API_BASE = 'http://localhost/rsud-mobile/api.php'; // LOCAL DEV
// Verifikasi format NIK sebelum cek ke database (digunakan saat daftar pasien baru)
export const NIK_VERIFY_URL = 'https://api.glianalabs.com/v1/tools/verify-nik';
//export const API_BASE = 'https://rsudmalangbong-altos-brainsphere-t110-f5.tail351109.ts.net/deni/rsud-mobile/debug.php'; // DEBUG PROD
export const TOKEN_USER = 'Pasa Pirdaos, A.Md.A.K';
export const KD_PROFILE = 1;
export const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJwYXNhIiwic2Vzc2lvbklkIjoiN2FlODRkMWQtODE0Ny00Yzg2LTk3YmYtMjczZDQ4ZjhlNjZlIiwiZXhwIjoxNzgyMjc0NTk1fQ.s1y3_kHquIMFXLUrySNuyXWQarceI6VhAqUveszO9uhpnGoT_peADF4hdNAiZxhN7uycLVvuicgk_6XgkY3WOQ.MQ==';
// Parse tanggal dari string API. Untuk format "YYYY-MM-DD" dijadikan tanggal
// lokal (bukan UTC) supaya tanggal & nama hari tidak bergeser karena timezone.
export const parseDateLocal = (dateStr) => {
  if (!dateStr) return null;
  if (typeof dateStr === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d, 0, 0, 0, 0);
  }
  const d = new Date(dateStr);
  return Number.isNaN(d.getTime()) ? null : d;
};

// Format tanggal Indonesia lengkap dengan hari, contoh:
// "Rabu, 2 September 2026"
export const formatDate = (dateStr) => {
  const d = parseDateLocal(dateStr);
  if (!d) return '-';
  return d.toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
};

// Format jam saja (HH.mm) untuk ditampilkan terpisah dari tanggal.
// Jika string hanya berisi tanggal (tanpa jam), kembalikan null agar UI tidak
// menampilkan jam 00.00 yang menyesatkan.
export const formatTime = (dateStr) => {
  if (typeof dateStr === 'string' && !String(dateStr).includes(':')) return null;
  const d = parseDateLocal(dateStr);
  if (!d) return null;
  const time = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  return time ? time.replace(':', '.') : null;
};


// ══════════════════════════════════════════════════════════════════════════
// PENJAMIN / CARA BAYAR PENDAFTARAN
// ──────────────────────────────────────────────────────────────────────────
// Dua alur pendaftaran yang formulirnya identik, berbeda hanya penjaminnya:
//   • umum         → kelompok pasien UMUM, check-in menagih registrasi Rp 75.000
//   • asuransi_kai → kelompok pasien ASURANSI (KAI), check-in TANPA tagihan
// Kode ini dikirim apa adanya ke backend (action=daftar_online, field "penjamin")
// dan dikembalikan lagi pada riwayat/tiket/check-in.
// ══════════════════════════════════════════════════════════════════════════
export const PENJAMIN_UMUM = 'umum';
export const PENJAMIN_KAI = 'asuransi_kai';

export const BIAYA_REGISTRASI_UMUM = 75000;

export const PENJAMIN_LABEL = {
  [PENJAMIN_UMUM]: 'Umum',
  [PENJAMIN_KAI]: 'Asuransi (KAI)',
};

// Urutan tampilan di pemilih penjamin — UMUM selalu pertama & jadi default.
export const PENJAMIN_OPTIONS = [
  {
    kode: PENJAMIN_UMUM,
    label: 'Umum',
    icon: 'fa-wallet',
    singkat: 'UMUM',
    biaya_registrasi: BIAYA_REGISTRASI_UMUM,
    keterangan: 'Biaya registrasi Rp 75.000 ditagihkan saat check-in di loket admisi.',
  },
  {
    kode: PENJAMIN_KAI,
    label: 'Asuransi (KAI)',
    icon: 'fa-train',
    singkat: 'ASURANSI KAI',
    biaya_registrasi: 0,
    keterangan: 'Ditanggung asuransi KAI — tidak ada tagihan registrasi saat check-in.',
  },
];

export const penjaminLabel = (kode) => PENJAMIN_LABEL[kode] || PENJAMIN_LABEL[PENJAMIN_UMUM];

export const isPenjaminAsuransi = (kode) => kode === PENJAMIN_KAI;

// Format angka menjadi "Rp 75.000"
export const formatRupiah = (nilai) =>
  'Rp ' + Number(nilai || 0).toLocaleString('id-ID');
