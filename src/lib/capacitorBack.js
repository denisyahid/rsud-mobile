// Integrasi tombol Back Android agar mengikuti navigasi React.
// Hanya aktif saat dijalankan di dalam aplikasi Capacitor (native);
// di browser/web dev modul ini tidak melakukan apa-apa.
import { App } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import Swal from 'sweetalert2';

// Stack handler: komponen React mendaftarkan handler sesuai layar aktif.
// Handler teratas (terakhir didaftarkan) yang dipanggil lebih dulu.
const handlers = [];

export function registerBackHandler(handler) {
  handlers.push(handler);
  return () => {
    const idx = handlers.indexOf(handler);
    if (idx >= 0) handlers.splice(idx, 1);
  };
}

function dispatchBack() {
  // 1) Jika dialog SweetAlert terbuka, tutup dialog dulu
  if (Swal.isVisible()) {
    Swal.close();
    return;
  }
  // 2) Serahkan ke handler React teratas (layar aktif)
  const handler = handlers[handlers.length - 1];
  if (handler) {
    const handled = handler();
    if (handled) return;
  }
  // 3) Tidak ada yang menangani → keluar aplikasi
  if (Capacitor.isNativePlatform()) {
    App.exitApp();
  }
}

export function initCapacitorBack() {
  if (!Capacitor.isNativePlatform()) return; // web dev: tidak ada tombol back native
  App.addListener('backButton', (ev) => {
    // Navigasi history WebView tidak dipakai aplikasi (navigasi berbasis state)
    ev.canGoBack = false;
    dispatchBack();
  });
}
