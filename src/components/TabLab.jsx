import React, { useState } from 'react';
import Swal from 'sweetalert2';
import { Capacitor } from '@capacitor/core';
import { Browser } from '@capacitor/browser';
import { Filesystem, Directory } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';
import { formatDate, TOKEN_USER, KD_PROFILE, TOKEN } from '../constants/api';

// Ubah blob PDF menjadi base64 agar bisa disimpan via Capacitor Filesystem
const blobToBase64 = (blob) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || '').split(',')[1] || '');
    reader.onerror = () => reject(reader.error || new Error('Gagal membaca file'));
    reader.readAsDataURL(blob);
  });

// URL cetak hasil lab — endpoint SIMRS cetakan-hasil-lab-manual, sama seperti
// hasil radiologi (cetak-ekspertise-manual). Parameternya dibangun dinamis dari
// data order: noregistrasi, norec_apd, product (id produk dipisah koma),
// norec_pp, norec_so, user, kdprofile & token.
const buildCetakUrl = (order) => {
  const params = new URLSearchParams();
  params.set('noregistrasi', order.noregistrasi || '');
  params.set('norec_apd', order.norec_apd || '');
  params.set('product', (order.produk || []).map(p => p.produk_id).join(','));
  params.set('norec_pp', (order.produk || [])[0]?.norec_pp || '');
  params.set('norec_so', order.norec_so || '');
  params.set('user', TOKEN_USER);
  params.set('kdprofile', String(KD_PROFILE));
  params.set('token', TOKEN);
  return `https://rsudmalangbong.com/service/laboratorium/cetakan-hasil-lab-manual?${params.toString()}`;
};

export default function TabLab({ orders }) {
  const [downloading, setDownloading] = useState(null); // norec_so yang sedang diunduh

  const handleOpenHasil = async (order) => {
    const url = buildCetakUrl(order);
    const fileName = `hasil-lab-${order.norec_so || order.noregistrasi}.pdf`;

    // Web: unduh/buka PDF lewat browser (perilaku standar)
    if (!Capacitor.isNativePlatform()) {
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      a.remove();
      return;
    }

    // Android (Capacitor): unduh PDF ke penyimpanan aplikasi, lalu tawarkan buka
    if (downloading) return; // cegah unduhan ganda bersamaan
    setDownloading(order.norec_so || order.noregistrasi);
    try {
      Swal.fire({
        title: 'Mengunduh PDF...',
        text: 'Mohon tunggu sebentar.',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
      });
      const res = await fetch(url);
      if (!res.ok) throw new Error('Server menolak (HTTP ' + res.status + ')');
      const blob = await res.blob();
      // Pastikan benar-benar PDF (server bisa membalas halaman HTML error)
      const head = await blob.slice(0, 5).text();
      const isPdf = head === '%PDF-' || (res.headers.get('content-type') || '').includes('application/pdf');
      if (!isPdf) throw new Error('Respons bukan file PDF');
      const base64 = await blobToBase64(blob);
      const saved = await Filesystem.writeFile({
        path: fileName,
        data: base64,
        directory: Directory.Documents,
      });
      const openPdf = async () => {
        try {
          await Share.share({
            title: 'Hasil Laboratorium',
            text: 'Buka PDF hasil laboratorium',
            url: saved.uri,
            dialogTitle: 'Buka PDF dengan',
          });
        } catch { /* pengguna menutup dialog */ }
      };
      const r = await Swal.fire({
        icon: 'success',
        title: 'PDF Berhasil Diunduh',
        text: 'Pilih aplikasi untuk membuka file hasil laboratorium Anda.',
        confirmButtonText: '<i class="fas fa-file-pdf"></i> Buka PDF',
        cancelButtonText: 'Tutup',
        showCancelButton: true,
        confirmButtonColor: '#2e7d32',
        customClass: {
          popup: 'rounded-2xl',
          confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
          cancelButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
        },
      });
      if (r.isConfirmed) openPdf();
    } catch (err) {
      console.error('Gagal mengunduh PDF:', err);
      Swal.fire({
        icon: 'warning',
        title: 'Gagal Mengunduh PDF',
        text: 'Membuka file melalui browser...',
        showConfirmButton: false,
        timer: 1200,
      });
      try {
        await Browser.open({ url }); // fallback: buka di browser sistem
      } catch { /* abaikan */ }
    } finally {
      setDownloading(null);
    }
  };

  if (!orders || orders.length === 0) {
    return (
      <div className="card p-6 text-center text-gray-500">
        <i className="fas fa-flask text-3xl text-gray-300 mb-2"></i>
        <p className="text-sm">Belum ada pemeriksaan laboratorium yang selesai.</p>
      </div>
    );
  }

  const statusMap = {
    1: { label: 'Verifikasi', class: 'badge-verifikasi' },
    2: { label: 'Selesai', class: 'badge-selesai' }
  };

  return (
    <div className="space-y-3 scroll-area stagger">
      {orders.map((order, idx) => {
        const st = statusMap[order.statusorder] || { label: 'Selesai', class: 'badge-selesai' };
        const tgl = formatDate(order.tglorder);
        return (
          <div key={idx} className="card card-order p-4">
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-2">
                <span className="badge-status badge-lab"><i className="fas fa-flask mr-1"></i> Lab</span>
                <span className="text-xs font-medium text-gray-700"><i className="far fa-calendar-alt mr-1"></i> {tgl}</span>
              </div>
              <span className={`badge-status ${st.class}`}>{st.label}</span>
            </div>
            <div className="mt-2 text-sm text-gray-600">
              <i className="fas fa-user-md w-5 text-gray-400"></i> Dokter: {order.dokter_order || '-'}
            </div>
            {order.ruangan_asal && order.ruangan_asal !== '-' && (
              <div className="mt-1 text-sm text-gray-600">
                <i className="fas fa-hospital-alt w-5 text-gray-400"></i> Asal Ruangan: {order.ruangan_asal}
              </div>
            )}
            {order.produk && order.produk.length > 0 && (
              <div className="mt-2 pt-2 border-t border-gray-100">
                <ul className="mt-1 space-y-1">
                  {order.produk.map((p, i) => (
                    <li key={i} className="text-sm flex items-center gap-2">
                      <i className="fas fa-circle text-[6px] text-gray-400"></i>
                      <span className="flex-1">{p.namaproduk}</span>
                      {p.satuan && (
                        <span className="text-[10px] font-semibold bg-green-50 text-green-700 px-2 py-0.5 rounded-full shrink-0">
                          {p.satuan}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {order.produk && order.produk.length > 0 && (
              <div className="mt-3 w-full">
                <button
                  onClick={() => handleOpenHasil(order)}
                  disabled={downloading === (order.norec_so || order.noregistrasi)}
                  className="btn-outline btn-green w-full"
                >
                  {downloading === (order.norec_so || order.noregistrasi) ? (
                    <><i className="fas fa-spinner fa-spin"></i> Mengunduh...</>
                  ) : (
                    <><i className="fas fa-file-medical-alt"></i> Hasil</>
                  )}
                </button>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
