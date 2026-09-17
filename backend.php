<?php
/**
 * RSUD Malangbong — Halaman Informasi & Panduan Pemakaian Aplikasi
 * Dimuat penuh sebagai web di dalam aplikasi (tab Informasi) maupun browser.
 * -------------------------------------------------------------
 * Cara menambah informasi/pengumuman baru:
 *   1. Duplikat blok <article class="card info-card"> di bawah.
 *   2. Ganti judul, tanggal, dan isi.
 * Saat server RSUD sudah menyediakan berita, bagian ini bisa
 * diganti menjadi feed dari database tanpa mengubah struktur halaman.
 */
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#14521A">
<title>Informasi — RSUD Malangbong</title>
<style>
  :root {
    --green-900: #123c16;
    --green-700: #1b5e20;
    --green-600: #2e7d32;
    --green-100: #e6f2e7;
    --ink: #1f2937;
    --muted: #6b7280;
    --line: #e5e7eb;
    --bg: #f6f9f6;
    --white: #ffffff;
    --radius: 16px;
    --shadow: 0 1px 3px rgba(16, 60, 20, .07), 0 8px 24px rgba(16, 60, 20, .05);
  }
  * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
  }

  /* ── Header ringkas ─────────────────────────────────────── */
  .header {
    background: linear-gradient(180deg, var(--green-700), var(--green-600));
    color: #fff;
    padding: 22px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .header .logo {
    width: 46px; height: 46px; flex: none;
    background: #fff; border-radius: 13px;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 3px 10px rgba(0,0,0,.18);
  }
  .header .logo svg { width: 28px; height: 28px; }
  .header h1 { font-size: 16px; font-weight: 800; letter-spacing: .2px; }
  .header p { font-size: 11.5px; opacity: .9; margin-top: 2px; }

  .container { max-width: 640px; margin: 0 auto; padding: 16px 14px 40px; }
  .stack { display: flex; flex-direction: column; gap: 12px; }

  .card {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 16px;
  }
  .card h2 {
    font-size: 13px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .6px; color: var(--green-700);
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 12px;
  }
  .card h2 svg { color: var(--green-600); }

  /* ── Langkah panduan (ringkas) ──────────────────────────── */
  .step {
    display: flex; align-items: flex-start; gap: 11px;
    padding: 10px 0; border-top: 1px solid #f1f3f1;
  }
  .step:first-child { border-top: 0; padding-top: 2px; }
  .step b {
    font-size: 11px; font-weight: 800; color: var(--green-700);
    width: 24px; height: 24px; flex: none; border-radius: 8px;
    background: var(--green-100);
    display: inline-flex; align-items: center; justify-content: center;
  }
  .step h3 { font-size: 13.5px; font-weight: 700; }
  .step p { font-size: 12px; color: var(--muted); line-height: 1.5; margin-top: 2px; }

  /* ── Pengumuman ─────────────────────────────────────────── */
  .tag {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
    color: var(--green-700); background: var(--green-100);
    padding: 3px 9px; border-radius: 999px; margin-bottom: 8px;
  }
  .info-card h3 { font-size: 14.5px; font-weight: 800; line-height: 1.4; }
  .info-date { font-size: 11px; color: var(--muted); margin-top: 4px; }
  .info-card p { font-size: 12.5px; color: var(--muted); line-height: 1.6; margin-top: 8px; }

  /* ── Kontak ─────────────────────────────────────────────── */
  .contact { display: flex; flex-direction: column; }
  .contact a, .contact .row {
    display: flex; align-items: center; gap: 11px;
    padding: 11px 4px; border-top: 1px solid #f1f3f1;
    text-decoration: none; color: inherit;
  }
  .contact a:first-child, .contact .row:first-child { border-top: 0; }
  .contact .ic {
    width: 34px; height: 34px; flex: none; border-radius: 10px;
    background: var(--green-100); color: var(--green-700);
    display: inline-flex; align-items: center; justify-content: center;
  }
  .contact b { display: block; font-size: 12.5px; font-weight: 700; }
  .contact span { font-size: 11.5px; color: var(--muted); }
  .contact .ic .go { margin-left: auto; color: #9ca3af; font-size: 12px; }

  .footer { text-align: center; font-size: 11px; color: #9ca3af; padding: 18px 14px 34px; line-height: 1.6; }
  .footer b { color: var(--green-700); }
</style>
</head>
<body>

  <header class="header">
    <span class="logo">
      <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
        <rect width="48" height="48" rx="13" fill="#fff"/>
        <path d="M24 9v30M9 24h30" stroke="#1b5e20" stroke-width="6" stroke-linecap="round"/>
        <circle cx="24" cy="24" r="9" fill="#2e7d32" stroke="#fff" stroke-width="3"/>
        <circle cx="24" cy="24" r="3" fill="#fff"/>
      </svg>
    </span>
    <div>
      <h1>RSUD Malangbong</h1>
      <p>Informasi &amp; Panduan Pemakaian Aplikasi</p>
    </div>
  </header>

  <main class="container">
    <div class="stack">

      <!-- ══ PANDUAN PEMAKAIAN ══ -->
      <section class="card">
        <h2>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/></svg>
          Panduan Pemakaian
        </h2>
        <div class="step"><b>1</b><div><h3>Login</h3><p>Masukkan No. Rekam Medis (No. CM) atau NIK, lalu tekan Masuk.</p></div></div>
        <div class="step"><b>2</b><div><h3>Pasien Baru</h3><p>Belum pernah berobat? Pilih Daftar Pasien Baru, NIK divalidasi otomatis.</p></div></div>
        <div class="step"><b>3</b><div><h3>Booking Kunjungan</h3><p>Pilih tanggal, poliklinik, dan dokter yang tersedia di tab Booking.</p></div></div>
        <div class="step"><b>4</b><div><h3>Hasil Pemeriksaan</h3><p>Unduh PDF hasil Laboratorium &amp; Radiologi pada pemeriksaan berstatus Selesai.</p></div></div>
        <div class="step"><b>5</b><div><h3>Riwayat &amp; Bukti Antrian</h3><p>Lihat kunjungan, nomor antrian, atau batalkan (rawat jalan yang belum pulang).</p></div></div>
      </section>

      <!-- ══ PENGUMUMAN ══ -->
      <!--
        Tempat menampilkan pengumuman & berita terbaru.
        Saat server berita RSUD siap, ganti blok ini dengan feed dinamis.
        Duplikat blok <article> untuk menambah pengumuman.
      -->
      <section class="card info-card">
        <span class="tag">Pengumuman</span>
        <h3>Selamat datang di Aplikasi Mobile RSUD Malangbong</h3>
        <div class="info-date"><?php echo date('d F Y'); ?></div>
        <p>Terima kasih telah menggunakan aplikasi mobile RSUD Malangbong. Aplikasi ini memudahkan pendaftaran, booking kunjungan dokter, melihat hasil pemeriksaan, serta riwayat kunjungan di mana pun dan kapan pun.</p>
      </section>

      <!-- ══ KONTAK ══ -->
      <section class="card">
        <h2>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 2 .7 2.8a2 2 0 01-.5 2.1L8 10a16 16 0 006 6l1.4-1.3a2 2 0 012.1-.5c.9.3 1.9.6 2.8.7a2 2 0 011.7 2z"/></svg>
          Kontak &amp; Layanan
        </h2>
        <div class="contact">
          <div class="row">
            <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
            <div><b>Lokasi</b><span>Jl. Raya Malangbong, Kab. Garut</span></div>
          </div>
          <div class="row">
            <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
            <div><b>Jam Pelayanan</b><span>24 Jam / 7 Hari</span></div>
          </div>
          <a href="https://wa.me/6281385831193">
            <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 2 .7 2.8a2 2 0 01-.5 2.1L8 10a16 16 0 006 6l1.4-1.3a2 2 0 012.1-.5c.9.3 1.9.6 2.8.7a2 2 0 011.7 2z"/></svg></span>
            <div><b>Hotline WhatsApp</b><span>0813 8583 1193</span></div>
            <span class="go">›</span>
          </a>
          <a href="https://rsud-malangbong.garutkab.go.id">
            <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7L12 13 2 7"/><rect x="2" y="5" width="20" height="14" rx="2"/></svg></span>
            <div><b>Website</b><span>rsud-malangbong.garutkab.go.id</span></div>
            <span class="go">›</span>
          </a>
        </div>
      </section>

    </div>
  </main>

  <footer class="footer">
    <b>RSUD Malangbong</b> — Kabupaten Garut, Jawa Barat<br>
    &copy; <?php echo date('Y'); ?> RSUD Malangbong
  </footer>

</body>
</html>
