import React, { useEffect, useRef, useState } from 'react';
import { API_BASE } from '../constants/api';

// URL halaman informasi — diambil dari base API yang sama (seperti api.js)
const INFO_URL = API_BASE.replace(/api\.php(\?.*)?$/, 'informasi.php');

/**
 * Tab "Informasi" — halaman awal aplikasi.
 * Memuat /tm/rsud/informasi.php secara penuh sebagai web (iframe),
 * agar informasi/pengumuman dari RSUD tampil langsung di sini.
 */
export default function TabInformasi() {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const [reloadKey, setReloadKey] = useState(0); // untuk remount iframe saat coba lagi
  const timerRef = useRef(null);

  // Jika halaman tidak termuat dalam 12 detik, tampilkan opsi fallback
  useEffect(() => {
    timerRef.current = setTimeout(() => {
      if (!loaded) setFailed(true);
    }, 12000);
    return () => clearTimeout(timerRef.current);
  }, [loaded, reloadKey]);

  const retry = () => {
    setFailed(false);
    setLoaded(false);
    setReloadKey(k => k + 1); // remount iframe + restart timer
  };

  return (
    <div className="space-y-3">
      {/* ─── Header ─────────────────────────────────────────────────── */}
      <div className="card p-4 anim-fade-up">
        <h2 className="text-lg font-bold text-gray-800 flex items-center gap-2">
          <i className="fas fa-info-circle text-green-600"></i>
          Informasi
        </h2>
        <p className="text-xs text-gray-500 mt-0.5">
          Informasi &amp; panduan pemakaian aplikasi RSUD Malangbong.
        </p>
      </div>

      {/* ─── Konten web (informasi.php) ─────────────────────────────── */}
      <div className="card overflow-hidden p-0">
        {!loaded && !failed && (
          <div className="flex flex-col items-center justify-center py-14 text-gray-400">
            <i className="fas fa-spinner fa-spin text-2xl text-green-600 mb-3"></i>
            <p className="text-xs">Memuat informasi...</p>
          </div>
        )}

        {failed && (
          <div className="flex flex-col items-center justify-center py-12 px-6 text-center">
            <div className="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center mb-3">
              <i className="fas fa-plug text-xl text-gray-400"></i>
            </div>
            <p className="text-sm font-semibold text-gray-600">Tidak dapat memuat informasi</p>
            <p className="text-xs text-gray-400 mt-1">
              Periksa koneksi internet Anda, lalu coba lagi.
            </p>
            <div className="flex gap-2 mt-4">
              <button
                onClick={retry}
                className="px-4 py-2 bg-gray-50 hover:bg-gray-100 text-gray-600 text-xs font-semibold rounded-lg border border-gray-200 transition"
              >
                <i className="fas fa-redo mr-1"></i> Coba Lagi
              </button>
            </div>
          </div>
        )}

        <iframe
          key={reloadKey}
          src={INFO_URL}
          title="Informasi RSUD Malangbong"
          className="w-full block"
          style={{ height: '74vh', border: 0, background: '#fff', display: failed ? 'none' : 'block' }}
          onLoad={() => { setLoaded(true); setFailed(false); }}
          onError={() => setFailed(true)}
        />
      </div>
    </div>
  );
}
