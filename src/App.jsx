import React, { useState, useEffect, useCallback, useRef } from 'react';
import Swal from 'sweetalert2';
import Login from './components/Login';
import ProfileCard from './components/ProfileCard';
import TabHasil from './components/TabHasil';
import TabInformasi from './components/TabInformasi';
import TabRiwayat from './components/TabRiwayat';
import TabDaftar from './components/TabDaftar';
import TabJadwal from './components/TabJadwal';
import BottomNav from './components/BottomNav';
import SplashScreen from './components/SplashScreen';
import { API_BASE } from './constants/api';
import { registerBackHandler } from './lib/capacitorBack';

// Banner kecil saat koneksi internet terputus
function OfflineBanner() {
  return (
    <div className="offline-banner anim-fade" role="alert">
      <span className="offline-icon">
        <i className="fas fa-wifi"></i>
        <i className="fas fa-slash"></i>
      </span>
      <div>
        <p className="offline-title">Koneksi internet terputus</p>
        <p className="offline-sub">Periksa koneksi Anda lalu coba lagi.</p>
      </div>
    </div>
  );
}

// Memastikan satu nomor registrasi/kunjungan hanya muncul 1 kali (anti duplikasi tampilan)
function dedupeRiwayat(list) {
  if (!Array.isArray(list)) return [];
  const seen = new Set();
  return list.filter((item) => {
    const key = item?.noregistrasi || item?.norec;
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

export default function App() {
  const [bootState, setBootState] = useState('loading'); // 'loading' | 'ready'
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [showRegistration, setShowRegistration] = useState(false);
  const [isExistingMode, setIsExistingMode] = useState(false);
  const [profile, setProfile] = useState(null);
  const [labOrders, setLabOrders] = useState([]);
  const [radOrders, setRadOrders] = useState([]);
  const [riwayat, setRiwayat] = useState([]);
  const [masterData, setMasterData] = useState(null);
  const [activeTab, setActiveTab] = useState('informasi'); // halaman awal = Informasi
  // Jadwal terpilih dari tab Jadwal untuk prefill form pendaftaran
  const [selectedJadwal, setSelectedJadwal] = useState(null);
  const [loading, setLoading] = useState(false);
  const [loginError, setLoginError] = useState('');
  // Status koneksi internet (untuk banner offline)
  const [isOnline, setIsOnline] = useState(() => (typeof navigator !== 'undefined' ? navigator.onLine : true));

  // State untuk menampilkan ticket di riwayat
  const [ticketToShow, setTicketToShow] = useState(null);

  const apiCall = useCallback(async (params, method = 'GET', body = null) => {
    const url = new URL(API_BASE);
    Object.keys(params).forEach(key => url.searchParams.append(key, params[key]));
    const options = {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
    };
    if (body) options.body = JSON.stringify(body);
    // Timeout agar tidak menggantung saat koneksi bermasalah/offline
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    options.signal = controller.signal;
    try {
      const response = await fetch(url.toString(), options);
      return response.json();
    } finally {
      clearTimeout(timer);
    }
  }, []);

  const loadDashboardData = useCallback(async () => {
    try {
      // Dijalankan paralel agar siklus refresh cepat (penting untuk polling 5 detik)
      const [profileResult, ordersResult, riwayatResult] = await Promise.all([
        apiCall({ action: 'get_profile' }),
        apiCall({ action: 'get_orders' }),
        apiCall({ action: 'get_riwayat' }),
      ]);
      if (profileResult.success) setProfile(profileResult.data);
      if (ordersResult.success) {
        setLabOrders(ordersResult.lab || []);
        setRadOrders(ordersResult.rad || []);
      }
      if (riwayatResult.success) setRiwayat(dedupeRiwayat(riwayatResult.data));
    } catch (e) {
      console.error('Error loading dashboard:', e);
    }
  }, [apiCall]);

  // Fungsi refresh khusus untuk riwayat (dipanggil setelah pembatalan)
  const refreshRiwayat = useCallback(async () => {
    try {
      const riwayatResult = await apiCall({ action: 'get_riwayat' });
      if (riwayatResult.success) setRiwayat(dedupeRiwayat(riwayatResult.data));
    } catch (e) {
      console.error('Error refreshing riwayat:', e);
    }
  }, [apiCall]);

  const loadMasterData = useCallback(async () => {
    if (masterData) return;
    try {
      const result = await apiCall({ action: 'get_masters' });
      if (result.success) setMasterData(result.data);
    } catch (e) {
      console.error('Error loading master data:', e);
    }
  }, [apiCall, masterData]);

  // ─── Auto-refresh data dari API setiap 5 detik ──────────────────────────────
  // Agar profil, hasil lab/radiologi & riwayat selalu terbaru tanpa perlu
  // memuat ulang manual saat sudah login (mis. hasil lab baru langsung muncul).
  const pollingRef = useRef(false);

  useEffect(() => {
    if (!isLoggedIn) return;
    const timer = setInterval(() => {
      // Cegah tumpang tindih permintaan bila respons API lebih lambat dari 5 detik
      if (pollingRef.current) return;
      pollingRef.current = true;
      loadDashboardData().finally(() => { pollingRef.current = false; });
    }, 5000);
    return () => clearInterval(timer);
  }, [isLoggedIn, loadDashboardData]);

  const handleLogin = useCallback(async (identifier) => {
    setLoading(true);
    setLoginError('');
    try {
      const result = await apiCall({ action: 'login' }, 'POST', { identifier });
      if (result.success) {
        setIsLoggedIn(true);
        setShowRegistration(false);
        setIsExistingMode(false);
        await loadDashboardData();
      } else {
        setLoginError(result.error || 'Login gagal.');
      }
    } catch {
      setLoginError('Terjadi kesalahan koneksi.');
    } finally {
      setLoading(false);
    }
  }, [apiCall, loadDashboardData]);

  const handleLogout = useCallback(async () => {
    // Konfirmasi logout ala Android (SweetAlert)
    const { isConfirmed } = await Swal.fire({
      title: 'Keluar dari Akun?',
      text: 'Anda yakin ingin keluar dari akun pasien ini?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Ya, Keluar',
      cancelButtonText: 'Batal',
      reverseButtons: true,
      customClass: {
        popup: 'rounded-2xl',
        confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
        cancelButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm',
      },
    });
    if (!isConfirmed) return;
    try {
      await apiCall({ action: 'logout' });
    } catch {}
    setIsLoggedIn(false);
    setProfile(null);
    setLabOrders([]);
    setRadOrders([]);
    setRiwayat([]);
    setMasterData(null);
    setShowRegistration(false);
    setIsExistingMode(false);
    setTicketToShow(null);
  }, [apiCall]);

  const handleRegister = useCallback(async (formData) => {
    setLoading(true);
    try {
      const result = await apiCall({ action: 'daftar_online' }, 'POST', formData);
      if (result.success && result.data) {
        // Siapkan data ticket untuk ditampilkan di riwayat
        const ticket = {
          noregistrasi: result.data.noregistrasi,
          tglregistrasi: result.data.tgl_kunjungan,
          poliklinik: result.data.poliklinik,
          dokter: result.data.dokter,
          noantrian_full: result.data.noantrian,
        };
        setTicketToShow(ticket);
        setActiveTab('riwayat'); // langsung pindah ke tab riwayat
        // Segarkan data riwayat & hasil langsung, sehingga pendaftaran baru
        // (pasien lama) langsung muncul tanpa menunggu polling 5 detik.
        // Untuk pasien baru, data disegarkan lewat handleAutoLogin.
        if (isLoggedIn) await loadDashboardData();
        return result;
      } else {
        return result; // pesan error ditampilkan oleh TabDaftar (SweetAlert)
      }
    } catch (err) {
      console.error(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, [apiCall, loadDashboardData, isLoggedIn]);

  const handleAutoLogin = useCallback(async (nocm) => {
    try {
      const result = await apiCall({ action: 'login' }, 'POST', { identifier: nocm });
      if (result.success) {
        setIsLoggedIn(true);
        setShowRegistration(false);
        setIsExistingMode(false);
        await loadDashboardData();
      } else {
        Swal.fire({
          icon: 'warning',
          title: 'Auto-login Gagal',
          text: 'Pendaftaran berhasil, tetapi login otomatis gagal. Silakan login manual.',
          confirmButtonColor: '#2e7d32',
          customClass: { popup: 'rounded-2xl', confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm' },
        });
      }
    } catch (e) {
      console.error('Auto-login error:', e);
    }
  }, [apiCall, loadDashboardData]);

  const handleShowRegistration = useCallback(() => {
    setShowRegistration(true);
    setIsExistingMode(false);
    loadMasterData();
  }, [loadMasterData]);

  const handleDaftarUmum = useCallback((jadwal) => {
    setIsExistingMode(true);
    // Jika dipanggil dari tab Jadwal, simpan jadwal untuk mengisi form otomatis
    // (panggilan dari tombol profil mengirim event klik → diabaikan)
    const jw = jadwal && typeof jadwal === 'object' && jadwal.tanggal ? jadwal : null;
    setSelectedJadwal(jw);
    setActiveTab('daftar');
    loadMasterData();
  }, [loadMasterData]);

  const handleTabChange = useCallback((tab) => {
    // Getaran halus khas Android saat ganti tab
    if (navigator.vibrate) navigator.vibrate(8);
    setActiveTab(tab);
  }, []);

  const clearTicket = useCallback(() => {
    setTicketToShow(null);
  }, []);

  // Cek session (dengan splash screen minimal)
  useEffect(() => {
    let cancelled = false;
    const checkSession = async () => {
      try {
        // Jika perangkat sedang offline, langsung ke halaman login
        // tanpa menunggu timeout fetch (splash tidak menggantung)
        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
          if (cancelled) return;
          setIsLoggedIn(false);
          return;
        }
        const result = await apiCall({ action: 'get_profile' });
        if (cancelled) return;
        if (result.success) {
          setIsLoggedIn(true);
          setProfile(result.data);
          setIsExistingMode(false);
          await loadDashboardData();
        } else {
          setIsLoggedIn(false);
        }
      } catch {
        if (!cancelled) setIsLoggedIn(false);
      } finally {
        if (!cancelled) {
          // Pastikan splash tampil minimal sebentar agar terasa seperti aplikasi Android
          setTimeout(() => setBootState('ready'), 350);
        }
      }
    };
    checkSession();
    return () => { cancelled = true; };
  }, [apiCall, loadDashboardData]);

  useEffect(() => {
    if (isLoggedIn && activeTab === 'daftar') {
      loadMasterData();
    }
  }, [activeTab, isLoggedIn, loadMasterData]);

  // Tombol Back Android mengikuti navigasi React (didaftarkan ke Capacitor)
  useEffect(() => {
    return registerBackHandler(() => {
      if (bootState === 'loading') return true; // splash: abaikan
      if (showRegistration) {
        setShowRegistration(false); // kembali ke halaman login
        return true;
      }
      if (isLoggedIn) {
        if (ticketToShow) {
          clearTicket(); // tutup modal tiket
          return true;
        }
        if (activeTab !== 'informasi') {
          setActiveTab('informasi'); // kembali ke halaman awal (Informasi)
          if (navigator.vibrate) navigator.vibrate(8);
          return true;
        }
        return false; // sudah di halaman awal → izinkan keluar aplikasi
      }
      return false; // halaman login → izinkan keluar aplikasi
    });
  }, [bootState, showRegistration, isLoggedIn, ticketToShow, activeTab, clearTicket]);

  // Pantau status koneksi internet (banner offline)
  useEffect(() => {
    const goOnline = () => setIsOnline(true);
    const goOffline = () => setIsOnline(false);
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);
    return () => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
    };
  }, []);

  // Splash saat boot
  if (bootState === 'loading') {
    return (
      <>
        {!isOnline && <OfflineBanner />}
        <SplashScreen message="Memeriksa sesi Anda..." />
      </>
    );
  }

  // Render
  if (showRegistration) {
    return (
      <div className="app-shell">
        {!isOnline && <OfflineBanner />}
        <div className="app-content">
          <div className="anim-fade">
            <TabDaftar
              masterData={masterData}
              onRegister={handleRegister}
              onRegisterSuccess={handleAutoLogin}
              isExisting={false}
              profile={null}
            />
            <div className="mt-4 text-center">
              <button onClick={() => setShowRegistration(false)} className="link-back">
                <i className="fas fa-arrow-left"></i> Kembali ke Login
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (!isLoggedIn) {
    return (
      <div className="app-shell">
        {!isOnline && <OfflineBanner />}
        <Login
          onLogin={handleLogin}
          loading={loading}
          error={loginError}
          onRegisterClick={handleShowRegistration}
        />
      </div>
    );
  }

  return (
    <div className="app-shell">
      {!isOnline && <OfflineBanner />}
      <div className="app-content">
        <ProfileCard
          profile={profile}
          onLogout={handleLogout}
        />
        {/* Key=activeTab memicu animasi fade saat pindah tab */}
        <div key={activeTab} className="mt-4 anim-fade">
          {activeTab === 'informasi' && <TabInformasi />}
          {activeTab === 'hasil' && (
            <TabHasil labOrders={labOrders} radOrders={radOrders} />
          )}
          {activeTab === 'riwayat' && (
            <TabRiwayat
              data={riwayat}
              ticketToShow={ticketToShow}
              clearTicket={clearTicket}
              onRefresh={refreshRiwayat}
            />
          )}
          {activeTab === 'jadwal' && (
            <TabJadwal
              masterData={masterData}
              onDaftar={handleDaftarUmum}
            />
          )}
          {activeTab === 'daftar' && (
            <TabDaftar
              masterData={masterData}
              onRegister={handleRegister}
              onRegisterSuccess={handleAutoLogin}
              isExisting={isExistingMode}
              profile={profile}
              initialJadwal={selectedJadwal}
            />
          )}
        </div>
      </div>
      <BottomNav activeTab={activeTab} onTabChange={handleTabChange} />
    </div>
  );
}
