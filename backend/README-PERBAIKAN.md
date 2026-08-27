# 🛠️ Perbaikan Case "Daftar Pasien" — RSUD Mobile

## Akar masalah (kenapa pendaftaran error / belum lengkap)

Frontend memanggil **16 action** API, tetapi `api.php` di repo hanya mengimplementasikan **9**
(satu di antaranya bahkan duplikat & tidak pernah dieksekusi). Akibatnya:

| Gejala di aplikasi | Penyebab |
|---|---|
| Pendaftaran **pasien terdaftar** selalu gagal ("NIK / No Identitas wajib diisi") | Frontend mengirim payload `{ruangan_id, dokter_id, tgl_kunjungan}` saja ke `daftar_online`, backend versi lama selalu menuntut NIK/nama/alamat |
| Login pakai NIK gagal (hanya No RM yang bisa) | Query login lama hanya mencocokkan `nocm` |
| Tab Riwayat tidak menunjukkan status Dibatalkan / Telah Check-in / dokter | `get_riwayat` lama tidak mengembalikan `status`, `is_checkin`, `jenis_rawat`, `namadokter` |
| Tab Jadwal kosong / error | `get_poliklinik_list`, `get_jadwal_dokter`, `get_dokter_by_jadwal` tidak ada |
| Tombol Lihat Bukti / Batalkan / Check-in tidak berfungsi | `get_ticket_detail`, `cancel_reservation`, `checkin` tidak ada di api.php (fungsi checkin ada di checkin.php tapi tidak pernah di-include) |
| Pendaftaran baru kadang gagal saat jam sibuk | Nomor antrian/No CM dibuat `MAX()+1` tanpa lock → race condition |
| Tanggal/kunjungan meleset di malam hari | Timezone PHP server (UTC) — `date('Y-m-d')` bukan WIB |
| Pendaftaran baru mati total saat layanan verifikasi NIK pihak ketiga down | Frontend mem-blokir bila API eksternal gagal dihubungi |

## Yang diperbaiki

### Backend — `backend/api.php` (tulis ulang, 1 file siap deploy)
- ✅ `daftar_online` kini **2 mode**: PASIEN BARU (payload lengkap) & PASIEN TERDAFTAR
  (cukup `ruangan_id` + `dokter_id` + `tgl_kunjungan`, identitas dari session)
- ✅ Login: **NIK atau No RM**
- ✅ Action baru: `get_poliklinik_list`, `get_jadwal_dokter`, `get_dokter_by_jadwal`,
  `check_active_booking`, `get_ticket_detail`, `checkin`, `cancel_reservation`
- ✅ `get_riwayat` mengembalikan `status` (Aktif/Selesai/**Dibatalkan**), `is_checkin`,
  `jenis_rawat` (Rawat Jalan/Inap via nama departemen), `namadokter`, `noantrian_full`
- ✅ Anti-race: `pg_advisory_xact_lock` untuk penomoran No CM & antrian
- ✅ Timezone `Asia/Jakarta` eksplisit
- ✅ Validasi kunjungan: tanggal tidak boleh mundur, maks. 30 hari ke depan, kuota dokter,
  anti double-booking, batal hanya sebelum check-in/dilayani
- ✅ CORS allowlist (bukan lagi memantulkan origin sembarangan), cookie `secure` otomatis
  mengikuti HTTPS, kredensial DB bisa lewat env (`RSUD_DB_HOST` dst.)
- ✅ Semua error selalu berbentuk JSON (try/catch global) — tidak lagi HTML fatal error

### Frontend — `src/components/TabDaftar.jsx`
- ✅ Verifikasi NIK eksternal (glianalabs) gagal dihubungi → **fallback validasi format
  lokal** (kode provinsi, tanggal lahir ter-encode, dll.) dengan peringatan kuning —
  pendaftaran tidak lagi mati total karena layanan pihak ketiga

## Cara deploy ke server

1. Salin **`backend/api.php`** ke lokasi api.php yang berjalan di server
   (path yang sama dengan `API_BASE` di `src/constants/api.js`).
2. Selesai — tidak ada dependensi/instalasi baru. (Opsional: set env
   `RSUD_DB_HOST/PORT/NAME/USER/PASS` bila ingin kredensial di luar file.)
3. Aplikasi Android yang sudah terpasang **langsung terbantu** (perbaikan murni di server).
   Untuk perbaikan fallback NIK di sisi aplikasi: `npm run android:sync` lalu build APK baru.

## Catatan tabel jadwal dokter

Nama tabel jadwal dideteksi otomatis dari kandidat:
`jadwaldokter_m`, `jadwal_dokter_m`, `jadwaldokter_t`, `jadwalpraktikdokter_m`, `jadwal_dokter`
dengan kolom `objectpegawaifk/objectdokterfk`, `objectruanganfk/ruanganfk`, `hari`
(angka 1-7 / 0-6 / nama hari — semua didukung), `jammulai`, `jamakhir`, `quota/kuota`.

Bila nama tabel di server berbeda → set env `RSUD_JADWAL_TABLE=nama_tabel`.
Cara cek cepat di server:
```sql
SELECT table_name FROM information_schema.tables WHERE table_name ILIKE '%jadwal%';
```

## Pengujian yang sudah dilakukan

- Sintaks PHP: lolos parser penuh (`backend/tests/lint-php.mjs`)
- **28/28 tes SQL lulus** terhadap PostgreSQL asli (`backend/tests/sql-smoke.mjs`):
  login NIK & No RM, jadwal + kuota, daftar pasien baru, tiket, riwayat,
  double-booking, batal, rebook pasien lama, check-in, double check-in terblokir,
  antrian yang dibatalkan tidak mengurangi kuota.
- Build frontend (`npm run build`) & oxlint: lulus.

## Masalah lama yang belum disentuh (disarankan menyusul)

- Login masih **tanpa password/OTP** — siapa pun yang tahu NIK/No RM seseorang bisa
  melihat hasil lab/radiologinya (data kesehatan — sensitif menurut UU PDP).
- `search_nik` masih terbuka tanpa login (enumerasi data pasien).
- Kredensial DB masih ada fallback plaintext di file (env sudah didukung, pindahkan).
- TOKEN SIMRS hardcoded di `src/constants/api.js` **sudah kedaluwarsa (24-06-2026)** —
  perbarui sebelum fitur unduh PDF hasil lab/radiologi dipakai.
- Polling 3 endpoint tiap 5 detik saat login (beban server & baterai).
