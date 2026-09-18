# 🖥️ Panel Admin Konten "Informasi" — RSUD Mobile

Panel admin untuk mengelola isi halaman **Informasi** pada aplikasi mobile pasien
RSUD Malangbong: **slider gambar**, **kartu pengumuman bergambar**, **panduan
berbentuk dropdown**, serta **kontak & tampilan halaman**.

Semua perubahan di panel ini **langsung tampil di aplikasi** tanpa perlu
membangun ulang APK, karena tab *Informasi* memuat `backend/informasi.php`
yang isinya diambil dari database.

---

## 1. Berkas yang ditambahkan

| Berkas | Fungsi |
|---|---|
| `backend/admin.php` | Panel admin: login, CRUD slide/kartu/panduan, upload gambar, pengaturan, cek sistem |
| `backend/admin-config.php` | Konfigurasi database admin (**terpisah** dari `api.php`), helper, skema tabel |
| `backend/informasi.php` | Halaman publik yang tampil di tab *Informasi* (isi sepenuhnya dinamis) |
| `backend/sql/admin_info_rsudmobile.sql` | Skema + data awal database admin — tinggal **import** |
| `backend/uploads/` | Folder penyimpanan gambar hasil unggahan (`.htaccess` menolak eksekusi skrip) |
| `src/components/TabInformasi.jsx` | Tab *Informasi* — judul ganda di atas iframe dihapus |
| `backend/tests/admin-smoke.mjs` | 134 pengujian otomatis (PHP WebAssembly + SQLite) |
| `backend/tests/generate-sql.mjs` | Membuat ulang file `.sql` dari kode panel (anti melenceng) |
| `backend/tests/sql-lint.mjs` | Memeriksa sintaks MySQL file `.sql` |

> Database admin memakai **MySQL/MariaDB**, sedangkan `api.php` tetap memakai
> **PostgreSQL SIMRS**. Keduanya tidak saling bergantung.

---

## 2. Cara memasang (±5 menit)

### a. Salin berkas ke server
Salin seluruh isi folder `backend/` ke lokasi yang sama dengan `api.php`
sekarang berada, misalnya:

```
/var/www/html/rsud-mobile/backend/
├── admin.php
├── admin-config.php
├── informasi.php
├── api.php            (yang sudah ada — tidak diubah)
├── uploads/           (folder gambar)
└── sql/admin_info_rsudmobile.sql
```

### b. Buat database & import SQL
Lewat **phpMyAdmin**: tab *Import* → pilih `backend/sql/admin_info_rsudmobile.sql` → **Go**.

Lewat **terminal**:

```bash
mysql -h 192.168.22.251 -P 3306 -u rsudmalangbong -p < backend/sql/admin_info_rsudmobile.sql
```

File SQL itu idempoten — aman dijalankan berulang, tidak menduplikasi data.

**Alternatif tanpa import:** buka `admin.php` → menu **Cek Sistem** →
tombol **Jalankan Instalasi** (tabel dibuat otomatis dari panel).

### c. Beri izin tulis folder upload
```bash
chmod 775 backend/uploads
chown www-data:www-data backend/uploads     # sesuaikan user web server
```

### d. Sesuaikan koneksi database (bila perlu)
Buka `backend/admin-config.php` bagian **1. KONEKSI DATABASE ADMIN**:

```php
'host' => '192.168.22.251',           // 'localhost' bila PHP jalan di mesin DB itu sendiri
'port' => 3306,
'name' => 'admin_info_rsudmobile',
'user' => 'rsudmalangbong',
'pass' => 'garutKAB@2024',
```

Atau lewat *environment variable* (disarankan untuk produksi):

```
RSUD_ADMIN_DB_HOST, RSUD_ADMIN_DB_PORT, RSUD_ADMIN_DB_NAME,
RSUD_ADMIN_DB_USER, RSUD_ADMIN_DB_PASS, RSUD_ADMIN_DB_DRIVER
```

### e. Login
Buka `http://server-anda/rsud-mobile/backend/admin.php`

