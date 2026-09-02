import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { formatDate, formatTime, parseDateLocal, API_BASE } from '../constants/api';
import Swal from 'sweetalert2';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

// ─── Helper: tanggal chip ala Android (hari + bulan singkat) ───────────────
const getDateChip = (dateStr) => {
  const d = parseDateLocal(dateStr);
  if (!d) return { day: '--', month: '---' };
  return {
    day: String(d.getDate()).padStart(2, '0'),
    month: d.toLocaleDateString('id-ID', { month: 'short' }).toUpperCase(),
  };
};

// ─── Warna tema status kunjungan (Material 3 green palette) ────────────────
const getStatusTheme = ({ isRawatJalan, isCancelled, isActive }) => {
  if (!isRawatJalan) return { label: 'Rawat Inap', bg: '#EDE9FE', fg: '#6D28D9' };
  if (isCancelled) return { label: 'Dibatalkan', bg: '#F3F4F6', fg: '#6B7280' };
  if (isActive) return { label: 'Aktif', bg: '#D8F5D5', fg: '#1B5E20' };
  return { label: 'Selesai', bg: '#E9F8E9', fg: '#2E7D32' };
};

// ─── Kartu Riwayat versi Android / Material Design 3 ───────────────────────
const RiwayatCard = ({
  reg,
  isCheckin,
  isCancelled,
  isActive,
  isRawatJalan,
  canCancelNow,
  canCheckin,
  showDokter,
  loadingTicket,
  onOpenTicket,
  onCancel,
  onCheckin,
}) => {
  const tglMasuk = formatDate(reg.tglregistrasi);
  const tglPulang = reg.tglpulang ? formatDate(reg.tglpulang) : null;
  const jamMasuk = formatTime(reg.tglregistrasi);
  const chip = getDateChip(reg.tglregistrasi);
  const theme = getStatusTheme({ isRawatJalan, isCancelled, isActive });

  return (
    <div className={`card card-order overflow-hidden ${isCancelled ? 'opacity-75' : ''}`}>
      <div className="p-4 pb-3">
        {/* ── Baris atas: chip tanggal + status ─────────────────────────── */}
        <div className="flex items-start gap-3">
          <div
            className="flex-shrink-0 w-14 h-14 rounded-2xl flex flex-col items-center justify-center shadow-sm"
            style={{ background: theme.bg, color: theme.fg }}
          >
            <span className="text-[9px] font-extrabold uppercase tracking-[0.6px] leading-none">
              {chip.month}
            </span>
            <span className="text-[20px] font-extrabold leading-none mt-0.5">
              {chip.day}
            </span>
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex items-start justify-between gap-2">
              <h3 className="text-[13.5px] font-extrabold text-gray-900 leading-snug">
                {tglMasuk}
              </h3>
              <span
                className="flex-shrink-0 text-[10px] font-bold px-2.5 py-1 rounded-full"
                style={{ background: theme.bg, color: theme.fg }}
              >
                {theme.label}
              </span>
            </div>
            <p className="text-[11px] text-gray-500 mt-0.5">
              <i className="fas fa-hashtag mr-1 text-[10px]"></i>
              No. Registrasi <span className="font-semibold text-gray-700">{reg.noregistrasi || reg.norec || '-'}</span>
            </p>
          </div>
        </div>

        {/* ── Detail poli / dokter / waktu ──────────────────────────────── */}
        <div className="mt-3 space-y-1.5 text-[12.5px]">
          {reg.namaruangan && (
            <div className="flex items-center gap-2 text-gray-700">
              <span className="flex-shrink-0 w-7 h-7 rounded-lg flex items-center justify-center"
                style={{ background: 'var(--m3-primary-container)', color: 'var(--m3-on-primary-container)' }}>
                <i className="fas fa-hospital-alt text-[11px]"></i>
              </span>
              <span className="font-semibold">{reg.namaruangan}</span>
            </div>
          )}
          {showDokter && (
            <div className="flex items-center gap-2 text-gray-600">
              <span className="flex-shrink-0 w-7 h-7 rounded-lg flex items-center justify-center bg-blue-50 text-blue-600">
                <i className="fas fa-user-md text-[11px]"></i>
              </span>
              <span>{reg.namadokter}</span>
            </div>
          )}
          {(jamMasuk || tglPulang) && (
            <div className="flex items-center gap-2 text-gray-500">
              <span className="flex-shrink-0 w-7 h-7 rounded-lg flex items-center justify-center bg-gray-100 text-gray-500">
                <i className="far fa-clock text-[11px]"></i>
              </span>
              <span>
                {jamMasuk && (
                  <>
                    Jam kunjungan: <strong className="text-gray-700">{jamMasuk}</strong>
                  </>
                )}
                {tglPulang && (
                  <span className="text-gray-400">
                    {' '}{jamMasuk ? '·' : ''} Pulang {tglPulang}
                  </span>
                )}
              </span>
            </div>
          )}
        </div>

        {/* ── Status check-in ───────────────────────────────────────────── */}
        {isActive && (
          <div className="mt-3">
            <span
              className={`inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full ${
                isCheckin ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
              }`}
            >
              <i className={`fas ${isCheckin ? 'fa-check-circle' : 'fa-hourglass-half'} text-[10px]`}></i>
              {isCheckin ? 'Telah Check-in' : 'Belum Check-in'}
            </span>
          </div>
        )}
      </div>

      {/* ── Aksi ─────────────────────────────────────────────────────────── */}
      {(isRawatJalan && isActive) || canCancelNow || canCheckin ? (
        <div className="px-3 pb-3 pt-0 flex gap-2 flex-wrap">
          {isRawatJalan && isActive && (
            <button
              onClick={onOpenTicket}
              disabled={loadingTicket}
              className="riwayat-action riwayat-action-blue"
            >
              <i className="fas fa-ticket-alt"></i> Lihat Bukti
            </button>
          )}
          {canCancelNow && (
            <button onClick={onCancel} className="riwayat-action riwayat-action-red">
              <i className="fas fa-times"></i> Batalkan
            </button>
          )}
          {canCheckin && (
            <button onClick={onCheckin} className="riwayat-action riwayat-action-green">
              <i className="fas fa-qrcode"></i> Check-in
            </button>
          )}
        </div>
      ) : null}
    </div>
  );
};

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

      // DEBUG SEMENTARA: tampilkan hasil verifikasi antrian dari backend di console
      if (json.debug) {
        console.log('[CHECKIN-DEBUG] branch:', json.debug.apd_branch,
          '| norec antrian:', json.debug.apd_norec,
          '| terverifikasi di antrianpasiendiperiksa_t:', json.debug.apd_verified,
          '| row:', json.debug.apd_row || null,
          '| error:', json.debug.apd_error || null);
      }

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
          // DEBUG SEMENTARA: perlihatkan detail antrian bila backend mengirim debug
          footer: json.debug
            ? `<small style="color:#64748b;">DEBUG: branch=${json.debug.apd_branch || '-'} · norec=${json.debug.apd_norec || json.debug.norec_pd || '-'} · verified=${json.debug.apd_verified ? 'YA' : 'TIDAK'}${json.debug.apd_error ? ' · ' + json.debug.apd_error : ''}</small>`
            : undefined,
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
    const tgl = parseDateLocal(tglRegistrasi);
    if (!tgl) return false;
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    tgl.setHours(0, 0, 0, 0);
    return tgl >= now;
  };

  // Tanggal pelayanan sudah tiba (hari ini atau sudah lewat) → bisa check-in
  const canCheckinDate = (tglRegistrasi) => {
    const tgl = parseDateLocal(tglRegistrasi);
    if (!tgl) return false;
    tgl.setHours(0, 0, 0, 0);
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    return tgl <= now;
  };

  // Deduplikasi riwayat per nomor registrasi agar 1 kunjungan tidak pernah tampil ganda,
  // lalu urutkan TERBARU DI PALING ATAS (tglregistrasi paling baru dulu).
  const uniqueData = useMemo(() => {
    if (!Array.isArray(data)) return [];
    const seen = new Set();
    const deduped = data.filter((item) => {
      const key = item?.noregistrasi || item?.norec;
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
    return deduped.sort((a, b) => {
      const ta = parseDateLocal(a?.tglregistrasi)?.getTime() || 0;
      const tb = parseDateLocal(b?.tglregistrasi)?.getTime() || 0;
      return tb - ta; // descending → terbaru di atas
    });
  }, [data]);

  if (!uniqueData || uniqueData.length === 0) {
    return (
      <>
        <div className="card p-8 text-center anim-fade-up">
          <div
            className="w-20 h-20 mx-auto rounded-3xl flex items-center justify-center"
            style={{ background: 'var(--m3-primary-container)', color: 'var(--m3-on-primary-container)' }}
          >
            <i className="fas fa-history text-3xl"></i>
          </div>
          <h3 className="mt-4 text-base font-extrabold text-gray-800">Belum Ada Riwayat Kunjungan</h3>
          <p className="text-xs text-gray-500 mt-1 leading-relaxed">
            Kunjungan yang sudah Anda lakukan atau yang sedang aktif akan muncul di sini.
          </p>
          <button
            onClick={handleRefresh}
            disabled={refreshing}
            className="mt-4 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-green-700 hover:bg-green-800 text-white text-xs font-bold rounded-xl transition"
          >
            <i className={`fas ${refreshing ? 'fa-spinner fa-spin' : 'fa-sync-alt'}`}></i>
            Muat Ulang
          </button>
        </div>
        {fabRefresh}
      </>
    );
  }

  const activeCount = uniqueData.filter((r) => (r.status || (r.tglpulang ? 'Selesai' : 'Aktif')) === 'Aktif').length;

  return (
    <>
      {/* ── Header ringkasan ala Android ─────────────────────────────── */}
      <div className="card p-4 mb-3 flex items-center gap-3 anim-fade-up">
        <div
          className="flex-shrink-0 w-11 h-11 rounded-2xl flex items-center justify-center"
          style={{ background: 'var(--m3-primary-container)', color: 'var(--m3-on-primary-container)' }}
        >
          <i className="fas fa-history"></i>
        </div>
        <div className="flex-1 min-w-0">
          <h2 className="text-[15px] font-extrabold text-gray-900 leading-tight">Riwayat Kunjungan</h2>
          <p className="text-[11px] text-gray-500 mt-0.5">
            <span className="font-bold text-gray-600">{uniqueData.length}</span> kunjungan
            {activeCount > 0 && (
              <span className="ml-1.5">· <span className="font-bold text-green-700">{activeCount} aktif</span></span>
            )}
          </p>
        </div>
        {refreshing && (
          <div
            className="flex-shrink-0 m3-spinner"
            style={{ width: 22, height: 22, borderWidth: 3 }}
          ></div>
        )}
      </div>

      {/* ── Daftar riwayat ───────────────────────────────────────────── */}
      <div className="space-y-3 scroll-area riwayat-scroll stagger">
        {uniqueData.map((reg, idx) => {
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
            <RiwayatCard
              key={reg.noregistrasi || reg.norec || idx}
              reg={reg}
              isCheckin={isCheckin}
              isCancelled={isCancelled}
              isActive={isActive}
              isRawatJalan={isRawatJalan}
              canCancelNow={canCancelNow}
              canCheckin={canCheckin}
              showDokter={showDokter}
              loadingTicket={loadingTicket}
              onOpenTicket={() => fetchTicketDetail(reg.noregistrasi)}
              onCancel={() => fetchTicketDetail(reg.noregistrasi)} // buka modal dulu, lalu di modal ada tombol batalkan
              onCheckin={() => handleCheckinClick(reg)}
            />
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
