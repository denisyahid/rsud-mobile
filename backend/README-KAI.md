# Pendaftaran Pasien KAI / Asuransi — RSUD Malangbong Mobile

Dokumen ini menjelaskan fitur **"Daftar Pasien KAI"**: alur pendaftaran online yang
**sama persis** dengan "Daftar Pasien Umum", tetapi penjaminnya **ASURANSI (KAI)** dan
**tidak ditagih biaya registrasi Rp 75.000 saat check-in**.

> Tanggal: 2026-09-18 · Berkas terkait: `backend/api.php`, `backend/checkin.php`,
> `backend/barcode.php`, `src/components/Login.jsx`, `src/components/TabDaftar.jsx`,
> `src/components/TabRiwayat.jsx`, `src/constants/api.js`, `src/index.css`

---

## 1. Ringkasan perilaku

| Tahap | Penjamin **UMUM** (default) | Penjamin **ASURANSI (KAI)** |
|---|---|---|
| Formulir identitas pasien | sama | **sama persis** (tidak ada form kedua) |
| Pilih poli / dokter / tanggal | sama | sama |
| `pasiendaftar_t.objectkelompokpasienlastfk` | id kelompok **Umum** (default `1`) | id kelompok **Asuransi/KAI** (dicari otomatis) |
| `pasiendaftar_t.objectrekananfk` | `REKANAN_DEFAULT` (0) | `RSUD_REKANAN_ASURANSI_KAI` (default 0) |
| Check-in scan QR di loket | jalan | **jalan, sama persis** |
| Nomor antrian + `ischeckin` | dibuat/diset | dibuat/diset |
| `strukpelayanan_t` (tagihan Rp 75.000) | **dibuat** | **TIDAK dibuat** |
| `pelayananpasien_t` (tindakan BIAYA REGISTRASI) | **dibuat** | **TIDAK dibuat** |
| Pesan sukses check-in | "Tagihan registrasi Rp 75.000 telah ditambahkan." | "Biaya registrasi ditanggung Asuransi (KAI) — tidak ada tagihan." |

Yang **tidak berubah sama sekali**: validasi NIK, pencarian desa, kuota dokter,
pencegahan double booking, pembatalan reservasi, format QR
(`CHECKIN-RSUD-MALANGBONG-YYYYMMDD`), dan seluruh data lain pada registrasi lama
(registrasi lama otomatis dianggap **Umum** sehingga tetap ditagih seperti sebelumnya).

---

## 2. Tampilan aplikasi

1. **Layar login** — tombol baru `Daftar Online Pasien KAI (Asuransi)` terletak
   **di bawah** tombol "Daftar Online Pasien Umum" dan **di atas** tombol
   "Panduan Pemakaian" (`.login-kai` di `src/index.css`). Panduan pemakaian
   mendapat langkah tambahan "2b" tentang alur KAI.
2. **Formulir pendaftaran** — satu form untuk kedua penjamin. Di blok
   *Rencana Kunjungan* ada pemilih **Penjamin / Cara Bayar** dengan dua pilihan:
   * **Umum** — *default*, "Registrasi Rp 75.000 saat check-in"
   * **Asuransi (KAI)** — "Tanpa tagihan registrasi saat check-in"

   Bila penjamin Asuransi dipilih, muncul field opsional
   **Nomor Kepesertaan Asuransi / KAI** (disimpan ke `pasien_m.noasuransilain`
   hanya bila kolom itu ada di SIMRS). Badge header, teks tombol submit, dan
   bukti pendaftaran (tiket) mengikuti penjamin yang dipilih.
3. **Riwayat & tiket** — tiap kartu/bukti menampilkan penjamin dan biaya registrasi;
   modal check-in menampilkan peringatan yang sesuai (kuning untuk Umum,
   biru "tanpa tagihan" untuk Asuransi/KAI).

---

## 3. Kontrak API

### 3.1 Permintaan `POST api.php?action=daftar_online`

Field baru (opsional — bila tidak dikirim dianggap **Umum**, jadi aplikasi lama tetap jalan):

```jsonc
{
  "penjamin": "asuransi_kai",      // alias yang diterima: penjamin | cara_bayar |
                                   // jenis_pembayaran | pembayaran | kelompok_pasien
                                   // nilai dikenali: kai, KAI, asuransi, asuransi_kai,
                                   // pt kai, kereta api, asuransi swasta, ... (lainnya → umum)
  "nomor_asuransi": "0123456789"   // opsional, hanya dipakai untuk penjamin asuransi
  // ...field lama (nik, namapasien, ruangan_id, dokter_id, tgl_kunjungan, dst.)
}
```

Bila kelompok asuransi tidak ditemukan di SIMRS, API menolak dengan `400`:

```json
{ "error": "Penjamin Asuransi (KAI) belum tersedia di master SIMRS (tabel kelompokpasien_m ...). Minta administrator menambahkan kelompoknya atau set RSUD_KELOMPOK_PASIEN_ASURANSI_KAI=<id>." }
```

### 3.2 Respons

