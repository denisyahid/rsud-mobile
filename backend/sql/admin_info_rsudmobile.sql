-- ============================================================================
-- admin_info_rsudmobile.sql
-- Skema + data awal database ADMIN konten informasi Aplikasi Mobile
-- RSUD Malangbong (MySQL / MariaDB).
--
-- Database ini TERPISAH dari database SIMRS yang dipakai backend/api.php.
--
-- Cara import (pilih salah satu):
--   1) phpMyAdmin  : tab "Import" → pilih file ini → Go
--   2) Terminal    : mysql -h 192.168.22.251 -u rsudmalangbong -p < admin_info_rsudmobile.sql
--   3) Panel admin : menu "Cek Sistem" → tombol "Jalankan instalasi otomatis"
--
-- Aman dijalankan berulang kali (CREATE TABLE IF NOT EXISTS + INSERT dengan
-- syarat NOT EXISTS), sehingga tidak menduplikasi data yang sudah ada.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+07:00';

CREATE DATABASE IF NOT EXISTS `admin_info_rsudmobile`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `admin_info_rsudmobile`;

-- ---------------------------------------------------------------------------
-- STRUKTUR TABEL
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(150) NOT NULL DEFAULT '',
    email VARCHAR(180) NOT NULL DEFAULT '',
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    status_aktif TINYINT(1) NOT NULL DEFAULT 1,
    login_gagal SMALLINT NOT NULL DEFAULT 0,
    terkunci_sampai DATETIME NULL DEFAULT NULL,
    terakhir_login DATETIME NULL DEFAULT NULL,
    dibuat_pada DATETIME NOT NULL,
    diperbarui_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pengaturan (
    kunci VARCHAR(80) NOT NULL,
    nilai TEXT NULL,
    label VARCHAR(150) NOT NULL DEFAULT '',
    kelompok VARCHAR(40) NOT NULL DEFAULT 'umum',
    tipe VARCHAR(20) NOT NULL DEFAULT 'text',
    diperbarui_pada DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (kunci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slide (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    judul VARCHAR(180) NOT NULL,
    subjudul VARCHAR(255) NOT NULL DEFAULT '',
    gambar VARCHAR(255) NOT NULL DEFAULT '',
    tautan VARCHAR(255) NOT NULL DEFAULT '',
    warna_latar VARCHAR(20) NOT NULL DEFAULT '',
    urutan SMALLINT NOT NULL DEFAULT 0,
    status_aktif TINYINT(1) NOT NULL DEFAULT 1,
    dibuat_pada DATETIME NOT NULL,
    diperbarui_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_slide_aktif_urutan (status_aktif, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS informasi (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kategori VARCHAR(60) NOT NULL DEFAULT 'Pengumuman',
    judul VARCHAR(200) NOT NULL,
    konten TEXT NULL,
    gambar VARCHAR(255) NOT NULL DEFAULT '',
    tautan VARCHAR(255) NOT NULL DEFAULT '',
    tanggal DATE NULL DEFAULT NULL,
    urutan SMALLINT NOT NULL DEFAULT 0,
    status_aktif TINYINT(1) NOT NULL DEFAULT 1,
    dibuat_pada DATETIME NOT NULL,
    diperbarui_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_informasi_aktif (status_aktif, tanggal, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS panduan (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    judul VARCHAR(180) NOT NULL,
    isi TEXT NULL,
    ikon VARCHAR(40) NOT NULL DEFAULT '',
    urutan SMALLINT NOT NULL DEFAULT 0,
    status_aktif TINYINT(1) NOT NULL DEFAULT 1,
    dibuat_pada DATETIME NOT NULL,
    diperbarui_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_panduan_aktif_urutan (status_aktif, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarif (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kategori VARCHAR(60) NOT NULL DEFAULT 'Lainnya',
    nama_layanan VARCHAR(200) NOT NULL,
    satuan VARCHAR(60) NOT NULL DEFAULT '',
    tarif INT UNSIGNED NOT NULL DEFAULT 0,
    keterangan VARCHAR(255) NOT NULL DEFAULT '',
    urutan SMALLINT NOT NULL DEFAULT 0,
    status_aktif TINYINT(1) NOT NULL DEFAULT 1,
    dibuat_pada DATETIME NOT NULL,
    diperbarui_pada DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tarif_kategori (status_aktif, kategori, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    waktu DATETIME NOT NULL,
    username VARCHAR(120) NOT NULL DEFAULT '',
    aktivitas VARCHAR(80) NOT NULL DEFAULT '',
    keterangan VARCHAR(255) NOT NULL DEFAULT '',
    ip VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_admin_log_waktu (waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- PENGATURAN HALAMAN INFORMASI (nilai default)
-- ---------------------------------------------------------------------------

-- Identitas & Tampilan
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('app_nama', 'RSUD Malangbong', 'Nama rumah sakit', 'Identitas & Tampilan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('app_subjudul', 'Informasi & Layanan Rumah Sakit', 'Subjudul halaman', 'Identitas & Tampilan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('warna_utama', '#1b5e20', 'Warna utama', 'Identitas & Tampilan', 'color', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tampilkan_header', '0', 'Tampilkan header di atas halaman', 'Identitas & Tampilan', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tampilkan_slider', '1', 'Tampilkan slider gambar', 'Identitas & Tampilan', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('slider_autoplay', '1', 'Slider bergeser otomatis', 'Identitas & Tampilan', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('slider_interval', '5000', 'Jeda geser slider (milidetik)', 'Identitas & Tampilan', 'number', NOW());

-- Judul Seksi
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('info_judul_seksi', 'Informasi & Pengumuman', 'Judul seksi informasi', 'Judul Seksi', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tarif_judul_seksi', 'Tarif Layanan', 'Judul tab tarif', 'Judul Seksi', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('panduan_judul_seksi', 'Panduan Pemakaian Aplikasi', 'Judul seksi panduan', 'Judul Seksi', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tampilkan_tarif', '1', 'Tampilkan tab tarif layanan', 'Judul Seksi', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tampilkan_panduan', '1', 'Tampilkan tab panduan', 'Judul Seksi', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('panduan_buka_satu', '1', 'Hanya satu dropdown terbuka', 'Judul Seksi', 'switch', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('kontak_judul_seksi', 'Kontak & Layanan', 'Judul seksi kontak', 'Judul Seksi', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tampilkan_kontak', '1', 'Tampilkan tab kontak', 'Judul Seksi', 'switch', NOW());

-- Kontak & Layanan
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('alamat', 'Jl. Raya Malangbong, Kab. Garut, Jawa Barat', 'Alamat', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('jam_layanan', '24 Jam / 7 Hari', 'Jam pelayanan', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('telepon', '', 'Telepon / IGD', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('whatsapp', '6281385831193', 'Nomor WhatsApp', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('email', 'rsudmalangbonggarut@gmail.com', 'Email', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('website', 'https://rsud-malangbong.garutkab.go.id', 'Website', 'Kontak & Layanan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('maps', '', 'Link Google Maps', 'Kontak & Layanan', 'text', NOW());

-- Footer & Pesan
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('footer_teks', 'RSUD Malangbong — Kabupaten Garut, Jawa Barat', 'Teks footer', 'Footer & Pesan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('pesan_kosong_info', 'Belum ada informasi terbaru. Silakan cek kembali nanti.', 'Pesan bila informasi kosong', 'Footer & Pesan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('pesan_kosong_slide', '', 'Pesan bila slide kosong', 'Footer & Pesan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('pesan_kosong_tarif', 'Daftar tarif sedang diperbarui. Silakan hubungi petugas untuk informasi biaya.', 'Pesan bila tarif kosong', 'Footer & Pesan', 'text', NOW());
INSERT IGNORE INTO pengaturan (kunci, nilai, label, kelompok, tipe, diperbarui_pada) VALUES ('tarif_catatan', 'Tarif dapat berubah sewaktu-waktu sesuai peraturan yang berlaku. Pastikan konfirmasi ke petugas bila membutuhkan rincian biaya.', 'Catatan di bawah daftar tarif', 'Footer & Pesan', 'text', NOW());

-- ---------------------------------------------------------------------------
-- AKUN ADMIN PERTAMA
--   username : rsudmalangbonggarut@gmail.com
--   password : @_Malangbong123   <-- GANTI setelah login pertama!
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO admin_users
  (username, password_hash, nama_lengkap, email, role, status_aktif, login_gagal, dibuat_pada, diperbarui_pada)
VALUES ('rsudmalangbonggarut@gmail.com', '$2y$10$lrnfeT75qGtW5y11PS4OwObyKrDmSKxtcTtd5VgK5FSAN9jK5Ow/y', 'Administrator Konten Informasi', 'rsudmalangbonggarut@gmail.com', 'admin', 1, 0, NOW(), NOW());

-- ---------------------------------------------------------------------------
-- DATA CONTOH (hanya masuk bila judulnya belum ada)
-- ---------------------------------------------------------------------------

-- Slide gambar (gambar kosong — unggah lewat panel admin)
INSERT INTO slide (judul, subjudul, gambar, tautan, warna_latar, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Pendaftaran Online Lebih Cepat', 'Booking poliklinik dari rumah, tanpa antri lama di loket.', '', '', '#1b5e20', 10, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM slide WHERE judul = 'Pendaftaran Online Lebih Cepat');
INSERT INTO slide (judul, subjudul, gambar, tautan, warna_latar, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Hasil Lab & Radiologi di Genggaman', 'Unduh PDF hasil pemeriksaan begitu statusnya Selesai.', '', '', '#0f766e', 20, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM slide WHERE judul = 'Hasil Lab & Radiologi di Genggaman');
INSERT INTO slide (judul, subjudul, gambar, tautan, warna_latar, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Check-in Kunjungan Pakai QR', 'Pindai QR di loket admisi untuk memastikan kehadiran Anda.', '', '', '#1d4ed8', 30, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM slide WHERE judul = 'Check-in Kunjungan Pakai QR');

-- Kartu informasi
INSERT INTO informasi (kategori, judul, konten, gambar, tautan, tanggal, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Pengumuman', 'Selamat datang di Aplikasi Mobile RSUD Malangbong', 'Terima kasih telah menggunakan aplikasi mobile RSUD Malangbong.
Aplikasi ini memudahkan pendaftaran, booking kunjungan dokter, melihat hasil pemeriksaan, serta riwayat kunjungan — di mana pun dan kapan pun.', '', '', CURDATE(), 10, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM informasi WHERE judul = 'Selamat datang di Aplikasi Mobile RSUD Malangbong');
INSERT INTO informasi (kategori, judul, konten, gambar, tautan, tanggal, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Layanan', 'Pendaftaran Online Dibuka Setiap Hari', 'Pendaftaran online dapat dilakukan maksimal 30 hari sebelum kunjungan.
Datang 30 menit lebih awal dan lakukan check-in di loket admisi dengan memindai QR code.
Kuota setiap dokter terbatas — bila penuh, pilih jadwal lain.', '', '', CURDATE(), 20, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM informasi WHERE judul = 'Pendaftaran Online Dibuka Setiap Hari');
INSERT INTO informasi (kategori, judul, konten, gambar, tautan, tanggal, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Kesehatan', 'Siapkan Dokumen Sebelum Berobat', '- KTP atau Kartu Keluarga
- Kartu BPJS Kesehatan (bila ada)
- Surat rujukan dari faskes tingkat pertama
- Obat yang sedang dikonsumsi

Dokumen lengkap mempercepat proses pendaftaran di loket.', '', '', CURDATE(), 30, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM informasi WHERE judul = 'Siapkan Dokumen Sebelum Berobat');

-- Panduan pemakaian (tampil sebagai dropdown di aplikasi)
INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Login ke Aplikasi', 'Buka aplikasi RSUD Malangbong.
Masukkan No. Rekam Medis (No. CM) atau NIK pada kolom yang tersedia.
Tekan tombol Masuk.
Bila data tidak ditemukan, hubungi loket pendaftaran untuk memastikan nomor Anda sudah terdaftar.', 'login', 10, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = 'Login ke Aplikasi');
INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Mendaftar sebagai Pasien Baru', 'Pada halaman Login pilih Daftar Pasien Baru.
Isi NIK 16 digit — sistem memvalidasi format NIK secara otomatis.
Lengkapi nama, tempat & tanggal lahir, alamat, dan nomor HP aktif.
Tekan Daftar. Nomor Rekam Medis akan diberikan setelah data diverifikasi petugas.', 'pasien', 20, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = 'Mendaftar sebagai Pasien Baru');
INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Booking Kunjungan Poliklinik', 'Masuk ke tab Booking.
Pilih tanggal kunjungan (maksimal 30 hari ke depan).
Pilih poliklinik, lalu pilih dokter yang jadwalnya tersedia.
Periksa kembali ringkasan booking, lalu tekan Konfirmasi.
Simpan nomor antrian yang muncul sebagai bukti pendaftaran.', 'kalender', 30, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = 'Booking Kunjungan Poliklinik');
INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Melihat & Mengunduh Hasil Pemeriksaan', 'Buka tab Hasil.
Pilih pemeriksaan Laboratorium atau Radiologi berstatus Selesai.
Tekan Unduh PDF untuk menyimpan hasil ke ponsel.
Hasil hanya muncul setelah dokter/instansi selesai memverifikasi.', 'hasil', 40, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = 'Melihat & Mengunduh Hasil Pemeriksaan');
INSERT INTO panduan (judul, isi, ikon, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Riwayat Kunjungan & Bukti Antrian', 'Buka tab Riwayat untuk melihat seluruh kunjungan.
Tekan Lihat Bukti untuk menampilkan kartu antrian beserta QR check-in.
Kunjungan rawat jalan yang belum dilayani dapat dibatalkan dari halaman ini.
Saat tiba di rumah sakit, lakukan check-in dengan memindai QR di loket admisi.', 'riwayat', 50, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM panduan WHERE judul = 'Riwayat Kunjungan & Bukti Antrian');

-- Tarif layanan (angka hanya CONTOH — ganti dengan tarif resmi rumah sakit)
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Pendaftaran', 'Pendaftaran Rawat Jalan', 'per kunjungan', 15000, 'Termasuk kartu berobat untuk pasien baru.', 10, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Pendaftaran Rawat Jalan');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Rawat Jalan', 'Konsultasi Dokter Umum', 'per kunjungan', 35000, '', 20, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Konsultasi Dokter Umum');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Rawat Jalan', 'Konsultasi Dokter Spesialis', 'per kunjungan', 75000, 'Mengikuti jadwal praktik poliklinik.', 30, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Konsultasi Dokter Spesialis');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'IGD', 'Pemeriksaan IGD', 'per kunjungan', 100000, 'Belum termasuk obat dan tindakan medis.', 40, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Pemeriksaan IGD');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Laboratorium', 'Darah Lengkap', 'per pemeriksaan', 65000, '', 50, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Darah Lengkap');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Laboratorium', 'Gula Darah Sewaktu', 'per pemeriksaan', 25000, '', 60, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Gula Darah Sewaktu');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Radiologi', 'Rontgen Thorax', 'per foto', 120000, 'Hasil dapat diunduh lewat aplikasi.', 70, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Rontgen Thorax');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Rawat Inap', 'Kamar Kelas III', 'per hari', 150000, 'Termasuk visite dokter dan perawatan.', 80, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Kamar Kelas III');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Rawat Inap', 'Kamar Kelas II', 'per hari', 275000, '', 90, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Kamar Kelas II');
INSERT INTO tarif (kategori, nama_layanan, satuan, tarif, keterangan, urutan, status_aktif, dibuat_pada, diperbarui_pada)
SELECT 'Persalinan', 'Persalinan Normal', 'per tindakan', 2500000, 'Belum termasuk penanganan komplikasi.', 100, 1, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tarif WHERE nama_layanan = 'Persalinan Normal');

SET FOREIGN_KEY_CHECKS = 1;

-- Selesai. Silakan buka backend/admin.php lalu login.