| | |
|---|---|
| **Username** | `rsudmalangbonggarut@gmail.com` |
| **Password** | `@_Malangbong123` |

> ⚠️ Segera ganti password lewat menu **Akun Saya** setelah login pertama.
> Akun terkunci otomatis 15 menit setelah 5 kali salah password.

---

## 3. Isi panel admin

| Menu | Yang dikelola | Tampil di aplikasi sebagai |
|---|---|---|
| **Dashboard** | Ringkasan jumlah konten, status sistem, jejak aktivitas | — |
| **Slide Gambar** | Gambar besar + judul + subjudul + tautan | Slider (geser otomatis, bisa di-swipe) di paling atas |
| **Kartu Informasi** | Kategori, judul, isi, gambar, tanggal, tautan | Kartu bergambar (2 kolom di layar lebar, 1 kolom di ponsel) |
| **Panduan (Dropdown)** | Judul + isi langkah + ikon | Dropdown/akordion — hemat tempat, dibuka saat dibutuhkan |
| **Pengaturan Halaman** | Warna tema, judul tiap seksi, kontak (alamat, jam, WA, email, website, Maps), footer | Semua bagian non-konten |
| **Akun Saya** | Nama, email, ganti password | — |
| **Cek Sistem** | Status koneksi DB, kelengkapan tabel, folder upload, jejak aktivitas, unduh file SQL | — |

Setiap baris data punya tombol: ▲ ▼ (urutan), **Aktif/Nonaktif** (sembunyikan
tanpa menghapus), ✎ (ubah), 🗑 (hapus — gambar ikut terhapus dari server).

### Upload gambar
* Format: **JPG, PNG, WEBP, GIF** — maks **5 MB** per file.
* Jenis file diperiksa dari **isi berkas** (finfo), bukan dari nama — berkas PHP
  yang menyamar sebagai gambar akan ditolak.
* Gambar besar otomatis diperkecil ke maksimal **1600 px** (hemat kuota pasien).
* Ukuran ideal: slide **1200 × 600 px**, kartu **800 × 500 px**.
* Tanpa gambar pun tetap rapi: slide/kartu memakai latar gradien otomatis.

### Cara menulis isi (kartu & panduan)
Teks biasa, tidak perlu HTML:

```
Pendaftaran dibuka pukul 07.00 WIB.      → paragraf
- Bawa KTP                               → daftar bullet
- Bawa kartu BPJS
1. Langkah pertama                       → daftar bernomor
**teks ini jadi tebal**                  → cetak tebal
```

Semua keluaran di-*escape*, jadi menempel HTML/JavaScript tidak akan dieksekusi.

---

## 4. Halaman informasi.php

* **Header hijau dihapus** sesuai permintaan — halaman langsung mulai dari slider.
  Bila suatu saat ingin ditampilkan lagi: **Pengaturan Halaman → "Tampilkan header"**.
* Teks lama *"Informasi & panduan pemakaian aplikasi RSUD Malangbong"* di atas
  iframe (dalam `TabInformasi.jsx`) juga sudah dihapus — tidak ada judul ganda.
* Panduan tampil sebagai **dropdown** (satu terbuka pada satu waktu; bisa diubah
  di pengaturan).
* Tersedia mode data mentah untuk pengembangan lanjut: `informasi.php?format=json`.
* **Tidak pernah error di depan pasien:** bila database admin mati atau tabel
  belum dibuat, halaman otomatis menampilkan konten cadangan + catatan halus.

---

## 5. Pemecahan masalah

