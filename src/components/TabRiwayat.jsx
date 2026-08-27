import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { formatDate, API_BASE } from '../constants/api';
import Swal from 'sweetalert2';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

export default function TabRiwayat({ data, ticketToShow, clearTicket, onRefresh }) {
  const [loadingTicket, setLoadingTicket] = useState(false);
  const [ticketData, setTicketData] = useState(null);
  const [showModal, setShowModal] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  // ── State Scanner Check-in ──
  const [showScanner, setShowScanner] = useState(false);
  const [currentCheckinReg, setCurrentCheckinReg] = useState(null);
  const [scannerError, setScannerError] = useState('');
  const [manualBarcode, setManualBarcode] = useState('');
  const [processingCheckin, setProcessingCheckin] = useState(false);
  const scannerRef = useRef(null);
  // Ref agar callback scanner selalu memakai performCheckin versi render terbaru
  // (tanpa ini, startScanner yang di-memoize menangkap performCheckin lama yang
  //  masih punya currentCheckinReg = null, sehingga scan tidak melakukan apa-apa).
  const performCheckinRef = useRef(null);

  // Tombol refresh mengambang — segarkan daftar riwayat manual
  const handleRefresh = async () => {
    if (!onRefresh || refreshing) return;
    setRefreshing(true);
    try {
      await onRefresh();
    } catch (e) {
      console.error('Error refresh riwayat:', e);
    } finally {
      setRefreshing(false);
    }
  };

  // Jika ada ticketToShow dari App, tampilkan modal otomatis
  useEffect(() => {
    if (ticketToShow) {
      setTicketData(ticketToShow);
      setShowModal(true);
    }
  }, [ticketToShow]);

  const fetchTicketDetail = async (noregistrasi) => {
    setLoadingTicket(true);
    try {
      const res = await fetch(`${API_BASE}?action=get_ticket_detail&noregistrasi=${encodeURIComponent(noregistrasi)}`, {
        credentials: 'include'
      });
      const json = await res.json();
      if (json.success) {
        setTicketData(json.data);
        setShowModal(true);
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Gagal',
          text: json.error || 'Gagal mengambil detail tiket',
          confirmButtonColor: '#2e7d32',
        });
      }
    } catch (e) {
      console.error(e);
      Swal.fire({
        icon: 'error',
        title: 'Koneksi Error',
        text: 'Terjadi kesalahan saat mengambil data',
        confirmButtonColor: '#2e7d32',
      });
    } finally {
      setLoadingTicket(false);
    }
  };

  const closeModal = () => {
    setShowModal(false);
    setTicketData(null);
    if (clearTicket) clearTicket();
  };

  // ═══════════════════ CHECK-IN (Scan QR dari barcode.php) ═══════════════════

  const stopScanner = useCallback(async () => {
    const s = scannerRef.current;
    scannerRef.current = null;
    if (s) {
      try { if (s.isScanning) await s.stop(); } catch { /* abaikan */ }
      try { s.clear(); } catch { /* abaikan */ }
    }
  }, []);

  const startScanner = useCallback(async () => {
    if (scannerRef.current) return; // sudah jalan
    setScannerError('');
    try {
      const scanner = new Html5Qrcode('rsud-checkin-scanner', {
        formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
        verbose: false,
      });
      scannerRef.current = scanner;
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 220, height: 220 } },
        async (decodedText) => {
          // Scan berhasil → hentikan kamera lalu proses check-in
          await stopScanner();
          if (performCheckinRef.current) {
            await performCheckinRef.current(decodedText);
          }
        },
        () => { /* error per-frame diabaikan */ }
      );
    } catch (e) {
      console.error('Scanner start error:', e);
      setScannerError('Tidak dapat mengakses kamera. Periksa izin kamera, atau gunakan kode manual di bawah.');
    }
  }, [stopScanner]);

  useEffect(() => {
    if (showScanner && currentCheckinReg) {
      startScanner();
    }
    return () => { stopScanner(); };
  }, [showScanner, currentCheckinReg, startScanner, stopScanner]);

  const handleCheckinClick = (reg) => {
    if (!reg.noregistrasi) return;
    setCurrentCheckinReg(reg);
    setScannerError('');
    setManualBarcode('');
    setShowScanner(true);
  };

  const closeScannerModal = () => {
    stopScanner();
    setShowScanner(false);
    setCurrentCheckinReg(null);
    setManualBarcode('');
    setProcessingCheckin(false);
  };

  const performCheckin = async (barcode) => {
    if (!currentCheckinReg || !barcode || processingCheckin) return;
    setProcessingCheckin(true);
    try {
      const res = await fetch(`${API_BASE}?action=checkin`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          noregistrasi: currentCheckinReg.noregistrasi,
          barcode: barcode.trim()
        })
      });
      const json = await res.json();

      if (json.success) {
        const noAntrian = json.data?.noantrian;
        await Swal.fire({
          icon: 'success',
          title: 'Check-in Berhasil!',
          html: `
            <div style="text-align: center;">
              <div style="font-size: 48px; color: #2e7d32; margin-bottom: 8px;">
                <i class="fas fa-check-circle"></i>
              </div>
              <p style="font-size: 15px; color: #334155; margin-bottom: 4px;">
                Status: <strong>Telah Check-in</strong>
              </p>
              ${noAntrian ? `<p style="font-size: 22px; font-weight: 800; color: #166534; margin: 8px 0;">${noAntrian}</p>
              <p style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Nomor Antrian</p>` : ''}
              <p style="font-size: 13px; color: #64748b;">
                Tindakan registrasi Rp 75.000 telah ditambahkan.
              </p>
            </div>
          `,
          confirmButtonColor: '#2e7d32',
          confirmButtonText: '<i class="fas fa-check"></i> Tutup',
          customClass: {
            popup: 'rounded-2xl',
            confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
          },
        });
        closeScannerModal();
        if (onRefresh) onRefresh(); // segarkan status di daftar riwayat
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Check-in Gagal',
          text: json.error || 'Gagal melakukan check-in',
          confirmButtonColor: '#dc2626',
        });
        startScanner(); // nyalakan kamera lagi untuk coba scan ulang
      }
    } catch (e) {
      console.error('Error check-in:', e);
      await Swal.fire({
        icon: 'error',
        title: 'Koneksi Error',
        text: 'Terjadi kesalahan saat check-in. Silakan coba lagi.',
        confirmButtonColor: '#dc2626',
      });
      startScanner();
    } finally {
      setProcessingCheckin(false);
    }
  };
  // Selalu ikat performCheckin terbaru ke ref (agar callback scanner tidak stale)
  performCheckinRef.current = performCheckin;

  const submitManualBarcode = () => {
    const val = manualBarcode.trim();
    if (!val) {
      Swal.fire({
        icon: 'warning',
        title: 'Kode Kosong',
        text: 'Masukkan kode QR check-in terlebih dahulu.',
        confirmButtonColor: '#2e7d32',
      });
      return;
    }
    stopScanner();
    performCheckin(val);
  };

  const handleCancelReservation = async () => {
    if (!ticketData || !ticketData.noregistrasi) return;

    // ── Konfirmasi Pembatalan ──
    const confirmResult = await Swal.fire({
      title: 'Batalkan Reservasi?',
      html: `
        <div style="text-align: left; font-size: 14px;">
          <p style="margin-bottom: 8px;">Anda yakin ingin membatalkan reservasi kunjungan ini?</p>
          <table style="width: 100%; border-collapse: collapse;">
            <tr>
              <td style="padding: 4px 8px; color: #64748b; font-weight: 500;">No. Registrasi</td>
              <td style="padding: 4px 8px;">: <strong>${ticketData.noregistrasi}</strong></td>
            </tr>
            ${ticketData.poliklinik ? `
            <tr>
              <td style="padding: 4px 8px; color: #64748b; font-weight: 500;">Poliklinik</td>
              <td style="padding: 4px 8px;">: <strong>${ticketData.poliklinik}</strong></td>
            </tr>` : ''}
            ${ticketData.dokter ? `
            <tr>
              <td style="padding: 4px 8px; color: #64748b; font-weight: 500;">Dokter</td>
              <td style="padding: 4px 8px;">: <strong>${ticketData.dokter}</strong></td>
            </tr>` : ''}
          </table>
          <p style="margin-top: 12px; color: #dc2626; font-size: 13px;">
            <i class="fas fa-exclamation-triangle"></i> Tindakan ini tidak dapat dibatalkan.
          </p>
        </div>
      `,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: '<i class="fas fa-times"></i> Ya, Batalkan',
      cancelButtonText: '<i class="fas fa-arrow-left"></i> Kembali',
      reverseButtons: true,
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
        cancelButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
      },
    });

    if (!confirmResult.isConfirmed) return;

    setCancelling(true);
    try {
      const res = await fetch(`${API_BASE}?action=cancel_reservation`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ noregistrasi: ticketData.noregistrasi })
      });
      const json = await res.json();
      if (json.success) {
        closeModal();
        await Swal.fire({
          icon: 'success',
          title: 'Berhasil Dibatalkan!',
          html: `
            <div style="text-align: center;">
              <div style="font-size: 48px; color: #2e7d32; margin-bottom: 8px;">
                <i class="fas fa-check-circle"></i>
              </div>
              <p style="font-size: 15px; color: #334155; margin-bottom: 4px;">
                Reservasi <strong>${ticketData.noregistrasi}</strong> telah dibatalkan.
              </p>
              <p style="font-size: 13px; color: #64748b;">
                Silakan daftar ulang jika masih ingin berkunjung.
              </p>
            </div>
          `,
          confirmButtonColor: '#2e7d32',
          confirmButtonText: '<i class="fas fa-check"></i> Tutup',
          customClass: {
            popup: 'rounded-2xl',
            confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
          },
        });
        if (onRefresh) onRefresh(); // refresh data riwayat
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Gagal Membatalkan',
          text: json.error || 'Terjadi kesalahan, silakan coba lagi.',
          confirmButtonColor: '#2e7d32',
          confirmButtonText: 'Tutup',
          customClass: {
            popup: 'rounded-2xl',
            confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
          },
        });
      }
    } catch (e) {
      console.error(e);
      await Swal.fire({
        icon: 'error',
        title: 'Koneksi Error',
        text: 'Terjadi kesalahan koneksi. Silakan coba lagi.',
        confirmButtonColor: '#2e7d32',
        confirmButtonText: 'Tutup',
        customClass: {
          popup: 'rounded-2xl',
          confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
        },
      });
    } finally {
      setCancelling(false);
    }
  };

  // Tombol refresh mengambang (dipakai saat daftar kosong maupun terisi)
  const fabRefresh = (
    <button
      onClick={handleRefresh}
      disabled={refreshing}
      className="fab-refresh"
      aria-label="Muat ulang riwayat"
    >
      <i className={`fas ${refreshing ? 'fa-spinner fa-spin' : 'fa-sync-alt'}`}></i>
    </button>
  );

  // Cek apakah reservasi bisa dibatalkan (kunjungan aktif, tanggal >= hari ini dan belum pulang)
  const canCancel = (tglRegistrasi, tglPulang, status) => {
    if (status === 'Dibatalkan') return false; // sudah dibatalkan
    if (tglPulang) return false; // sudah selesai
    if (!tglRegistrasi) return false;
    const tgl = new Date(tglRegistrasi);
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    return tgl >= now;
  };

  // Tanggal pelayanan sudah tiba (hari ini atau sudah lewat) → bisa check-in
  const canCheckinDate = (tglRegistrasi) => {
    if (!tglRegistrasi) return false;
    const tgl = new Date(tglRegistrasi);
    tgl.setHours(0, 0, 0, 0);
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    return tgl <= now;
  };

  // Deduplikasi riwayat per nomor registrasi agar 1 kunjungan tidak pernah tampil ganda
  const uniqueData = useMemo(() => {
    if (!Array.isArray(data)) return [];
    const seen = new Set();
    return data.filter((item) => {
      const key = item?.noregistrasi || item?.norec;
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }, [data]);

  if (!uniqueData || uniqueData.length === 0) {
    return (
      <>
        <div className="card p-6 text-center text-gray-500">
          <i className="fas fa-history text-3xl text-gray-300 mb-2"></i>
          <p className="text-sm">Belum ada riwayat kunjungan.</p>
        </div>
        {fabRefresh}
      </>
    );
  }

  return (
    <>
      <div className="space-y-3 scroll-area stagger">
      {uniqueData.map((reg, idx) => {
        const tglMasuk = formatDate(reg.tglregistrasi);
        const tglPulang = reg.tglpulang ? formatDate(reg.tglpulang) : null;
        const status = reg.status || (reg.tglpulang ? 'Selesai' : 'Aktif');
        const isCheckin = !!reg.is_checkin;
        const isCancelled = status === 'Dibatalkan';
        const isActive = status === 'Aktif';
        const isRawatJalan = reg.jenis_rawat !== 'Rawat Inap'; // tombol hanya untuk rawat jalan
        const canCancelNow = isActive && canCancel(reg.tglregistrasi, reg.tglpulang, status);
        // Nama dokter hanya ditampilkan jika pasien belum check-in atau sudah pulang
        const showDokter = reg.namadokter && (!isCheckin || !!reg.tglpulang);
        // Tombol check-in: kunjungan aktif, rawat jalan, belum check-in & tanggal sudah tiba
        const canCheckin = isActive && isRawatJalan && !isCheckin && canCheckinDate(reg.tglregistrasi);
        return (
          <div key={reg.noregistrasi || reg.norec || idx} className={`card card-order p-4 ${isCancelled ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between">
              <div>
                <span className="font-medium text-gray-800">{tglMasuk}</span>
                <span className="text-xs text-gray-500 ml-2">#{reg.noregistrasi}</span>
              </div>
              <span className={`text-xs px-2 py-1 rounded-full ${
                !isRawatJalan
                  ? 'bg-purple-100 text-purple-700'
                  : isCancelled ? 'bg-gray-200 text-gray-600'
                  : isActive ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500'
              }`}>
                {!isRawatJalan ? 'Rawat Inap' : isCancelled ? 'Dibatalkan' : isActive ? 'Aktif' : 'Selesai'}
              </span>
            </div>
            <div className="mt-1 text-sm text-gray-600 flex flex-wrap items-center gap-x-2">
              <span><i className="far fa-calendar-alt mr-1"></i> Masuk: {new Date(reg.tglregistrasi).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'})}</span>
              {tglPulang ? (
                <>
                  <span className="mx-1">|</span>
                  <span><i className="far fa-calendar-check mr-1"></i> Pulang: {tglPulang}</span>
                </>
              ) : (
                <span className="ml-2 text-yellow-600 text-xs"><i className="fas fa-clock mr-1"></i> Masih dirawat</span>
              )}
            </div>
            {/* Status check-in */}
            {isActive && (
              <div className="mt-1.5">
                <span className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full ${
                  isCheckin ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
                }`}>
                  <i className={`fas ${isCheckin ? 'fa-check-circle' : 'fa-hourglass-half'} text-[10px]`}></i>
                  {isCheckin ? 'Telah Check-in' : 'Belum Check-in'}
                </span>
              </div>
            )}
            {/* Tampilkan Poli & Dokter */}
            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              {reg.namaruangan && (
                <span className="inline-flex items-center gap-1 text-green-700 font-medium">
                  <i className="fas fa-hospital-alt text-xs"></i> {reg.namaruangan}
                </span>
              )}
              {showDokter && (
                <span className="inline-flex items-center gap-1 text-blue-700">
                  <i className="fas fa-user-md text-xs"></i> {reg.namadokter}
                </span>
              )}
            </div>
            <div className="mt-2 flex gap-2 flex-wrap">
              {/* Lihat Bukti hanya untuk pasien RAWAT JALAN yang masih aktif */}
              {isRawatJalan && isActive && (
                <button
                  onClick={() => fetchTicketDetail(reg.noregistrasi)}
                  disabled={loadingTicket}
                  className="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1 rounded-full transition"
                >
                  <i className="fas fa-ticket-alt mr-1"></i> Lihat Bukti
                </button>
              )}
              {isRawatJalan && canCancelNow && (
                <button
                  onClick={() => fetchTicketDetail(reg.noregistrasi)} // buka modal dulu, lalu di modal ada tombol batalkan
                  className="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1 rounded-full transition"
                >
                  <i className="fas fa-times mr-1"></i> Batalkan Reservasi
                </button>
              )}
              {/* Tombol Check-in: scan QR Code dari loket admisi (barcode.php) */}
              {canCheckin && (
                <button
                  onClick={() => handleCheckinClick(reg)}
                  className="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-full transition shadow-sm"
                >
                  <i className="fas fa-qrcode mr-1"></i> Check-in
                </button>
              )}
            </div>
          </div>
        );
      })}
      </div>

      {fabRefresh}

      {/* Modal Ticket */}
      {showModal && ticketData && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-sheet" onClick={e => e.stopPropagation()}>
            <div className="text-center mb-4">
              {/* Kop surat: logo + nama rumah sakit */}
              <div className="ticket-kop mb-3">
                <img src="./logo.png" alt="Logo RSUD Malangbong" className="ticket-logo" />
                <div className="text-left">
                  <div className="ticket-kop-name">RSUD MALANGBONG</div>
                  <div className="ticket-kop-sub">Jl. Raya Malangbong – Garut, Jawa Barat</div>
                </div>
              </div>
              <h2 className="text-xl font-bold text-gray-800">BUKTI PENDAFTARAN KUNJUNGAN</h2>
              <p className="text-xs text-gray-500">Pasien Rawat Jalan - Pembayaran UMUM</p>
            </div>

            <div className="ticket-card mb-6">
              <div className="text-center pb-4 mb-4 border-b border-green-200">
                <span className="text-xs text-gray-500 uppercase tracking-wide">Nomor Antrian Anda</span>
                <div className="text-4xl font-extrabold text-green-700 my-1">
                  {ticketData.noantrian_full || '-'}
                </div>
                <div className="text-sm font-semibold text-gray-700">{ticketData.poliklinik || '-'}</div>
              </div>

              <div className="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <span className="text-xs text-gray-400 block">No. Registrasi</span>
                  <strong className="text-gray-800">{ticketData.noregistrasi}</strong>
                </div>
                <div>
                  <span className="text-xs text-gray-400 block">Tgl Kunjungan</span>
                  <strong className="text-green-700">{formatDate(ticketData.tglregistrasi)}</strong>
                </div>
                <div className="col-span-2">
                  <span className="text-xs text-gray-400 block">Dokter Pemeriksa</span>
                  <strong className="text-gray-800">{ticketData.dokter || '-'}</strong>
                </div>
              </div>
            </div>

            <div className="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 mb-6">
              <i className="fas fa-info-circle mr-1 text-amber-600"></i>
              <strong>Petunjuk Kunjungan:</strong> Harap datang 1 jam sebelum jam pelayanan poli dan tunjukkan bukti pendaftaran online ini / KTP kepada petugas admisi / satpam yang bertugas RSUD Malangbong.
            </div>

            <div className="flex gap-2 flex-wrap">
              {canCancel(ticketData.tglregistrasi, ticketData.tglpulang, ticketData.status) && (
                <button
                  type="button"
                  onClick={handleCancelReservation}
                  disabled={cancelling}
                  className="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl font-semibold text-sm transition"
                >
                  {cancelling ? 'Membatalkan...' : <><i className="fas fa-times mr-2"></i> Batalkan</>}
                </button>
              )}
              <button
                type="button"
                onClick={closeModal}
                className="flex-1 py-2.5 bg-green-700 hover:bg-green-800 text-white rounded-xl font-semibold text-sm transition"
              >
                <i className="fas fa-times mr-2"></i> Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Check-in — Scan QR Code (barcode.php loket admisi) */}
      {showScanner && currentCheckinReg && (
        <div className="modal-overlay" onClick={closeScannerModal}>
          <div className="modal-sheet" onClick={e => e.stopPropagation()}>
            <div className="text-center mb-4">
              <div className="ticket-kop mb-3">
                <img src="./logo.png" alt="Logo RSUD Malangbong" className="ticket-logo" />
                <div className="text-left">
                  <div className="ticket-kop-name">RSUD MALANGBONG</div>
                  <div className="ticket-kop-sub">Jl. Raya Malangbong – Garut, Jawa Barat</div>
                </div>
              </div>
              <h2 className="text-xl font-bold text-gray-800">CHECK-IN KUNJUNGAN</h2>
              <p className="text-xs text-gray-500">Scan QR Code di loket admisi (halaman barcode.php)</p>
            </div>

            <div className="bg-green-50 border border-green-200 rounded-xl p-3 mb-4">
              <div className="flex justify-between text-sm">
                <div>
                  <span className="text-xs text-gray-500 block">No. Registrasi</span>
                  <strong className="text-gray-800">{currentCheckinReg.noregistrasi}</strong>
                </div>
                <div>
                  <span className="text-xs text-gray-500 block">Poliklinik</span>
                  <strong className="text-gray-800">{currentCheckinReg.namaruangan || '-'}</strong>
                </div>
              </div>
            </div>

            {scannerError ? (
              <div className="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-700 mb-3 flex items-start gap-2">
                <i className="fas fa-exclamation-triangle mt-0.5"></i>
                <span>{scannerError}</span>
              </div>
            ) : (
              <>
                <div className="scanner-box">
                  <div id="rsud-checkin-scanner"></div>
                </div>
                <div className="scanner-hint">
                  <i className="fas fa-video"></i> Arahkan kamera ke QR Code check-in
                </div>
              </>
            )}

            {processingCheckin && (
              <div className="mt-3 flex items-center justify-center gap-2 text-sm text-green-700">
                <i className="fas fa-spinner fa-spin"></i> Memproses check-in...
              </div>
            )}

            <div className="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 mt-4">
              <i className="fas fa-info-circle mr-1 text-amber-600"></i>
              <strong>Perhatian:</strong> Check-in otomatis menambahkan tindakan <strong>registrasi Rp 75.000</strong> pada kunjungan ini.
            </div>

            {/* Input manual (fallback jika kamera tidak bisa dipakai) */}
            <div className="mt-4">
              <label className="block text-xs text-gray-500 mb-1 font-medium">Kode QR manual (fallback):</label>
              <div className="flex gap-2">
                <input
                  type="text"
                  value={manualBarcode}
                  onChange={e => setManualBarcode(e.target.value)}
                  placeholder="Ketik kode dari halaman barcode.php"
                  className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600"
                />
                <button
                  type="button"
                  onClick={submitManualBarcode}
                  disabled={processingCheckin}
                  className="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition"
                >
                  Kirim
                </button>
              </div>
            </div>

            <div className="flex gap-2 flex-wrap mt-4">
              <button
                type="button"
                onClick={closeScannerModal}
                className="flex-1 py-2.5 bg-gray-600 hover:bg-gray-700 text-white rounded-xl font-semibold text-sm transition"
              >
                <i className="fas fa-times mr-2"></i> Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