`daftar_online`, `get_riwayat`, `get_ticket_detail`, dan `checkin` kini menyertakan:

```jsonc
{
  "penjamin": "asuransi_kai",       // atau "umum"
  "penjamin_label": "Asuransi (KAI)",
  "kelompok_pasien": "Asuransi PT KAI",
  "biaya_registrasi": 0,            // 75000 untuk umum
  "ditanggung_asuransi": true
}
```

`get_masters` menambah daftar pilihan penjamin untuk dropdown (default = Umum):

```jsonc
{
  "penjamin": [
    { "kode": "umum", "label": "Umum", "kelompok_id": 1, "biaya_registrasi": 75000,
      "keterangan": "Biaya registrasi Rp 75.000 dibayar saat check-in.",
      "tersedia": true, "default": true },
    { "kode": "asuransi_kai", "label": "Asuransi (KAI)", "kelompok_id": 4,
      "kelompok_nama": "Asuransi PT KAI", "biaya_registrasi": 0,
      "keterangan": "Ditanggung asuransi — tidak ada tagihan registrasi saat check-in.",
      "tersedia": true, "default": false }
  ],
  "penjamin_default": "umum"
}
```

---

## 4. Konfigurasi id kelompok pasien ASURANSI

Id kelompok **dicari otomatis** dari `kelompokpasien_m` dengan pencocokan nama.
Untuk melihat kandidat di SIMRS produksi:

```sql
SELECT id, kelompokpasien, statusenabled FROM kelompokpasien_m ORDER BY id;
```

Skor pencocokan (fungsi `penjaminSkorNama()` di `api.php`), nama di-uppercase:

| Pola pada nama kelompok | Skor |
|---|---|
| kata `KAI` atau frasa `KERETA API` | +8 |
| mengandung `ASURANSI` | +5 |
| `SWASTA` / `PERUSAHAAN` / `REKANAN` / `KERJASAMA` / `PRIVAT` | +2 |
| `PT` / `PERSERO` | +1 |
| `UMUM` / `REGULER` / `PRIBADI` / `MANDIRI` / `BAYAR SENDIRI` / `TUNAI` | **−100** (tidak pernah dipilih) |
| `BPJS` / `JKN` / `KIS` / `PBI` / `ASKES` / `JAMKESDA` / `JAMKESMAS` | **−80** (tidak pernah dipilih) |

Kandidat dengan skor tertinggi (> 0) dan `statusenabled = true` yang dipakai.
Contoh: `Asuransi PT KAI` (8+5+1 = 14) menang atas `Asuransi` (5).

**Bila namanya tidak cocok**, paksa lewat environment variable (di vhost Apache,
`.htaccess`, atau `php.ini` server API):

| Variabel | Default | Arti |
|---|---|---|
| `RSUD_KELOMPOK_PASIEN_ASURANSI_KAI` | *(kosong = deteksi otomatis)* | id `kelompokpasien_m` untuk penjamin Asuransi/KAI |
| `RSUD_KELOMPOK_PASIEN_UMUM` | `1` (`KELOMPOK_PASIEN_DEFAULT`) | id kelompok Umum |
| `RSUD_REKANAN_ASURANSI_KAI` | `0` (`REKANAN_DEFAULT`) | `objectrekananfk` untuk registrasi asuransi |
| `RSUD_REKANAN_UMUM` | `0` | `objectrekananfk` untuk registrasi umum |

Contoh `.htaccess`:

```apache
SetEnv RSUD_KELOMPOK_PASIEN_ASURANSI_KAI 4
```

Konstanta terkait di `api.php`: `PENJAMIN_UMUM = 'umum'`, `PENJAMIN_KAI = 'asuransi_kai'`,
`LABEL_PENJAMIN_KAI = 'Asuransi (KAI)'`, `BIAYA_REGISTRASI_KAI = 0`
(`BIAYA_REGISTRASI = 75000` tetap dipakai untuk Umum).

---

## 5. Rincian perubahan backend

### `backend/api.php`
* **Blok PENJAMIN** (setelah `$HARI_NAMA`): konstanta + fungsi
  `penjaminKonfigurasi()`, `penjaminKode()`, `penjaminLabel()`,
  `penjaminBiayaRegistrasi()`, `penjaminDitanggungAsuransi()`, `kelompokPasienRows()`,
  `penjaminSkorNama()`, `cariKelompokPasienId()`, `namaKelompokPasien()`,
  `resolvePenjamin()`, `penjaminDariKelompokId()`, `daftarPenjaminMaster()`.
  Nama kolom master dideteksi otomatis (`kelompokpasien` / `namakelompokpasien` /
  `namakelompok` / `nama`) dan kegagalan baca master tidak pernah fatal.
  Blok diakhiri guard `if (defined('RSUD_API_NO_RUN')) { return; }` supaya bisa
  diuji tanpa koneksi database.
* **`buatRegistrasiDanAntrian(..., $penjamin = null)`** — parameter baru (opsional,
  default = Umum sehingga pemanggil lama tidak berubah); `:kelompokpasien` dan
  `:rekanan` diisi dari penjamin; respons tiket menyertakan info penjamin.
