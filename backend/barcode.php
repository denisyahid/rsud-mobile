<?php
// barcode.php - QR Code Check-in UMUM RSUD Malangbong
// ------------------------------------------------------------------
// Satu QR yang sama untuk SEMUA pasien (dipajang di loket admisi).
// Saat QR ini di-scan dari aplikasi RSUD Mobile (Riwayat → Check-in),
// sistem otomatis menambahkan tindakan registrasi Rp 75.000 pada
// kunjungan aktif pasien yang sedang check-in.
//
// Nilai QR berisi tanggal hari ini sehingga barcode otomatis ganti
// setiap hari dan tidak bisa dipakai ulang di hari berikutnya.
// ------------------------------------------------------------------

// Set timezone ke Indonesia (WIB/WITA/WIT)
date_default_timezone_set('Asia/Jakarta');

$barcodeData = 'CHECKIN-RSUD-MALANGBONG-' . date('Ymd');
$tanggalLabel = date('d-m-Y');
$jamLabel = date('H:i');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check-in RSUD Malangbong</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: linear-gradient(160deg, #0f3d17 0%, #1B5E20 55%, #2e7d32 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
        padding: 20px;
    }
    .card {
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        max-width: 420px;
        width: 100%;
        padding: 28px 24px 24px;
        text-align: center;
    }
    .kop {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 14px;
    }
    .kop img {
        width: 52px;
        height: 52px;
        object-fit: contain;
        border-radius: 12px;
    }
    .kop .nama {
        text-align: left;
        line-height: 1.15;
    }
    .kop .nama strong {
        display: block;
        font-size: 16px;
        color: #14521A;
        letter-spacing: 0.5px;
    }
    .kop .nama span {
        display: block;
        font-size: 11px;
        color: #6b7280;
    }
    h1 {
        font-size: 22px;
        color: #14521A;
        margin: 6px 0 4px;
        letter-spacing: 1px;
    }
    .sub {
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 16px;
    }
    .qr-box {
        background: #f8fafc;
        border: 2px dashed #cbd5e1;
        border-radius: 18px;
        padding: 16px;
        display: inline-block;
    }
    .qr-box img {
        width: 260px;
        height: 260px;
        display: block;
        border-radius: 8px;
    }
    .kode {
        margin: 14px auto 0;
        max-width: 320px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #14521A;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: 12px;
        font-weight: 600;
        padding: 8px 12px;
        border-radius: 10px;
        word-break: break-all;
        letter-spacing: 0.5px;
    }
    .info {
        margin-top: 16px;
        font-size: 13px;
        color: #334155;
        line-height: 1.6;
    }
    .info b { color: #b91c1c; }
    .tanggal {
        display: inline-block;
        margin-top: 12px;
        background: #14521A;
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        padding: 6px 14px;
        border-radius: 999px;
        letter-spacing: 0.5px;
    }
    .footer {
        margin-top: 18px;
        padding-top: 12px;
        border-top: 1px solid #e5e7eb;
        font-size: 11px;
        color: #9ca3af;
    }
</style>
</head>
<body>
    <div class="card">
        <div class="kop">
            <img src="logo.png" alt="Logo" onerror="this.style.display='none'">
            <div class="nama">
                <strong>RSUD MALANGBONG</strong>
                <span>Jl. Raya Malangbong – Garut, Jawa Barat</span>
            </div>
        </div>

        <h1>SCAN UNTUK CHECK-IN</h1>
        <p class="sub">Buka aplikasi RSUD Mobile → menu Riwayat → tombol Check-in, lalu scan QR di bawah ini.</p>

        <div class="qr-box">
            <img
                src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($barcodeData) ?>&bgcolor=ffffff&color=000000"
                alt="QR Code Check-in RSUD Malangbong">
        </div>

        <div class="kode"><?= htmlspecialchars($barcodeData) ?></div>

        <div class="info">
            Scan QR ini akan otomatis menambahkan<br>
            tindakan <b>REGISTRASI sebesar Rp 75.000</b>.<br>
            Berlaku untuk kunjungan hari ini.
        </div>

        <div class="tanggal"><?= $tanggalLabel ?> • <?= $jamLabel ?> WIB</div>

        <div class="footer">Loket Admisi Rawat Jalan • RSUD Malangbong</div>
    </div>
</body>
</html>
