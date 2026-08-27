import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { API_BASE } from '../constants/api';

// ─── Helper: Format tanggal ke label Indonesia ─────────────────────────────
const formatTanggalID = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
};

// ─── Helper: Cek apakah tanggal adalah hari ini ─────────────────────────────
const isToday = (dateStr) => {
  const d = new Date(dateStr);
  const now = new Date();
  return d.toDateString() === now.toDateString();
};

// ─── Helper: Format tanggal LOKAL YYYY-MM-DD (hindari offset UTC) ───────────
const getLocalDateStr = (d = new Date()) => {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

// ─── Skeleton Card ──────────────────────────────────────────────────────────
const SkeletonCard = () => (
  <div className="card p-4 flex items-start gap-3">
    <div className="skeleton w-11 h-11 rounded-full flex-shrink-0"></div>
    <div className="flex-1 space-y-2.5">
      <div className="skeleton h-4 w-3/4 rounded-md"></div>
      <div className="skeleton h-3 w-1/2 rounded-md"></div>
      <div className="skeleton h-2 w-full rounded-md"></div>
    </div>
  </div>
);

// ─── Doctor Schedule Card (minimalis) ───────────────────────────────────────
const JadwalCard = ({ jadwal, onDaftar }) => {
  const sisa = jadwal.sisa_quota ?? 0;
  const isFull = sisa <= 0;
  const today = isToday(jadwal.tanggal);

  return (
    <div className={`card p-4 anim-fade-up ${isFull ? 'opacity-75' : ''}`}>
      <div className="flex items-center gap-3">
        {/* Avatar */}
        <div className="flex-shrink-0 w-11 h-11 rounded-full bg-green-50 border border-green-200 flex items-center justify-center">
          <i className="fas fa-user-md text-green-600"></i>
        </div>

        {/* Content */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-2">
            <h3 className="font-bold text-gray-800 text-sm truncate">{jadwal.namadokter || '-'}</h3>
            {today && (
              <span className="flex-shrink-0 text-[10px] font-bold px-2 py-1 rounded-full bg-blue-50 text-blue-600">
                <i className="fas fa-circle text-[6px] mr-0.5 animate-pulse"></i> Hari Ini
              </span>
            )}
          </div>
          <p className="text-xs text-gray-500 truncate mt-0.5">{jadwal.namaruangan || '-'}</p>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-xs text-gray-600">
            <span className="inline-flex items-center gap-1">
              <i className="far fa-clock text-blue-500"></i>
              {jadwal.jammulai?.slice(0, 5)} - {jadwal.jamakhir?.slice(0, 5)}
            </span>
            <span className="inline-flex items-center gap-1">
              <i className="far fa-calendar text-purple-500"></i>
              {jadwal.hari || '-'}
            </span>
          </div>
        </div>
      </div>

      {/* Kuota + Aksi */}
      <div className="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
        <span className={`text-xs font-semibold ${isFull ? 'text-red-600' : 'text-green-600'}`}>
          <i className="fas fa-users mr-1"></i>
          {isFull ? 'Kuota Penuh' : `Sisa ${sisa} slot`}
        </span>
        {!isFull && onDaftar && (
          <button
            onClick={() => onDaftar(jadwal)}
            className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-lg transition"
          >
            <i className="fas fa-calendar-check mr-1"></i> Reservasi
          </button>
        )}
        {isFull && (
          <span className="text-[10px] font-semibold px-2 py-1 rounded-full bg-red-50 text-red-500">
            <i className="fas fa-ban mr-0.5"></i> Penuh
          </span>
        )}
      </div>
    </div>
  );
};

// ─── Main Component ──────────────────────────────────────────────────────────
export default function TabJadwal({ onDaftar, masterData }) {
  const todayStr = getLocalDateStr();

  const [selectedDate, setSelectedDate] = useState(todayStr);
  const [poliklinikId, setPoliklinikId] = useState(''); // wajib dipilih dulu
  const [poliklinikList, setPoliklinikList] = useState([]);
  const [jadwalList, setJadwalList] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  // Penanda urutan fetch — mencegah respons lama menimpa yang baru
  // saat pengguna cepat mengganti poliklinik/tanggal
  const fetchSeq = React.useRef(0);

  // ─── Load poliklinik list ──────────────────────────────────────────────────
  useEffect(() => {
    // Coba ambil dari masterData dulu, kalau tidak ada fetch dari API
    if (masterData?.poliklinik) {
      setPoliklinikList(masterData.poliklinik);
    } else {
      fetch(`${API_BASE}?action=get_poliklinik_list`)
        .then(res => res.json())
        .then(json => {
          if (json.success) setPoliklinikList(json.data || []);
        })
        .catch(e => console.error('Gagal load poliklinik:', e));
    }
  }, [masterData]);

  // ─── Fetch jadwal (hanya setelah poliklinik dipilih) ───────────────────────
  const fetchJadwal = useCallback(async () => {
    if (!poliklinikId) {
      setJadwalList([]);
      return;
    }
    const seq = ++fetchSeq.current;
    setLoading(true);
    setError(null);
    try {
      const url = `${API_BASE}?action=get_jadwal_dokter&mode=hari&tanggal=${encodeURIComponent(selectedDate)}&ruangan_id=${encodeURIComponent(poliklinikId)}`;
      const res = await fetch(url);
      const json = await res.json();

      // Abaikan jika sudah ada fetch yang lebih baru
      if (seq !== fetchSeq.current) return;

      if (json.success) {
        // Hanya tampilkan jadwal hari ini ke depan (filter defensif)
        setJadwalList((json.data || []).filter(j => (j.tanggal || '') >= todayStr));
      } else {
        setError(json.error || 'Gagal memuat jadwal');
      }
    } catch (e) {
      if (seq !== fetchSeq.current) return;
      console.error('Error fetch jadwal:', e);
      setError('Terjadi kesalahan koneksi');
    } finally {
      if (seq === fetchSeq.current) setLoading(false);
    }
  }, [selectedDate, poliklinikId, todayStr]);

  useEffect(() => {
    fetchJadwal();
  }, [fetchJadwal]);

  // ─── Date Navigation (hanya hari ini ke depan) ─────────────────────────────
  const changeDate = (delta) => {
    const d = new Date(selectedDate + 'T00:00:00');
    d.setDate(d.getDate() + delta);
    const ds = getLocalDateStr(d);
    // Jangan pernah pindah ke tanggal sebelum hari ini
    setSelectedDate(ds < todayStr ? todayStr : ds);
  };

  const goToToday = () => setSelectedDate(todayStr);

  const canGoPrev = selectedDate <= todayStr;

  // ─── Poliklinik terpilih (untuk judul) ─────────────────────────────────────
  const selectedPoli = useMemo(
    () => poliklinikList.find(p => String(p.id) === String(poliklinikId)),
    [poliklinikList, poliklinikId]
  );

  // ─── Render ─────────────────────────────────────────────────────────────────
  return (
    <div className="space-y-3">
      {/* ─── Header: Poliklinik (wajib) ─────────────────────────────────── */}
      <div className="card p-4 anim-fade-up">
        <h2 className="text-lg font-bold text-gray-800 flex items-center gap-2">
          <i className="fas fa-calendar-check text-green-600"></i>
          Reservasi Dokter
        </h2>
        <p className="text-xs text-gray-500 mt-0.5">
          Pilih poliklinik terlebih dahulu untuk melihat jadwal dokter.
        </p>

        {/* Keterangan singkat cara reservasi */}
        <div className="mt-3 bg-green-50 border border-green-200 rounded-xl p-3 text-xs text-green-800 flex items-start gap-2">
          <i className="fas fa-info-circle mt-0.5 flex-shrink-0"></i>
          <div>
            <strong>Petunjuk reservasi:</strong> pilih poliklinik, tentukan tanggal kunjungan, lalu pilih dokter yang tersedia. Kuota slot terbatas &amp; hanya bisa untuk hari ini ke depan.
          </div>
        </div>

        {/* Keterangan khusus pasien umum */}
        <div className="mt-2 bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 flex items-start gap-2">
          <i className="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
          <div>
            <strong>Khusus pasien umum (bayar sendiri).</strong> Layanan reservasi ini hanya untuk pasien umum / pembayaran mandiri, bukan untuk pasien BPJS.
          </div>
        </div>

        {/* Poliklinik Select */}
        <div className="mt-3">
          <label className="block text-[11px] font-semibold text-gray-500 mb-1 uppercase tracking-wide">
            1. Poliklinik <span className="text-red-500">*</span>
          </label>
          <select
            value={poliklinikId}
            onChange={(e) => {
              setPoliklinikId(e.target.value);
              setJadwalList([]); // kosongkan daftar lama saat ganti poliklinik
            }}
            className="w-full text-sm border border-gray-200 rounded-lg px-3 py-2.5 bg-white focus:outline-none focus:border-green-500"
          >
            <option value="">— Pilih Poliklinik —</option>
            {poliklinikList.map(p => (
              <option key={p.id} value={p.id}>{p.namaruangan}</option>
            ))}
          </select>
        </div>

        {/* Tanggal — muncul setelah poliklinik dipilih */}
        {poliklinikId && (
          <div className="mt-3 anim-fade">
            <label className="block text-[11px] font-semibold text-gray-500 mb-1 uppercase tracking-wide">
              2. Tanggal Kunjungan
            </label>
            <div className="flex items-center gap-2">
              <button
                onClick={() => changeDate(-1)}
                disabled={canGoPrev}
                className={`flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-lg transition ${
                  canGoPrev
                    ? 'bg-gray-50 text-gray-300 cursor-not-allowed'
                    : 'bg-gray-100 hover:bg-gray-200 text-gray-600'
                }`}
                title="Sebelumnya"
              >
                <i className="fas fa-chevron-left text-sm"></i>
              </button>

              <div className="flex-1 text-center">
                <input
                  type="date"
                  value={selectedDate}
                  min={todayStr}
                  onChange={(e) => setSelectedDate(e.target.value)}
                  className="w-full text-center text-sm font-semibold text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:border-green-500"
                />
              </div>

              <button
                onClick={() => changeDate(1)}
                className="flex-shrink-0 w-9 h-9 flex items-center justify-center bg-gray-100 hover:bg-gray-200 rounded-lg transition"
                title="Selanjutnya"
              >
                <i className="fas fa-chevron-right text-sm"></i>
              </button>
            </div>

            {selectedDate !== todayStr && (
              <div className="mt-2 text-center">
                <button
                  onClick={goToToday}
                  className="px-3 py-1.5 text-xs font-semibold bg-green-50 hover:bg-green-100 text-green-700 rounded-lg border border-green-200 transition"
                >
                  <i className="fas fa-dot-circle mr-1 text-[10px]"></i> Hari Ini
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* ─── Content Area ───────────────────────────────────────────────────── */}
      {!poliklinikId ? (
        // Belum pilih poliklinik
        <div className="card p-8 text-center anim-fade-up">
          <div className="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
            <i className="fas fa-hospital-alt text-2xl text-gray-400"></i>
          </div>
          <p className="text-sm font-medium text-gray-600">Pilih poliklinik terlebih dahulu</p>
          <p className="text-xs text-gray-400 mt-1">
            Silakan pilih poliklinik di atas untuk melihat jadwal dokter yang tersedia.
          </p>
        </div>
      ) : loading ? (
        <div className="space-y-3">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      ) : error ? (
        <div className="card p-6 text-center">
          <i className="fas fa-exclamation-triangle text-3xl text-red-300 mb-2"></i>
          <p className="text-sm text-red-600 font-medium">{error}</p>
          <button
            onClick={fetchJadwal}
            className="mt-3 px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-semibold rounded-lg border border-red-200 transition"
          >
            <i className="fas fa-redo mr-1"></i> Coba Lagi
          </button>
        </div>
      ) : jadwalList.length > 0 ? (
        <div className="space-y-2 stagger">
          {jadwalList.map((j, idx) => (
            <JadwalCard key={`${j.id}-${idx}`} jadwal={j} onDaftar={onDaftar} />
          ))}
        </div>
      ) : (
        // Tidak ada jadwal
        <div className="card p-8 text-center">
          <div className="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
            <i className="far fa-calendar-times text-2xl text-gray-400"></i>
          </div>
          <p className="text-sm font-medium text-gray-600">Tidak ada jadwal dokter</p>
          <p className="text-xs text-gray-400 mt-1">
            {selectedPoli ? selectedPoli.namaruangan : ''} · {formatTanggalID(selectedDate)} — coba pilih tanggal lain.
          </p>
        </div>
      )}

      {/* ─── Footer Info ────────────────────────────────────────────────────── */}
      {!loading && !error && poliklinikId && (
        <div className="text-center text-[10px] text-gray-400 pt-2 pb-1">
          <i className="fas fa-info-circle mr-1"></i>
          Jadwal dapat berubah sewaktu-waktu. Hubungi RSUD untuk konfirmasi.
        </div>
      )}
    </div>
  );
}