* **`case 'daftar_online'`** — membaca `penjamin` (+ alias) dan `nomor_asuransi`,
  menolak bila asuransi belum dikonfigurasi, meneruskan penjamin ke
  `buatRegistrasiDanAntrian()`, menyimpan nomor asuransi ke
  `pasien_m.noasuransilain` (hanya bila kolomnya ada), dan menyesuaikan pesan sukses.
* **`case 'checkin'`** — ikut membaca `pd.objectkelompokpasienlastfk`, menentukan
  penjamin lewat `penjaminDariKelompokId()`, lalu `$perluTagihan`:
  hanya bila **true** blok `strukpelayanan_t` + `pelayananpasien_t` dijalankan
  (dan cek duplikasi struk). Check-in, antrian, dan `ischeckin` **selalu** jalan.
* **`case 'get_riwayat'` & `case 'get_ticket_detail'`** — mengembalikan penjamin,
  label, nama kelompok, `biaya_registrasi`, `ditanggung_asuransi`.
* **`case 'get_masters'`** — menambahkan `penjamin` + `penjamin_default`.

### `backend/checkin.php` (endpoint check-in lama, tetap mandiri)
Fungsi `kelompokPasienAsuransi()` ditambahkan; `saveCheckinData()` membaca
`objectkelompokpasienlastfk` dan **melewati** insert struk + pelayanan bila
penjaminnya asuransi. Respons menyertakan `penjamin`, `penjamin_label`,
`ditanggung_asuransi`, dan `tagihan_checkin` (0 untuk asuransi).

### `backend/barcode.php`
Teks info di halaman QR loket diperjelas: Rp 75.000 untuk pasien **UMUM**,
pasien **ASURANSI (KAI)** tetap check-in tanpa tagihan.

---

## 6. Pengujian

```bash
cd backend/tests
npm install          # sekali saja (butuh koneksi)
npm test             # lint:php + test:admin + test:penjamin + test:api + lint:sql
npm run test:penjamin   # khusus logika penjamin
```

| Perintah | Isi | Hasil terakhir |
|---|---|---|
| `npm run lint:php` | parse 7 file PHP (sintaks) | 7/7 valid |
| `npm run test:admin` | panel admin informasi (PHP-WASM + SQLite) | 134 lulus / 0 gagal |
| `npm run test:penjamin` | **logika penjamin Umum vs Asuransi/KAI** (`penjamin-smoke.mjs`) | 33 lulus / 0 gagal |
| `npm run test:api` | SQL api.php di PostgreSQL WASM (pglite), termasuk **seksi j: check-in KAI tanpa tagihan** | 43 lulus / 0 gagal |
| `npm run lint:sql` | dump SQL panel admin | 0 gagal |

`penjamin-smoke.mjs` memuat `api.php` dengan `define('RSUD_API_NO_RUN', true)` lalu
menguji seluruh fungsi penjamin terhadap SQLite: normalisasi kode input, deteksi
id kelompok dari nama, master tanpa kelompok asuransi, tabel master tidak ada,
override environment, nama kolom berbeda, dan kelompok nonaktif.

Frontend: `npm run build` (vite) dan `npx oxlint src` — keduanya bersih.

### Preview UI tanpa server SIMRS
`/home/user/preview/` (di luar repo) berisi backend tiruan untuk mencoba alur ini:

```bash
node /home/user/preview/mock-api.mjs                       # port 8787 (internal)
npx vite --config /home/user/preview/vite.config.preview.mjs   # dev server + proxy
```

Konfigurasi itu me-*alias* `constants/api` ke shim yang mengarahkan `API_BASE` ke
`/mock-api/api.php`, sehingga **kode aplikasi tidak diubah sama sekali**.

---

## 7. Deployment

1. Unggah `backend/api.php`, `backend/checkin.php`, `backend/barcode.php` ke server.
2. Pastikan kelompok pasien Asuransi/KAI ada di `kelompokpasien_m`
   (atau set `RSUD_KELOMPOK_PASIEN_ASURANSI_KAI`).
3. Bangun ulang aplikasi: `npm run build` lalu `npm run android:sync` / `android:apk`.
4. Uji cepat: daftar pasien KAI → cek `objectkelompokpasienlastfk` pada
   `pasiendaftar_t` → check-in scan QR → pastikan **tidak** ada baris baru di
   `strukpelayanan_t` / `pelayananpasien_t` untuk `noregistrasi` tersebut, tetapi
   `ischeckin = true` dan nomor antrian terbit.

```sql
-- verifikasi setelah check-in pasien KAI
SELECT pd.noregistrasi, pd.ischeckin, kp.kelompokpasien,
       (SELECT COUNT(*) FROM strukpelayanan_t s WHERE s.noregistrasi = pd.noregistrasi) AS jml_struk
FROM pasiendaftar_t pd
LEFT JOIN kelompokpasien_m kp ON kp.id = pd.objectkelompokpasienlastfk
ORDER BY pd.tglregistrasi DESC
LIMIT 10;
```
