import React, { useState } from 'react';
import Swal from 'sweetalert2';

export default function Login({ onLogin, loading, error, onRegisterClick }) {
  const [identifier, setIdentifier] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [imgError, setImgError] = useState(false);

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!identifier.trim()) {
      setFieldError('Masukkan NIK atau No. Rekam Medis terlebih dahulu');
      return;
    }
    setFieldError('');
    onLogin(identifier.trim()); // kirim identifier
  };

  // Hanya menerima maksimal 16 karakter (NIK 16 digit / No. RM maksimal 16 digit),
  // dan langsung login otomatis begitu panjang input mencapai 16 karakter.
  const handleChange = (value) => {
    const clean = value.replace(/\s/g, ''); // buang spasi agar panjang sesuai inputan
    if (clean.length > 16) return; // batasi maksimal 16 karakter
    setIdentifier(clean);
    if (fieldError) setFieldError('');
    if (clean.length === 16) {
      onLogin(clean); // auto-login saat mencapai 16 karakter
    }
  };

  // Panduan pemakaian aplikasi (modal)
  const showPanduan = () => {
    Swal.fire({
      title: 'Panduan Pemakaian',
      html: `
        <div class="text-left text-sm text-gray-700 space-y-3" style="max-height:55vh;overflow-y:auto;padding:0 4px;">
          <div class="flex items-start gap-3">
            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 font-bold flex items-center justify-center text-xs">1</span>
            <div><strong class="block">Masuk Aplikasi</strong>Masukkan NIK atau No. Rekam Medis (RM) Anda, lalu tekan <em>Masuk</em>.</div>
          </div>
          <div class="flex items-start gap-3">
            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 font-bold flex items-center justify-center text-xs">2</span>
            <div><strong class="block">Daftar Pasien Baru (Umum)</strong>Belum punya RM? Klik <em>Daftar Online Pasien Umum</em>, masukkan NIK untuk divalidasi, lalu lengkapi data diri &amp; pilih poliklinik.</div>
          </div>
          <div class="flex items-start gap-3">
            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 font-bold flex items-center justify-center text-xs">3</span>
            <div><strong class="block">Reservasi Dokter</strong>Pilih poliklinik lalu tanggal kunjungan untuk melihat jadwal dokter dan melakukan reservasi kunjungan.</div>
          </div>
          <div class="flex items-start gap-3">
            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 font-bold flex items-center justify-center text-xs">4</span>
            <div><strong class="block">Hasil Pemeriksaan</strong>Lihat hasil laboratorium &amp; radiologi Anda pada menu <em>Hasil</em>.</div>
          </div>
          <div class="flex items-start gap-3">
            <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 font-bold flex items-center justify-center text-xs">5</span>
            <div><strong class="block">Riwayat &amp; Tiket</strong>Lihat riwayat kunjungan, tiket antrian, serta batalkan reservasi jika diperlukan pada menu <em>Riwayat</em>.</div>
          </div>
          <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-800">
            <i class="fas fa-phone-alt mr-1"></i> Butuh bantuan? Hubungi hotline RSUD Malangbong: <strong>0813 8583 1193</strong>
          </div>
        </div>
      `,
      confirmButtonText: 'Mengerti',
      confirmButtonColor: '#2e7d32',
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
      },
    });
  };

  return (
    <div className="login-screen">
      <div className="login-card anim-fade-up">
        {/* Logo */}
        <div className="text-center mb-1">
          <div className="login-logo-wrap anim-pop">
            {imgError ? (
              <i className="fas fa-hospital-user login-fallback"></i>
            ) : (
              <img
                src="./logo.png"
                alt="Logo RSUD Malangbong"
                onError={() => setImgError(true)}
              />
            )}
          </div>
          <h1 className="login-title">RSUD Malangbong</h1>
          <p className="login-sub">
            Aplikasi Mobile Pasien — cek jadwal dokter, hasil lab, radiologi, obat &amp; antrian Anda
          </p>
        </div>

        {/* Error dari server */}
        {error && (
          <div className="login-error anim-shake">
            <i className="fas fa-exclamation-circle"></i>
            <span>{error}</span>
          </div>
        )}

        {/* Error validasi field */}
        {fieldError && (
          <div className="login-error anim-shake">
            <i className="fas fa-exclamation-circle"></i>
            <span>{fieldError}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} noValidate>
          <div className="login-field">
            <i className="fas fa-id-card"></i>
            <input
              type="text"
              value={identifier}
              maxLength={16}
              onChange={(e) => handleChange(e.target.value)}
              className={error || fieldError ? 'border-red-400' : ''}
              placeholder="NIK atau No Rekam Medis"
              autoComplete="off"
              inputMode="numeric"
              required
            />
          </div>
          <p className="login-hint">
            <i className="fas fa-info-circle"></i>
            NIK (16 digit) atau No. Rekam Medis — maksimal 16 karakter, masuk otomatis setelah 16 digit.
          </p>
          <button type="submit" disabled={loading} className="login-btn">
            {loading ? (
              <>
                <span className="btn-spinner"></span> Memeriksa...
              </>
            ) : (
              <>
                <i className="fas fa-sign-in-alt"></i> Masuk
              </>
            )}
          </button>
        </form>

        <button onClick={onRegisterClick} className="login-register">
          <i className="fas fa-user-plus"></i> Daftar Online Pasien Umum
        </button>

        <button onClick={showPanduan} className="login-panduan">
          <i className="fas fa-book-open"></i> Panduan Pemakaian
        </button>

      </div>
    </div>
  );
}
