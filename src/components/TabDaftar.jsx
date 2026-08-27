import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import Swal from 'sweetalert2';
import { API_BASE, NIK_VERIFY_URL } from '../constants/api';

export default function TabDaftar({
  masterData,
  onRegister,
  onRegisterSuccess,
  isExisting = false,
  profile = null,
  initialJadwal = null
}) {
  const tomorrowStr = new Date(Date.now() + 86400000).toISOString().split('T')[0];

  const [form, setForm] = useState({
    nik: '',
    namapasien: profile ? profile.namapasien : '',
    tempatlahir: '',
    tgllahir: '',
    jeniskelamin: '2',
    nohp: '',
    agama: '1',
    kebangsaan: '1',
    negara: '0',
    tgl_kunjungan: tomorrowStr,
    ruangan_id: '',
    dokter_id: '',
    alamat: '',
    rtrw: '',
    desakelurahan_id: '',
    kecamatan_id: '',
    kotakabupaten_id: '',
    provinsi_id: '',
    kodepos: '',
    desaQuery: '',
    kecamatanName: '',
    kotaName: '',
    provinsiName: '',
    penanggung_sama: false,
    nama_penanggung: '',
    hubungan_penanggung: '1',
    telp_penanggung: '',
    jenis_kelamin_penanggung: '2',
    alamat_penanggung: '',
    nama_ibu: '',
    email: '',
    status_perkawinan: '1',
    goldar: '',
    pendidikan: '3',
    pekerjaan: '4',
    etnis: '2',
    nama_ayah: '',
    nama_suami_istri: ''
  });

  const [desaSuggestions, setDesaSuggestions] = useState([]);
  const [showDesaList, setShowDesaList]       = useState(false);
  const [loadingDesa, setLoadingDesa]         = useState(false);
  const [nikStatus, setNikStatus]             = useState(null);
  const [loadingNik, setLoadingNik]           = useState(false);
  const [submitting, setSubmitting]           = useState(false);
  const [regResult, setRegResult]             = useState(null);
  const [errors, setErrors]                   = useState({});
  const [nikValid, setNikValid]               = useState(false);
  const [showFullForm, setShowFullForm]       = useState(false);

  // ─── Prefill dari jadwal yang dipilih (tab Jadwal → tombol Daftar Kunjungan) ──
  // Isi apa pun yang tersedia: tanggal selalu, poli & dokter jika ada di data jadwal.
  useEffect(() => {
    if (!initialJadwal || !initialJadwal.tanggal) return;
    setForm(prev => ({
      ...prev,
      tgl_kunjungan: String(initialJadwal.tanggal).slice(0, 10),
      ruangan_id: initialJadwal.ruangan_id != null ? String(initialJadwal.ruangan_id) : prev.ruangan_id,
      dokter_id: initialJadwal.dokter_id != null ? String(initialJadwal.dokter_id) : prev.dokter_id,
    }));
  }, [initialJadwal]);

  // ─── Cek reservasi aktif (double reservation prevention) ──────────────────
  const [activeBooking, setActiveBooking]     = useState(null);
  const [loadingBooking, setLoadingBooking]   = useState(false);

  useEffect(() => {
    if (!isExisting || !profile) return;
    const checkBooking = async () => {
      setLoadingBooking(true);
      try {
        const res = await fetch(`${API_BASE}?action=check_active_booking&tanggal=${encodeURIComponent(form.tgl_kunjungan)}`, {
          credentials: 'include'
        });
        const json = await res.json();
        if (json.success && json.has_active_booking) {
          setActiveBooking({
            hasActive: true,
            message: json.message
          });
        } else {
          setActiveBooking(null);
        }
      } catch (e) {
        console.error('Gagal cek reservasi aktif:', e);
        setActiveBooking(null);
      } finally {
        setLoadingBooking(false);
      }
    };
    checkBooking();
  }, [isExisting, profile, form.tgl_kunjungan, API_BASE]);

  // Dokter berdasarkan jadwal
  const [dokterJadwal, setDokterJadwal]   = useState([]);
  const [loadingDokter, setLoadingDokter] = useState(false);
  const [jadwalStatus, setJadwalStatus]   = useState({ message: '', exists: null });

  // Penanda permintaan terakhir — untuk menolak respons fetch basi (race saat
  // user mengganti tanggal/poli sementara fetch dokter sedang berjalan)
  const lastReqRef = useRef(null);

  // ─── Destructure masterData dengan fallback aman ─────────────────────────
  const agama            = masterData?.agama            || [];
  const kebangsaan       = masterData?.kebangsaan       || [];
  const negara           = masterData?.negara           || [];
  const hubungan         = masterData?.hubungan         || [];
  const status_perkawinan= masterData?.status_perkawinan|| [];
  const goldar           = masterData?.goldar           || [];
  const pendidikan       = masterData?.pendidikan       || [];
  const pekerjaan        = masterData?.pekerjaan        || [];
  const etnis            = masterData?.etnis            || [];
  const poliklinik       = masterData?.poliklinik       || [];

  // Pastikan poli dari jadwal yang dipilih selalu ada di dropdown — jadwal bisa
  // menunjuk ruangan yang tidak masuk daftar poli master (departemen 18).
  const poliOptions = useMemo(() => {
    if (!initialJadwal?.ruangan_id || !initialJadwal.namaruangan) return poliklinik;
    const ruanganId = String(initialJadwal.ruangan_id);
    if (poliklinik.some(p => String(p.id) === ruanganId)) return poliklinik;
    return [
      { id: initialJadwal.ruangan_id, namaruangan: initialJadwal.namaruangan },
      ...poliklinik,
    ];
  }, [poliklinik, initialJadwal]);

  // ─── Ambil dokter sesuai tanggal + ruangan ───────────────────────────────
  const fetchDokterByJadwal = useCallback(async (tgl, ruangan) => {
    // Nilai prefill dari jadwal yang dipilih
    const prefillTgl     = initialJadwal?.tanggal ? String(initialJadwal.tanggal).slice(0, 10) : null;
    const prefillRuangan = initialJadwal?.ruangan_id != null ? String(initialJadwal.ruangan_id) : null;
    const prefillDokter  = initialJadwal?.dokter_id != null ? String(initialJadwal.dokter_id) : null;
    // Dokter hasil pilihan hanya dipertahankan selama tanggal+poli masih milik jadwal itu
    const keepPrefill = prefillTgl === tgl && prefillRuangan === ruangan;
    // Tandai permintaan terakhir (untuk menolak respons basi)
    lastReqRef.current = { tgl, ruangan };

    if (!tgl || !ruangan) {
      setDokterJadwal([]);
      if (!keepPrefill) setForm(prev => ({ ...prev, dokter_id: '' }));
      setJadwalStatus({ message: '', exists: null });
      return;
    }
    setLoadingDokter(true);
    setDokterJadwal([]);
    if (!keepPrefill) setForm(prev => ({ ...prev, dokter_id: '' }));
    setJadwalStatus({ message: '', exists: null });
    try {
      const res  = await fetch(
        `${API_BASE}?action=get_dokter_by_jadwal` +
        `&tanggal=${encodeURIComponent(tgl)}` +
        `&ruangan_id=${encodeURIComponent(ruangan)}`
      );
      const json = await res.json();
      // Respons basi — user sudah mengganti tanggal/poli saat fetch berjalan
      if (lastReqRef.current.tgl !== tgl || lastReqRef.current.ruangan !== ruangan) return;
      if (json.success && json.data.length > 0) {
        setDokterJadwal(json.data);
        // Terapkan dokter hasil prefill dari jadwal (jika dokter masih ada di daftar)
        if (keepPrefill && prefillDokter && json.data.some(d => String(d.id) === prefillDokter)) {
          setForm(prev => ({ ...prev, dokter_id: prefillDokter }));
        }
        setJadwalStatus({
          exists: true,
          message: `${json.data.length} dokter tersedia pada tanggal ini.`
        });
      } else {
        setDokterJadwal([]);
        setJadwalStatus({
          exists: false,
          message: 'Tidak ada jadwal dokter untuk tanggal & poliklinik yang dipilih.'
        });
      }
    } catch (e) {
      console.error('Fetch dokter jadwal gagal:', e);
      if (lastReqRef.current.tgl !== tgl || lastReqRef.current.ruangan !== ruangan) return;
      setJadwalStatus({ exists: null, message: 'Gagal memuat jadwal dokter.' });
    } finally {
      if (lastReqRef.current.tgl === tgl && lastReqRef.current.ruangan === ruangan) {
        setLoadingDokter(false);
      }
    }
  }, [initialJadwal]);

  // Trigger saat tanggal / ruangan berubah
  useEffect(() => {
    fetchDokterByJadwal(form.tgl_kunjungan, form.ruangan_id);
  }, [form.tgl_kunjungan, form.ruangan_id, fetchDokterByJadwal]);

  // Penanggung sama dengan pasien
  useEffect(() => {
    if (form.penanggung_sama) {
      setForm(prev => ({
        ...prev,
        nama_penanggung:          prev.namapasien,
        telp_penanggung:          prev.nohp,
        jenis_kelamin_penanggung: prev.jeniskelamin,
        alamat_penanggung:        prev.alamat
      }));
    }
  }, [form.penanggung_sama, form.namapasien, form.nohp, form.jeniskelamin, form.alamat]);

  // Auto cek NIK
  useEffect(() => {
    if (isExisting) return;
    const timer = setTimeout(() => {
      const nik = form.nik.trim();
      if (nik.length === 16) {
        checkNik(nik);
      } else if (nik.length > 0 && nik.length < 16) {
        setNikStatus({ exists: false, message: 'NIK harus 16 digit angka' });
        setNikValid(false);
        setShowFullForm(false);
      } else {
        setNikStatus(null);
        setNikValid(false);
        setShowFullForm(false);
      }
    }, 500);
    return () => clearTimeout(timer);
  }, [form.nik, isExisting]);

  // Sync profile
  useEffect(() => {
    if (isExisting && profile) {
      setForm(prev => ({ ...prev, namapasien: profile.namapasien || '' }));
    }
  }, [isExisting, profile]);

  // ─── Fungsi-fungsi ────────────────────────────────────────────────────────
  const checkNik = async (nik) => {
    if (nik.length !== 16) return;
    setLoadingNik(true);
    setNikStatus(null);
    try {
      // 1) Verifikasi format NIK ke API eksternal TERLEBIH DAHULU (dengan timeout 10 detik)
      try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 10000);
        let resExt, jsonExt;
        try {
          resExt  = await fetch(`${NIK_VERIFY_URL}?nik=${encodeURIComponent(nik)}`, { signal: ctrl.signal });
          jsonExt = await resExt.json();
        } finally {
          clearTimeout(timer);
        }
        const okExt = !!(jsonExt && jsonExt.ok === true);
        if (!okExt) {
          setNikStatus({
            exists: false,
            tone: 'error',
            message: 'NIK anda tidak sesuai. Periksa kembali NIK yang Anda masukkan.'
          });
          setNikValid(false);
          setShowFullForm(false);
          return;
        }
      } catch (e) {
        console.error('Gagal verifikasi NIK eksternal:', e);
        setNikStatus({
          exists: false,
          tone: 'error',
          message: 'Gagal memverifikasi NIK. Periksa koneksi internet Anda lalu coba lagi.'
        });
        setNikValid(false);
        setShowFullForm(false);
        return;
      }

      // 2) Baru cek NIK ke database RSUD
      const res  = await fetch(`${API_BASE}?action=search_nik&nik=${encodeURIComponent(nik)}`);
      const json = await res.json();
      if (json.success) {
        if (json.exists) {
          setNikStatus({
            exists: true,
            message: (json.message || 'NIK sudah terdaftar') + '. Mengalihkan Anda ke akun pasien...'
          });
          setNikValid(false);
          setShowFullForm(false);
          // NIK sudah terdaftar → otomatis login dengan No RM pasien tersebut
          if (json.data && json.data.nocm && onRegisterSuccess) {
            onRegisterSuccess(json.data.nocm);
          }
        } else {
          setNikStatus({
            exists: false,
            message: 'NIK belum terdaftar. Silakan lengkapi data di bawah.'
          });
          setNikValid(true);
          setShowFullForm(true);
        }
      } else {
        setNikStatus({ exists: false, message: json.error || 'Gagal cek NIK' });
      }
    } catch (e) {
      console.error('Error search NIK:', e);
      setNikStatus({ exists: false, message: 'Terjadi kesalahan saat cek NIK' });
    } finally {
      setLoadingNik(false);
    }
  };

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;
    setForm(prev => ({ ...prev, [name]: type === 'checkbox' ? checked : value }));
    if (errors[name]) setErrors(prev => ({ ...prev, [name]: '' }));
  };

  const handleDesaSearch = async (query) => {
    setForm(prev => ({ ...prev, desaQuery: query }));
    if (query.length < 2) {
      setDesaSuggestions([]);
      setShowDesaList(false);
      return;
    }
    setLoadingDesa(true);
    try {
      const res  = await fetch(`${API_BASE}?action=search_desa&q=${encodeURIComponent(query)}`);
      const json = await res.json();
      if (json.success) {
        setDesaSuggestions(json.data || []);
        setShowDesaList(true);
      }
    } catch (e) {
      console.error('Error search desa:', e);
    } finally {
      setLoadingDesa(false);
    }
  };

  const handleSelectDesa = (d) => {
    setForm(prev => ({
      ...prev,
      desaQuery:        d.namadesakelurahan,
      desakelurahan_id: d.id_dk,
      kecamatan_id:     d.objectkecamatanfk,
      kotakabupaten_id: d.objectkotakabupatenfk,
      provinsi_id:      d.objectpropinsifk,
      kecamatanName:    d.namakecamatan,
      kotaName:         d.namakotakabupaten,
      provinsiName:     d.namapropinsi,
      kodepos:          d.kodepos || prev.kodepos
    }));
    setShowDesaList(false);
  };

  const validateForm = () => {
    const newErrors = {};
    if (!isExisting) {
      if (!form.nik || form.nik.length !== 16)
        newErrors.nik = 'NIK harus 16 digit angka';
      else if (!nikValid)
        newErrors.nik = 'NIK belum divalidasi atau sudah terdaftar';
      if (!form.namapasien.trim())  newErrors.namapasien  = 'Nama pasien wajib diisi';
      if (!form.tempatlahir.trim()) newErrors.tempatlahir = 'Tempat lahir wajib diisi';
      if (!form.tgllahir)           newErrors.tgllahir    = 'Tanggal lahir wajib diisi';
      if (!form.jeniskelamin)       newErrors.jeniskelamin= 'Jenis kelamin wajib dipilih';
      if (!form.nohp.trim())        newErrors.nohp        = 'No HP wajib diisi';
      if (!form.alamat.trim())      newErrors.alamat      = 'Alamat wajib diisi';
      if (!form.nama_ibu.trim())    newErrors.nama_ibu    = 'Nama ibu kandung wajib diisi';
    }
    if (!form.ruangan_id)    newErrors.ruangan_id    = 'Poliklinik tujuan wajib dipilih';
    if (!form.dokter_id)     newErrors.dokter_id     = 'Dokter pemeriksa wajib dipilih';
    if (!form.tgl_kunjungan) newErrors.tgl_kunjungan = 'Tanggal kunjungan wajib diisi';

    if (jadwalStatus.exists === false)
      newErrors.jadwal = 'Tidak ada jadwal dokter tersedia untuk pilihan ini';

    if (form.dokter_id) {
      const sel = dokterJadwal.find(d => String(d.id) === String(form.dokter_id));
      if (sel && sel.sisa_quota <= 0)
        newErrors.dokter_id = `Kuota dokter ${sel.namalengkap} sudah penuh`;
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateForm()) {
      const firstError = document.querySelector('.input-error');
      if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    setSubmitting(true);
    try {
      let payload;
      if (isExisting) {
        payload = {
          ruangan_id:    form.ruangan_id,
          dokter_id:     form.dokter_id,
          tgl_kunjungan: form.tgl_kunjungan
        };
      } else {
        payload = { ...form };
        delete payload.desaQuery;
        delete payload.kecamatanName;
        delete payload.kotaName;
        delete payload.provinsiName;
      }
      const result = await onRegister(payload);
      if (result && result.success) {
        if (result.data) {
          setRegResult(result.data);
          if (!isExisting && result.data.nocm && onRegisterSuccess) {
            onRegisterSuccess(result.data.nocm);
          }
        } else {
          Swal.fire({
            icon: 'success',
            title: 'Pendaftaran Berhasil!',
            text: result.message || 'Data pendaftaran Anda telah diterima.',
            confirmButtonColor: '#43a047',
            customClass: { popup: 'rounded-2xl', confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm' },
          });
          setForm(prev => ({
            ...prev,
            ruangan_id: '', dokter_id: '', tgl_kunjungan: tomorrowStr
          }));
        }
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Pendaftaran Gagal',
          text: result?.error || 'Gagal mendaftar. Silakan coba lagi.',
          confirmButtonColor: '#2e7d32',
          customClass: { popup: 'rounded-2xl', confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm' },
        });
      }
    } catch (err) {
      console.error(err);
      Swal.fire({
        icon: 'error',
        title: 'Koneksi Error',
        text: 'Terjadi kesalahan. Silakan coba lagi.',
        confirmButtonColor: '#2e7d32',
        customClass: { popup: 'rounded-2xl', confirmButton: 'px-6 py-2.5 rounded-xl font-semibold text-sm' },
      });
    } finally {
      setSubmitting(false);
    }
  };

  // ─── Reset form setelah sukses ────────────────────────────────────────────
  const resetForm = () => {
    setRegResult(null);
    if (!isExisting) {
      setForm({
        nik: '', namapasien: '', tempatlahir: '', tgllahir: '',
        jeniskelamin: '2', nohp: '', agama: '1', kebangsaan: '1', negara: '0',
        tgl_kunjungan: tomorrowStr, ruangan_id: '', dokter_id: '',
        alamat: '', rtrw: '', desakelurahan_id: '', kecamatan_id: '',
        kotakabupaten_id: '', provinsi_id: '', kodepos: '',
        desaQuery: '', kecamatanName: '', kotaName: '', provinsiName: '',
        penanggung_sama: false, nama_penanggung: '', hubungan_penanggung: '1',
        telp_penanggung: '', jenis_kelamin_penanggung: '2', alamat_penanggung: '',
        nama_ibu: '', email: '', status_perkawinan: '1', goldar: '',
        pendidikan: '3', pekerjaan: '4', etnis: '2', nama_ayah: '', nama_suami_istri: ''
      });
      setNikStatus(null);
      setNikValid(false);
      setShowFullForm(false);
      setErrors({});
    } else {
      setForm(prev => ({
        ...prev, ruangan_id: '', dokter_id: '', tgl_kunjungan: tomorrowStr
      }));
    }
  };

  // ─── Helper: Blok jadwal (dipakai di dua tempat) ─────────────────────────
  const renderJadwalSection = () => (
    <>
      {/* Tanggal Kunjungan */}
      <div className="form-group">
        <label>
          Tanggal Rencana Kunjungan <span className="text-red-500">*</span>
        </label>
        <input
          type="date"
          name="tgl_kunjungan"
          value={form.tgl_kunjungan}
          min={new Date().toISOString().split('T')[0]}
          onChange={handleChange}
          className={errors.tgl_kunjungan ? 'border-red-500' : ''}
          required
        />
        {errors.tgl_kunjungan && (
          <div className="input-error">{errors.tgl_kunjungan}</div>
        )}
      </div>

      {/* Poliklinik */}
      <div className="form-group">
        <label>
          Poliklinik Tujuan <span className="text-red-500">*</span>
        </label>
        <select
          name="ruangan_id"
          value={form.ruangan_id}
          onChange={handleChange}
          className={errors.ruangan_id ? 'border-red-500' : ''}
          required
        >
          <option value="">-- Pilih Poliklinik --</option>
          {poliOptions.map(p => (
            <option key={p.id} value={p.id}>{p.namaruangan}</option>
          ))}
        </select>
        {errors.ruangan_id && (
          <div className="input-error">{errors.ruangan_id}</div>
        )}
      </div>

      {/* Status jadwal / loading */}
      {form.tgl_kunjungan && form.ruangan_id && (
        <div className={`p-3 rounded-xl text-xs font-medium mb-3 flex items-center gap-2 ${
          loadingDokter
            ? 'bg-gray-50 text-gray-500 border border-gray-200'
            : jadwalStatus.exists === true
              ? 'bg-green-50 text-green-700 border border-green-200'
              : jadwalStatus.exists === false
                ? 'bg-red-50 text-red-700 border border-red-200'
                : 'bg-gray-50 text-gray-500 border border-gray-200'
        }`}>
          {loadingDokter ? (
            <>
              <i className="fas fa-spinner fa-spin"></i>
              <span>Memuat jadwal dokter...</span>
            </>
          ) : (
            <>
              <i className={`fas ${
                jadwalStatus.exists === true  ? 'fa-check-circle text-green-600' :
                jadwalStatus.exists === false ? 'fa-times-circle text-red-600'   :
                'fa-info-circle text-gray-400'
              }`}></i>
              <span>{jadwalStatus.message}</span>
            </>
          )}
        </div>
      )}

      {/* Dropdown Dokter — hanya tampil jika ada jadwal */}
      {dokterJadwal.length > 0 && (
        <div className="form-group">
          <label>
            Dokter Pemeriksa <span className="text-red-500">*</span>
          </label>
          <select
            name="dokter_id"
            value={form.dokter_id}
            onChange={handleChange}
            className={errors.dokter_id ? 'border-red-500' : ''}
            required
          >
            <option value="">-- Pilih Dokter --</option>
            {dokterJadwal.map(d => (
              <option
                key={d.id}
                value={d.id}
                disabled={d.sisa_quota <= 0}
              >
                {d.namalengkap} | {d.jammulai}–{d.jamakhir}
                {' '}| Sisa: {d.sisa_quota}/{d.quota}
                {d.sisa_quota <= 0 ? ' (PENUH)' : ''}
              </option>
            ))}
          </select>
          {errors.dokter_id && (
            <div className="input-error">{errors.dokter_id}</div>
          )}

          {/* Info dokter terpilih */}
          {form.dokter_id && (() => {
            const sel = dokterJadwal.find(d => String(d.id) === String(form.dokter_id));
            if (!sel) return null;
            return (
              <div className={`mt-2 p-2.5 rounded-lg text-xs border flex items-start gap-2 ${
                sel.sisa_quota <= 0
                  ? 'bg-red-50 border-red-200 text-red-700'
                  : 'bg-blue-50 border-blue-200 text-blue-700'
              }`}>
                <i className={`fas mt-0.5 ${
                  sel.sisa_quota <= 0 ? 'fa-exclamation-circle' : 'fa-user-md'
                }`}></i>
                <div>
                  <strong>{sel.namalengkap}</strong><br />
                  <span>Jam Praktik: {sel.jammulai} – {sel.jamakhir} ({sel.hari})</span><br />
                  <span>
                    Sisa Kuota:{' '}
                    <strong className={sel.sisa_quota <= 0 ? 'text-red-600' : 'text-green-600'}>
                      {sel.sisa_quota}/{sel.quota}
                    </strong>
                    {sel.sisa_quota <= 0 && ' — Kuota penuh, pilih dokter lain.'}
                  </span>
                </div>
              </div>
            );
          })()}
        </div>
      )}

      {errors.jadwal && (
        <div className="input-error mb-3">{errors.jadwal}</div>
      )}
    </>
  );

  // ─── Tiket hasil pendaftaran ──────────────────────────────────────────────
  if (regResult) {
    return (
      <div className="card p-6 printable-ticket">
        <div className="text-center mb-6">
          {/* Kop surat: logo + nama rumah sakit */}
          <div className="ticket-kop mb-3">
            <img src="./logo.png" alt="Logo RSUD Malangbong" className="ticket-logo" />
            <div className="text-left">
              <div className="ticket-kop-name">RSUD MALANGBONG</div>
              <div className="ticket-kop-sub">Jl. Raya Malangbong – Garut, Jawa Barat</div>
            </div>
          </div>
          <h2 className="text-xl font-bold text-gray-800">
            {isExisting
              ? 'BUKTI PENDAFTARAN KUNJUNGAN'
              : 'BUKTI PENDAFTARAN ONLINE PASIEN BARU'}
          </h2>
          <p className="text-xs text-gray-500">Pasien Rawat Jalan - Pembayaran UMUM</p>
        </div>

        <div className="ticket-card mb-6 anim-pop">
          <div className="text-center pb-4 mb-4 border-b border-green-200">
            <span className="text-xs text-gray-500 uppercase tracking-wide">Nomor Antrian Anda</span>
            <div className="text-4xl font-extrabold text-green-700 my-1">{regResult.noantrian}</div>
            <div className="text-sm font-semibold text-gray-700">{regResult.poliklinik}</div>
          </div>
          <div className="grid grid-cols-2 gap-3 text-sm">
            {regResult.nocm && (
              <div>
                <span className="text-xs text-gray-400 block">No. Rekam Medis (RM)</span>
                <strong className="text-gray-800 text-base">{regResult.nocm}</strong>
              </div>
            )}
            <div>
              <span className="text-xs text-gray-400 block">No. Registrasi</span>
              <strong className="text-gray-800">{regResult.noregistrasi}</strong>
            </div>
            {regResult.namapasien && (
              <div>
                <span className="text-xs text-gray-400 block">Nama Pasien</span>
                <strong className="text-gray-800">{regResult.namapasien}</strong>
              </div>
            )}
            <div>
              <span className="text-xs text-gray-400 block">Tgl Kunjungan</span>
              <strong className="text-green-700">{regResult.tgl_kunjungan}</strong>
            </div>
            <div className="col-span-2">
              <span className="text-xs text-gray-400 block">Dokter Pemeriksa</span>
              <strong className="text-gray-800">{regResult.dokter}</strong>
            </div>
          </div>
        </div>

        <div className="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 mb-6">
          <i className="fas fa-info-circle mr-1 text-amber-600"></i>
          <strong>Petunjuk Kunjungan:</strong> Harap datang 1 jam sebelum jam pelayanan poli.
        </div>

        <div className="flex gap-2">
          <button
            type="button"
            onClick={resetForm}
            className="flex-1 py-2.5 bg-green-700 hover:bg-green-800 text-white rounded-xl font-semibold text-sm transition"
          >
            <i className="fas fa-plus-circle mr-2"></i>
            {isExisting ? 'Daftar Kunjungan Lain' : 'Daftar Pasien Lain'}
          </button>
        </div>
      </div>
    );
  }

  // ─── Loading master data ──────────────────────────────────────────────────
  if (!masterData) {
    return (
      <div className="flex flex-col justify-center items-center py-16 gap-4 anim-fade">
        <div className="m3-spinner"></div>
        <span className="text-gray-500 font-medium text-sm">Memuat master data RSUD...</span>
      </div>
    );
  }

  // ─── Form pasien TERDAFTAR ────────────────────────────────────────────────
  if (isExisting) {
    const selDokter  = dokterJadwal.find(d => String(d.id) === String(form.dokter_id));
    const kuotaPenuh = selDokter && selDokter.sisa_quota <= 0;

    return (
      <div className="card p-5">
        <div className="flex items-center justify-between mb-2">
          <h2 className="text-lg font-bold text-gray-800">Pendaftaran Kunjungan Rawat Jalan</h2>
          <span className="bg-blue-100 text-blue-800 text-xs px-2.5 py-1 rounded-full font-bold">
            PASIEN TERDAFTAR
          </span>
        </div>

        <div className="bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4 text-sm">
          <p>
            <strong>Pasien:</strong> {profile?.namapasien || '-'}
            <span className="text-gray-500 ml-2">(RM: {profile?.nocm || '-'})</span>
          </p>
        </div>

        {/* ⚠️ Peringatan double reservasi */}
        {loadingBooking && (
          <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 mb-4 text-xs text-gray-500 flex items-center gap-2">
            <i className="fas fa-spinner fa-spin"></i>
            <span>Memeriksa reservasi aktif...</span>
          </div>
        )}
        {activeBooking?.hasActive && (
          <div className="bg-red-50 border-2 border-red-300 rounded-xl p-4 mb-4">
            <div className="flex items-start gap-3">
              <div className="flex-shrink-0 w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                <i className="fas fa-exclamation-triangle text-red-600 text-lg"></i>
              </div>
              <div className="flex-1">
                <h3 className="font-bold text-red-800 text-sm mb-1">Reservasi Aktif Ditemukan!</h3>
                <p className="text-xs text-red-700 mb-2">
                  Anda sudah memiliki reservasi aktif untuk hari ini. Silakan selesaikan atau batalkan reservasi sebelumnya sebelum membuat reservasi baru.
                </p>
                <p className="text-xs text-red-600">
                  <i className="fas fa-info-circle mr-1"></i>
                  Buka tab <strong>Riwayat</strong> untuk melihat dan membatalkan reservasi.
                </p>
              </div>
            </div>
          </div>
        )}

        <form onSubmit={handleSubmit}>
          {renderJadwalSection()}

          <button
            type="submit"
            disabled={submitting || jadwalStatus.exists === false || kuotaPenuh || loadingDokter || activeBooking?.hasActive}
            className={`btn-submit ${
              jadwalStatus.exists === false || kuotaPenuh || activeBooking?.hasActive
                ? 'opacity-50 cursor-not-allowed'
                : ''
            }`}
          >
            {submitting ? (
              <span className="inline-flex items-center">
                <span className="loader loader-light mr-2"></span> Memproses...
              </span>
            ) : (
              <span>
                <i className="fas fa-paper-plane mr-2"></i> Daftar Kunjungan
              </span>
            )}
          </button>

          {kuotaPenuh && (
            <p className="text-xs text-red-600 mt-2 text-center">
              <i className="fas fa-exclamation-triangle mr-1"></i>
              Kuota dokter penuh. Silakan pilih dokter lain.
            </p>
          )}
          {activeBooking?.hasActive && (
            <p className="text-xs text-red-600 mt-2 text-center font-semibold">
              <i className="fas fa-ban mr-1"></i>
              Tidak dapat mendaftar karena masih ada reservasi aktif.
            </p>
          )}
        </form>
      </div>
    );
  }

  // ─── Form pasien BARU ─────────────────────────────────────────────────────
  const selDokter  = dokterJadwal.find(d => String(d.id) === String(form.dokter_id));
  const kuotaPenuh = selDokter && selDokter.sisa_quota <= 0;
  const canSubmit  = nikValid && jadwalStatus.exists !== false && !kuotaPenuh && !loadingDokter;

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-2">
        <h2 className="text-lg font-bold text-gray-800">Daftar Online Pasien Baru</h2>
        <span className="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-bold">
          PEMBAYARAN UMUM
        </span>
      </div>
      <p className="text-xs text-gray-500 mb-5">
        Formulir pendaftaran pasien baru rawat jalan RSUD Malangbong.
      </p>

      <form onSubmit={handleSubmit}>

        {/* ── LANGKAH 1: CEK NIK ── */}
        <div className="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-4">
          <div className="form-section-title mt-0 text-gray-700 border-gray-300">
            <i className="fas fa-id-card mr-2"></i> Langkah 1: Cek NIK
          </div>
          <div className="form-group mb-0">
            <label>NIK / No Identitas <span className="text-red-500">*</span></label>
            <div className="input-with-button">
              <input
                type="text"
                name="nik"
                maxLength={16}
                value={form.nik}
                onChange={handleChange}
                placeholder="Masukkan 16 digit NIK"
                className={errors.nik ? 'border-red-500' : ''}
                required
              />
              <button
                type="button"
                onClick={() => checkNik(form.nik)}
                disabled={loadingNik || form.nik.length !== 16}
              >
                {loadingNik ? <span className="loader"></span> : 'Cek NIK'}
              </button>
            </div>
            {errors.nik && <div className="input-error">{errors.nik}</div>}
            {nikStatus && (
              <div className={`mt-2 p-2.5 rounded-lg text-xs font-medium ${
                nikStatus.exists || nikStatus.tone === 'error'
                  ? 'bg-red-50 text-red-700 border border-red-200'
                  : 'bg-green-50 text-green-700 border border-green-200'
              }`}>
                {nikStatus.message}
              </div>
            )}
          </div>
        </div>

        {/* ── LANGKAH 2: DATA IDENTITAS (muncul setelah NIK valid) ── */}
        {showFullForm && nikValid && (
          <>
            <div className="form-section-title">
              <i className="fas fa-user mr-2 text-primary"></i> Langkah 2: Data Identitas Pasien
            </div>

            {/* Nama */}
            <div className="form-group">
              <label>Nama Lengkap Pasien <span className="text-red-500">*</span></label>
              <input
                type="text"
                name="namapasien"
                value={form.namapasien}
                onChange={e =>
                  setForm(prev => ({ ...prev, namapasien: e.target.value.toUpperCase() }))
                }
                className={errors.namapasien ? 'border-red-500' : ''}
                required
                placeholder="NAMA PASIEN SESUAI KTP"
              />
              {errors.namapasien && <div className="input-error">{errors.namapasien}</div>}
            </div>

            {/* Tempat & Tgl Lahir */}
            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Tempat Lahir <span className="text-red-500">*</span></label>
                <input
                  type="text"
                  name="tempatlahir"
                  value={form.tempatlahir}
                  onChange={e =>
                    setForm(prev => ({ ...prev, tempatlahir: e.target.value.toUpperCase() }))
                  }
                  className={errors.tempatlahir ? 'border-red-500' : ''}
                  required
                  placeholder="Kota Lahir"
                />
                {errors.tempatlahir && <div className="input-error">{errors.tempatlahir}</div>}
              </div>
              <div className="form-group">
                <label>Tgl Lahir <span className="text-red-500">*</span></label>
                <input
                  type="date"
                  name="tgllahir"
                  value={form.tgllahir}
                  onChange={handleChange}
                  className={errors.tgllahir ? 'border-red-500' : ''}
                  required
                />
                {errors.tgllahir && <div className="input-error">{errors.tgllahir}</div>}
              </div>
            </div>

            {/* Jenis Kelamin & No HP */}
            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Jenis Kelamin <span className="text-red-500">*</span></label>
                <select
                  name="jeniskelamin"
                  value={form.jeniskelamin}
                  onChange={handleChange}
                  className={errors.jeniskelamin ? 'border-red-500' : ''}
                  required
                >
                  <option value="2">Laki-Laki</option>
                  <option value="1">Perempuan</option>
                </select>
                {errors.jeniskelamin && <div className="input-error">{errors.jeniskelamin}</div>}
              </div>
              <div className="form-group">
                <label>No HP / Ponsel <span className="text-red-500">*</span></label>
                <input
                  type="tel"
                  name="nohp"
                  value={form.nohp}
                  onChange={handleChange}
                  className={errors.nohp ? 'border-red-500' : ''}
                  required
                  placeholder="08xxxxxxxxxx"
                />
                {errors.nohp && <div className="input-error">{errors.nohp}</div>}
              </div>
            </div>

            {/* Agama, Kebangsaan, Negara */}
            <div className="grid grid-cols-3 gap-2">
              <div className="form-group">
                <label>Agama</label>
                <select name="agama" value={form.agama} onChange={handleChange}>
                  {agama.map(a => (
                    <option key={a.id} value={a.id}>{a.agama}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>Kebangsaan</label>
                <select name="kebangsaan" value={form.kebangsaan} onChange={handleChange}>
                  {kebangsaan.map(k => (
                    <option key={k.id} value={k.id}>{k.name}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>Negara</label>
                <select name="negara" value={form.negara} onChange={handleChange}>
                  {negara.map(n => (
                    <option key={n.id} value={n.id}>{n.nama}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* ── Alamat ── */}
            <div className="form-section-title">
              <i className="fas fa-map-marker-alt mr-2 text-primary"></i> Informasi Alamat Pasien
            </div>

            <div className="form-group autocomplete-container">
              <label>Cari Desa / Kelurahan / Kecamatan</label>
              <input
                type="text"
                value={form.desaQuery}
                onChange={e => handleDesaSearch(e.target.value)}
                placeholder="Ketik min 2 huruf (misal: Malangbong)"
              />
              {loadingDesa && (
                <div className="text-xs text-gray-400 mt-1">Mencari lokasi...</div>
              )}
              {showDesaList && desaSuggestions.length > 0 && (
                <div className="suggestion-list">
                  {desaSuggestions.map(d => (
                    <div
                      key={d.id_dk}
                      className="suggestion-item"
                      onClick={() => handleSelectDesa(d)}
                    >
                      <strong className="block text-gray-800">{d.namadesakelurahan}</strong>
                      <span className="text-xs text-gray-500">
                        Kec. {d.namakecamatan}, {d.namakotakabupaten}, {d.namapropinsi} ({d.kodepos})
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {(form.kecamatanName || form.kotaName) && (
              <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 mb-3 text-xs text-gray-600">
                <div><strong>Kecamatan:</strong> {form.kecamatanName}</div>
                <div><strong>Kab/Kota:</strong>  {form.kotaName}</div>
                <div><strong>Provinsi:</strong>   {form.provinsiName}</div>
              </div>
            )}

            <div className="form-group">
              <label>Alamat Lengkap <span className="text-red-500">*</span></label>
              <textarea
                name="alamat"
                rows="2"
                value={form.alamat}
                onChange={e =>
                  setForm(prev => ({ ...prev, alamat: e.target.value.toUpperCase() }))
                }
                className={errors.alamat ? 'border-red-500' : ''}
                required
                placeholder="ALAMAT LENGKAP KAMPUNG/JALAN RT RW"
              />
              {errors.alamat && <div className="input-error">{errors.alamat}</div>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>RT / RW</label>
                <input
                  type="text"
                  name="rtrw"
                  value={form.rtrw}
                  onChange={handleChange}
                  placeholder="001/002"
                />
              </div>
              <div className="form-group">
                <label>Kode Pos</label>
                <input
                  type="text"
                  name="kodepos"
                  value={form.kodepos}
                  onChange={handleChange}
                  placeholder="44188"
                />
              </div>
            </div>

            <div className="form-group">
              <label>Email (Opsional)</label>
              <input
                type="email"
                name="email"
                value={form.email}
                onChange={handleChange}
                placeholder="email@domain.com"
              />
            </div>

            {/* ── Penanggung Jawab ── */}
            <div className="form-section-title">
              <i className="fas fa-user-shield mr-2 text-primary"></i> Penanggung Jawab Pasien
            </div>

            <div className="form-group checkbox-inline bg-amber-50 p-2.5 rounded-xl border border-amber-200 mb-3">
              <input
                type="checkbox"
                name="penanggung_sama"
                checked={form.penanggung_sama}
                onChange={handleChange}
                id="penanggung_sama"
              />
              <label
                htmlFor="penanggung_sama"
                className="text-xs font-semibold text-amber-900 cursor-pointer"
              >
                Penanggung Jawab Sama dengan Data Pasien
              </label>
            </div>

            <div className="form-group">
              <label>Nama Penanggung Jawab</label>
              <input
                type="text"
                name="nama_penanggung"
                value={form.nama_penanggung}
                onChange={e =>
                  setForm(prev => ({ ...prev, nama_penanggung: e.target.value.toUpperCase() }))
                }
                placeholder="Nama Penanggung Jawab"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Hubungan dg Pasien</label>
                <select
                  name="hubungan_penanggung"
                  value={form.hubungan_penanggung}
                  onChange={handleChange}
                >
                  {hubungan.map(h => (
                    <option key={h.id} value={h.id}>{h.nama}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>No. Telp Penanggung</label>
                <input
                  type="tel"
                  name="telp_penanggung"
                  value={form.telp_penanggung}
                  onChange={handleChange}
                  placeholder="08xxxxxxxxxx"
                />
              </div>
            </div>

            <div className="form-group">
              <label>Alamat Penanggung Jawab</label>
              <textarea
                name="alamat_penanggung"
                rows="2"
                value={form.alamat_penanggung}
                onChange={e =>
                  setForm(prev => ({ ...prev, alamat_penanggung: e.target.value.toUpperCase() }))
                }
                placeholder="Alamat Penanggung Jawab"
              />
            </div>

            {/* ── Informasi Tambahan ── */}
            <div className="form-section-title">
              <i className="fas fa-info-circle mr-2 text-primary"></i> Informasi Tambahan
            </div>

            <div className="form-group">
              <label>Nama Ibu Kandung <span className="text-red-500">*</span></label>
              <input
                type="text"
                name="nama_ibu"
                value={form.nama_ibu}
                onChange={e =>
                  setForm(prev => ({ ...prev, nama_ibu: e.target.value.toUpperCase() }))
                }
                className={errors.nama_ibu ? 'border-red-500' : ''}
                required
                placeholder="NAMA IBU KANDUNG"
              />
              {errors.nama_ibu && <div className="input-error">{errors.nama_ibu}</div>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Status Perkawinan</label>
                <select name="status_perkawinan" value={form.status_perkawinan} onChange={handleChange}>
                  {status_perkawinan.map(s => (
                    <option key={s.id} value={s.id}>{s.nama}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>Golongan Darah</label>
                <select name="goldar" value={form.goldar} onChange={handleChange}>
                  <option value="">Pilih</option>
                  {goldar.map(g => (
                    <option key={g.id} value={g.id}>{g.nama}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Pendidikan</label>
                <select name="pendidikan" value={form.pendidikan} onChange={handleChange}>
                  {pendidikan.map(p => (
                    <option key={p.id} value={p.id}>{p.nama}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>Pekerjaan</label>
                <select name="pekerjaan" value={form.pekerjaan} onChange={handleChange}>
                  {pekerjaan.map(pk => (
                    <option key={pk.id} value={pk.id}>{pk.nama}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="form-group">
                <label>Etnis / Suku</label>
                <select name="etnis" value={form.etnis} onChange={handleChange}>
                  {etnis.map(et => (
                    <option key={et.id} value={et.id}>{et.nama}</option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>Nama Ayah</label>
                <input
                  type="text"
                  name="nama_ayah"
                  value={form.nama_ayah}
                  onChange={e =>
                    setForm(prev => ({ ...prev, nama_ayah: e.target.value.toUpperCase() }))
                  }
                  placeholder="Nama Ayah"
                />
              </div>
            </div>
          </>
        )}

        {/* ── LANGKAH 3: RENCANA KUNJUNGAN (selalu tampil) ── */}
        <div className="bg-emerald-50/70 border border-emerald-100 rounded-xl p-4 mt-4">
          <div className="form-section-title mt-0 text-emerald-800 border-emerald-200">
            <i className="fas fa-hospital-user mr-2"></i> Rencana Kunjungan Rawat Jalan
          </div>
          {renderJadwalSection()}
        </div>

        {/* ── Tombol Submit ── */}
        <button
          type="submit"
          disabled={submitting || !canSubmit}
          className={`btn-submit mt-4 ${!canSubmit ? 'opacity-50 cursor-not-allowed' : ''}`}
        >
          {submitting ? (
            <span className="inline-flex items-center">
              <span className="loader loader-light mr-2"></span> Memproses Pendaftaran...
            </span>
          ) : (
            <span>
              <i className="fas fa-paper-plane mr-2"></i> Daftar Pasien Baru (Umum)
            </span>
          )}
        </button>

        {/* Pesan bantu di bawah tombol */}
        {!nikValid && form.nik.length > 0 && (
          <p className="text-xs text-amber-600 mt-2 text-center">
            <i className="fas fa-info-circle mr-1"></i> Silakan validasi NIK terlebih dahulu.
          </p>
        )}
        {jadwalStatus.exists === false && (
          <p className="text-xs text-red-600 mt-2 text-center">
            <i className="fas fa-exclamation-triangle mr-1"></i>
            Tidak ada jadwal dokter. Silakan pilih tanggal / poliklinik lain.
          </p>
        )}
        {kuotaPenuh && (
          <p className="text-xs text-red-600 mt-2 text-center">
            <i className="fas fa-exclamation-triangle mr-1"></i>
            Kuota dokter penuh. Silakan pilih dokter lain.
          </p>
        )}

      </form>
    </div>
  );
}