| Gejala | Penyebab & solusi |
|---|---|
| Halaman login muncul peringatan *"Database admin belum tersambung"* | Kredensial/host di `admin-config.php` belum cocok, atau MySQL mati. Cek menu **Cek Sistem** untuk pesan error aslinya. |
| *"Tabel belum lengkap"* | Import `sql/admin_info_rsudmobile.sql` atau jalankan **Cek Sistem → Instalasi Otomatis**. |
| Upload gagal: *"Folder upload tidak bisa ditulis"* | `chmod 775 backend/uploads` dan pastikan pemiliknya user web server. |
| Upload gagal padahal file kecil | `upload_max_filesize` / `post_max_size` di `php.ini` terlalu kecil (lihat nilainya di menu **Cek Sistem**). |
| Gambar tidak muncul di aplikasi | Nama file di DB ada tetapi berkasnya hilang (mis. folder upload tidak ikut tersalin). Unggah ulang gambarnya. |
| Perubahan tidak terlihat di aplikasi | Tarik-turun/muat ulang tab Informasi, atau tutup-buka aplikasi. Halaman mengirim header anti-cache. |
| Halaman informasi terasa lambat | Pastikan indeks tabel ada (sudah dibuat oleh file SQL) dan ukuran gambar wajar. |
| *Fatal error: Undefined constant* saat `admin.php` dibuka | Sudah diperbaiki: kode lama membandingkan `PHP_SESSION_NAME` (konstanta itu tidak pernah ada di PHP) sebelum `session_name()` dipanggil. Sekarang nama sesi dibaca lewat `session_name()` (helper `adminSessionNameBeda()`), dan `npm run lint:const` menolak konstanta asing semacam ini sebelum naik ke server. |

---

## 6. Pengujian otomatis

Tidak perlu server PHP/MySQL di laptop — pengujian memakai **PHP WebAssembly**
dengan SQLite sementara, tetapi menjalankan kode produksi yang sama persis:

```bash
cd backend/tests
npm install
npm test              # lint PHP + lint konstanta + 146 uji panel/halaman + lint SQL
npm run test:admin    # hanya uji panel admin & informasi.php
npm run lint:php      # periksa sintaks semua file .php
npm run lint:const    # pastikan tidak ada konstanta PHP yang tidak dikenal
npm run lint:sql      # periksa sintaks MySQL file .sql
npm run sql:generate  # buat ulang sql/admin_info_rsudmobile.sql dari kode
```

Yang diuji antara lain: instalasi skema, render halaman, login (gagal/sukses/
terkunci), penolakan CSRF, upload gambar sungguhan (termasuk pengecilan ukuran),
penolakan berkas berbahaya, toggle & urutan, CRUD ketiga jenis konten,
pengaturan halaman, ganti password, render seluruh halaman panel, penghapusan
berkas, keabsahan file SQL, jalur cadangan saat database mati, serta **sesi panel
admin lewat jalur web server sungguhan** (nama sesi `RSUDADMINSESS`, cookie
`Secure`/`HttpOnly`/`SameSite=Lax`, dan `admin.php` dibuka sebagai request penuh
tanpa fatal error).

`npm run lint:const` membandingkan setiap konstanta `HURUF_KAPITAL` di
`backend/*.php` (hasil pembacaan AST) dengan daftar konstanta PHP sungguhan dari
runtime PHP WebAssembly — penjaga agar bug seperti `PHP_SESSION_NAME` tidak
terulang.

---

## 7. Keamanan

* Password disimpan sebagai **hash bcrypt** (`password_hash`), tidak pernah teks polos.
* Semua query memakai **prepared statement** (anti SQL injection).
* Semua keluaran di-*escape* (anti XSS), termasuk isi yang ditulis admin.
* Token **CSRF** wajib untuk setiap aksi tulis; sesi berakhir setelah 2 jam menganggur.
* Cookie sesi `HttpOnly`, dan otomatis `Secure` bila diakses lewat HTTPS.
* Folder `uploads/` menolak eksekusi skrip (`.htaccess`) dan daftar isi dimatikan.
* Panel mengirim `X-Frame-Options: SAMEORIGIN` + `noindex`; `informasi.php`
  sengaja **tidak** mengirim `X-Frame-Options` agar tetap bisa dimuat di iframe aplikasi.
* Setiap aktivitas (login, ubah, hapus) tercatat di tabel `admin_log` beserta IP.
* Saran tambahan untuk produksi: batasi akses `admin.php` ke jaringan internal
  rumah sakit (firewall/VPN) dan selalu gunakan HTTPS.
