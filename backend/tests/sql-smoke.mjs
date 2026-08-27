// ============================================================================
// SQL smoke test — menjalankan query persis seperti yang ada di backend/api.php
// terhadap PostgreSQL asli (pglite/WASM) dengan skema mock SIMRS.
// Alur: login → masters → jadwal → daftar pasien baru → tiket → riwayat →
//       reservasi pasien lama → double-booking → batal → check-in → cek akhir
// ============================================================================
import { PGlite } from '@electric-sql/pglite';

const db = new PGlite();
let pass = 0, fail = 0;
const ok = (cond, label, extra = '') => {
  if (cond) { pass++; console.log('  ✅', label); }
  else { fail++; console.log('  ❌', label, extra); }
};

const q = (sql, params = []) => db.query(sql, params);

// Tanggal uji: hari Senin berikutnya (>= besok) — jadwal mock hari Senin
function nextMonday(offsetMin = 1) {
  const d = new Date();
  d.setDate(d.getDate() + offsetMin);
  while (d.getDay() !== 1) d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}
const TGL = nextMonday(1);
console.log('Tanggal uji (Senin):', TGL);

// ----------------------------------------------------------------------------
// 1. SCHEMA
// ----------------------------------------------------------------------------
await db.exec(`
CREATE TABLE departemen_m (id int PRIMARY KEY, namadepartemen text);
CREATE TABLE ruangan_m (id int PRIMARY KEY, namaruangan text, prefixnoantrian text, objectdepartemenfk int, statusenabled bool);
CREATE TABLE pegawai_m (id int PRIMARY KEY, namalengkap text, objectjenispegawaifk int, statusenabled bool);
CREATE TABLE jadwaldokter_m (id serial PRIMARY KEY, kdprofile int, statusenabled bool, norec text,
  objectpegawaifk int, objectruanganfk int, hari int, jammulai time, jamakhir time, quota int);
CREATE TABLE pasien_m (id text PRIMARY KEY, kdprofile int, statusenabled bool, norec text, nocm text,
  namapasien text, namaexternal text, reportdisplay text, noidentitas text, tempatlahir text,
  tgllahir date, objectjeniskelaminfk int, nohp text, notelepon text, objectagamafk int,
  objectkebangsaanfk int, objectnegarafk int, objectstatusperkawinanfk int, objectgolongandarahfk int,
  objectpendidikanfk int, objectpekerjaanfk int, objectsukufk int, namaibu text, namaayah text,
  namasuamiistri text, email text, penanggungjawab text, hubungankeluargapj int,
  telponpenanggungjawab text, jeniskelaminpenanggungjawab int, alamatrmh text, alamatlengkap text,
  tgldaftar timestamp, qpasien text);
CREATE TABLE alamat_m (id text PRIMARY KEY, kdprofile int, statusenabled bool, norec text, nocmfk text,
  alamatlengkap text, rtrw text, objectpropinsifk int, objectkotakabupatenfk int, objectkecamatanfk int,
  objectdesakelurahanfk int, kodepos text, objectjenisalamatfk text, objecthubungankeluargafk int, created_at timestamp);
CREATE TABLE pasiendaftar_t (norec text PRIMARY KEY, kdprofile int, statusenabled bool, noregistrasi text,
  nocmfk text, tglregistrasi timestamp, objectruanganlastfk int, objectpegawaifk int,
  objectkelompokpasienlastfk int, objectkelasfk int, statuspasien text, created_at timestamp,
  tglpulang timestamp, ischeckin bool default false);
CREATE TABLE antrianpasiendiperiksa_t (norec text PRIMARY KEY, kdprofile int, statusenabled bool,
  noregistrasifk text, noregistrasi text, objectruanganfk int, objectpegawaifk int, objectkelasfk int,
  kelasfk int, noantrian int, prefixnoantrian text, tglregistrasi timestamp, statusantrian text, created_at timestamp);
CREATE TABLE jeniskelamin_m (id int PRIMARY KEY, jeniskelamin text);
CREATE TABLE agama_m (id int PRIMARY KEY, agama text, statusenabled bool);
CREATE TABLE kebangsaan_m (id int PRIMARY KEY, name text, statusenabled bool);
CREATE TABLE negara_m (id int PRIMARY KEY, namanegara text, statusenabled bool);
CREATE TABLE hubungankeluarga_m (id int PRIMARY KEY, hubungankeluarga text, statusenabled bool);
CREATE TABLE pekerjaan_m (id int PRIMARY KEY, pekerjaan text, statusenabled bool);
CREATE TABLE statusperkawinan_m (id int PRIMARY KEY, statusperkawinan text, statusenabled bool);
CREATE TABLE golongandarah_m (id int PRIMARY KEY, golongandarah text, statusenabled bool);
CREATE TABLE pendidikan_m (id int PRIMARY KEY, pendidikan text, statusenabled bool);
CREATE TABLE suku_m (id int PRIMARY KEY, suku text, statusenabled bool);
CREATE TABLE kelompokpasien_m (id int PRIMARY KEY, kelompokpasien text, statusenabled bool);
CREATE TABLE propinsi_m (id int PRIMARY KEY, namapropinsi text);
CREATE TABLE kotakabupaten_m (id int PRIMARY KEY, namakotakabupaten text);
CREATE TABLE kecamatan_m (id int PRIMARY KEY, namakecamatan text);
CREATE TABLE desakelurahan_m (id int PRIMARY KEY, namadesakelurahan text, kodepos text,
  objectkecamatanfk int, objectkotakabupatenfk int, objectpropinsifk int, statusenabled bool);
CREATE TABLE strukorder_t (norec text PRIMARY KEY, kdprofile int, statusenabled bool, noorder text,
  tglorder timestamp, keteranganorder text, statusorder int, noregistrasi text, norec_apd text,
  objectruangantujuanfk int, objectruanganfk int, objectpegawaiorderfk int, noregistrasifk text);
CREATE TABLE pelayananpasien_t (norec text PRIMARY KEY, kdprofile int, statusenabled bool,
  noregistrasifk text, noregistrasi text, tglregistrasi timestamp, produkfk int, strukorderfk text,
  jumlah int, hargasatuan numeric, hargajual numeric, harganetto numeric, kelasfk int,
  kdkelompoktransaksi int, keteranganlain text, ischeckin bool, created_at timestamp);
CREATE TABLE produk_m (id int PRIMARY KEY, namaproduk text);
CREATE TABLE hasilradiologi_t (norec text PRIMARY KEY, statusenabled bool, pelayananpasienfk text,
  keterangan text, pegawaifk int);
CREATE TABLE strukpelayanan_t (norec text PRIMARY KEY, kdprofile int, statusenabled bool,
  noregistrasifk text, noregistrasi text, tglstruk timestamp, totalharusdibayar numeric,
  nostruk text, objectkelompoktransaksifk int, created_at timestamp);
`);

// ----------------------------------------------------------------------------
// 2. SEED
// ----------------------------------------------------------------------------
await db.exec(`
INSERT INTO departemen_m VALUES (18,'Poliklinik'),(48,'Rawat Inap');
INSERT INTO ruangan_m VALUES (100,'Poliklinik Penyakit Dalam','A',18,true),(101,'Poliklinik Anak','B',18,true);
INSERT INTO pegawai_m VALUES (500,'dr. Test Dokter SpPD',1,true),(501,'dr. Anak',1,true);
INSERT INTO jadwaldokter_m (kdprofile,statusenabled,norec,objectpegawaifk,objectruanganfk,hari,jammulai,jamakhir,quota)
VALUES (1,true,'jdw1',500,100,1,'08:00','12:00',2);
INSERT INTO jeniskelamin_m VALUES (1,'Perempuan'),(2,'Laki-laki');
INSERT INTO agama_m VALUES (1,'ISLAM',true);
INSERT INTO kebangsaan_m VALUES (1,'Indonesia',true);
INSERT INTO negara_m VALUES (1,'Indonesia',true);
INSERT INTO hubungankeluarga_m VALUES (1,'Suami',true);
INSERT INTO pekerjaan_m VALUES (1,'PNS',true);
INSERT INTO statusperkawinan_m VALUES (1,'Belum Kawin',true);
INSERT INTO golongandarah_m VALUES (1,'A',true);
INSERT INTO pendidikan_m VALUES (1,'SD',true);
INSERT INTO suku_m VALUES (1,'Sunda',true);
INSERT INTO kelompokpasien_m VALUES (1,'Umum',true);
INSERT INTO propinsi_m VALUES (33,'JAWA BARAT');
INSERT INTO kotakabupaten_m VALUES (3204,'KAB. GARUT');
INSERT INTO kecamatan_m VALUES (3204060,'MALANGBONG');
INSERT INTO desakelurahan_m VALUES (9001,'SUKAMAJU','44163',3204060,3204,33,true);
INSERT INTO pasien_m (id,kdprofile,statusenabled,norec,nocm,namapasien,namaexternal,reportdisplay,
  noidentitas,tempatlahir,tgllahir,objectjeniskelaminfk,nohp,notelepon,alamatlengkap,tgldaftar,qpasien)
VALUES ('1',1,true,'p1','00000001','PASIEN LAMA','PASIEN LAMA','PASIEN LAMA',
  '3204010101900002','GARUT','1990-01-01',2,'081200000001','081200000001','JL. TEST',NOW(),'1');
INSERT INTO produk_m VALUES (6164,'BIAYA REGISTRASI');
`);

console.log('\n═══ a) LOGIN (No RM & NIK) ═══');
{
  // resolve: login — nocm ATAU noidentitas
  const r1 = await q(`SELECT id, namapasien, nocm FROM pasien_m WHERE statusenabled = true AND (nocm = $1 OR noidentitas = $2) LIMIT 1`, ['00000001', '00000001']);
  ok(r1.rows.length === 1 && r1.rows[0].namapasien === 'PASIEN LAMA', 'login via No RM');
  const r2 = await q(`SELECT id, namapasien, nocm FROM pasien_m WHERE statusenabled = true AND (nocm = $1 OR noidentitas = $2) LIMIT 1`, ['3204010101900002', '3204010101900002']);
  ok(r2.rows.length === 1 && r2.rows[0].nocm === '00000001', 'login via NIK');
}

console.log('\n═══ b) MASTERS & POLIKLINIK ═══');
{
  const r = await q(`SELECT id, namaruangan, prefixnoantrian FROM ruangan_m WHERE objectdepartemenfk = 18 AND statusenabled = true ORDER BY namaruangan`);
  ok(r.rows.length === 2, 'get_poliklinik_list → 2 poli');
}

console.log('\n═══ c) JADWAL (get_jadwal_dokter & get_dokter_by_jadwal) ═══');
{
  // resolve detectJadwal: jadwaldokter_m/objectpegawaifk/objectruanganfk/hari/jammulai/jamakhir/quota
  const namaHari = 'Senin'; const keys = [namaHari, '1', (new Date(TGL + 'T00:00:00').getDay()) + ''];
  const sql = `SELECT pg.id AS dokter_id, pg.namalengkap AS namadokter, ru.id AS ruangan_id,
      ru.namaruangan, MIN(jd.jammulai)::text AS jammulai, MAX(jd.jamakhir)::text AS jamakhir,
      COALESCE(SUM(jd.quota), 40) AS quota,
      (SELECT COUNT(*) FROM antrianpasiendiperiksa_t a
       JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
       WHERE a.statusenabled = true AND a.objectruanganfk = ru.id AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = $1) AS terpakai
    FROM jadwaldokter_m jd
    JOIN pegawai_m pg ON pg.id = jd.objectpegawaifk AND pg.statusenabled = true
    JOIN ruangan_m ru ON ru.id = jd.objectruanganfk AND ru.statusenabled = true
    WHERE jd.objectruanganfk = $2 AND jd.hari::text IN ($3,$4,$5) AND COALESCE(jd.statusenabled, true) = true
    GROUP BY pg.id, pg.namalengkap, ru.id, ru.namaruangan ORDER BY pg.namalengkap`;
  const r = await q(sql, [TGL, 100, keys[0], keys[1], keys[2]]);
  ok(r.rows.length === 1, 'get_jadwal_dokter menemukan jadwal Senin', JSON.stringify(r.rows));
  ok(r.rows[0]?.quota == 2 && r.rows[0]?.terpakai == 0, 'kuota 2 / terpakai 0');
  const j = r.rows[0];
  const sisa = Math.max(0, j.quota - j.terpakai);
  ok(sisa === 2, 'sisa_quota = 2');
}

console.log('\n═══ d) DAFTAR PASIEN BARU (Mode A) ═══');
let pasienBaruId, noCm, noReg1, noAntrian1;
{
  await db.exec('BEGIN');
  await db.exec(`SELECT pg_advisory_xact_lock(hashtext('pasien_id'))`);
  const dup = await q(`SELECT id, nocm, namapasien FROM pasien_m WHERE noidentitas = $1 AND statusenabled = true LIMIT 1`, ['3204060105050001']);
  ok(dup.rows.length === 0, 'NIK belum terdaftar');
  const maxCm = await q(`SELECT MAX(CAST(nocm AS INTEGER)) AS max_cm FROM pasien_m WHERE nocm ~ '^[0-9]+$' AND LENGTH(nocm) <= 8`);
  noCm = String(((maxCm.rows[0].max_cm ?? 0) + 1)).padStart(8, '0');
  ok(noCm === '00000002', 'No CM berikutnya = 00000002', noCm);
  const maxId = await q(`SELECT MAX(CAST(id AS INTEGER)) AS max_id FROM pasien_m WHERE id ~ '^[0-9]+$'`);
  pasienBaruId = String((maxId.rows[0].max_id ?? 0) + 1);
  await q(`INSERT INTO pasien_m (id,kdprofile,statusenabled,norec,nocm,namapasien,namaexternal,reportdisplay,
      noidentitas,tempatlahir,tgllahir,objectjeniskelaminfk,nohp,notelepon,alamatlengkap,tgldaftar,qpasien)
    VALUES ($1,1,true,$2,$3,$3,$3,$3,$4,$5,$6,2,'081211111111','081211111111','JL. BARU 1',NOW(),'1')`,
    [pasienBaruId, 'norec-pb', noCm, '3204060105050001', 'GARUT', '2005-05-01']);
  const almId = await q(`SELECT COALESCE(MAX(CAST(id AS INTEGER)), 0) + 1 AS nid FROM alamat_m WHERE id ~ '^[0-9]+$'`);
  await q(`INSERT INTO alamat_m (id,kdprofile,statusenabled,norec,nocmfk,alamatlengkap,rtrw,kodepos,objectjenisalamatfk,objecthubungankeluargafk,created_at)
    VALUES ($1,1,true,$2,$3,'JL. BARU 1','1/2','44163','1',1,NOW())`, [almId.rows[0].nid, 'norec-alm', pasienBaruId]);
  await db.exec('COMMIT');
  ok(true, 'pasien baru & alamat tersimpan (id ' + pasienBaruId + ', cm ' + noCm + ')');

  // buatRegistrasiDanAntrian
  await db.exec('BEGIN');
  await db.exec(`SELECT pg_advisory_xact_lock(hashtext('antrian|100|${TGL}'))`);
  // cekJadwalKuota
  const cek = await q(`SELECT pg.id AS dokter_id, ru.id AS ruangan_id, MIN(jd.jammulai) AS jammulai, MAX(jd.jamakhir) AS jamakhir,
      COALESCE(SUM(jd.quota), 40) AS quota,
      (SELECT COUNT(*) FROM antrianpasiendiperiksa_t a JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
       WHERE a.statusenabled = true AND a.objectruanganfk = ru.id AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = $1) AS terpakai
    FROM jadwaldokter_m jd JOIN pegawai_m pg ON pg.id = jd.objectpegawaifk AND pg.statusenabled = true
    JOIN ruangan_m ru ON ru.id = jd.objectruanganfk AND ru.statusenabled = true
    WHERE jd.objectruanganfk = $2 AND pg.id = $3 AND jd.hari::text IN ($4,$5,$6) AND COALESCE(jd.statusenabled, true) = true
    GROUP BY pg.id, ru.id LIMIT 1`, [TGL, 100, 500, 'Senin', '1', (new Date(TGL + 'T00:00:00').getDay()) + '']);
  ok(cek.rows.length === 1 && cek.rows[0].quota - cek.rows[0].terpakai > 0, 'validasi jadwal & kuota lolos');
  const cnt = await q(`SELECT COUNT(*) AS c FROM pasiendaftar_t WHERE noregistrasi LIKE $1`, ['REG' + TGL.slice(2).replaceAll('-', '') + '%']);
  noReg1 = 'REG' + TGL.slice(2).replaceAll('-', '') + String((cnt.rows[0].c ?? 0) + 1).padStart(4, '0');
  const used = await q(`SELECT COUNT(*) AS c FROM antrianpasiendiperiksa_t a JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
    WHERE a.statusenabled = true AND a.objectruanganfk = $1 AND DATE(a.tglregistrasi) = $2`, [100, TGL]);
  noAntrian1 = used.rows[0].c + 1;
  ok(noAntrian1 === 1, 'no antrian pertama = 1');
  await q(`INSERT INTO pasiendaftar_t (norec,kdprofile,statusenabled,noregistrasi,nocmfk,tglregistrasi,
      objectruanganlastfk,objectpegawaifk,objectkelompokpasienlastfk,objectkelasfk,statuspasien,created_at)
    VALUES ($1,1,true,$2,$3,$4,$5,$6,1,6,$7,NOW())`,
    ['norec-r1', noReg1, pasienBaruId, TGL + ' 09:00:00', 100, 500, 'Pasien Baru']);
  await q(`INSERT INTO antrianpasiendiperiksa_t (norec,kdprofile,statusenabled,noregistrasifk,noregistrasi,
      objectruanganfk,objectpegawaifk,objectkelasfk,kelasfk,noantrian,prefixnoantrian,tglregistrasi,statusantrian,created_at)
    VALUES ($1,1,true,$2,$3,$4,$5,6,6,$6,$7,$8,'0',NOW())`,
    ['norec-a1', 'norec-r1', noReg1, 100, 500, noAntrian1, 'A', TGL + ' 09:00:00']);
  await db.exec('COMMIT');
  ok(true, `registrasi pasien baru tersimpan: ${noReg1}, antrian A-${String(noAntrian1).padStart(3, '0')}`);
}

console.log('\n═══ e) TIKET & RIWAYAT (setelah daftar) ═══');
{
  const t = await q(`SELECT pd.noregistrasi, pd.tglregistrasi, pd.tglpulang, pd.ischeckin, p.namapasien, p.nocm,
      ru.namaruangan AS poliklinik, pg.namalengkap AS dokter, a.noantrian, a.prefixnoantrian, a.statusantrian,
      CASE WHEN pd.statusenabled = false THEN 'Dibatalkan' WHEN pd.tglpulang IS NOT NULL THEN 'Selesai' ELSE 'Aktif' END AS status
    FROM pasiendaftar_t pd JOIN pasien_m p ON p.id = pd.nocmfk
    LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
    LEFT JOIN antrianpasiendiperiksa_t a ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
    LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
    WHERE pd.noregistrasi = $1 AND pd.nocmfk = $2 LIMIT 1`, [noReg1, pasienBaruId]);
  ok(t.rows.length === 1 && t.rows[0].status === 'Aktif' && t.rows[0].poliklinik === 'Poliklinik Penyakit Dalam', 'get_ticket_detail OK (status Aktif)');

  const rw = await q(`SELECT pd.norec, pd.noregistrasi, pd.tglregistrasi, pd.tglpulang, pd.ischeckin,
      ru.namaruangan, pg.namalengkap AS namadokter, a.noantrian, a.prefixnoantrian,
      CASE WHEN pd.statusenabled = false THEN 'Dibatalkan' WHEN pd.tglpulang IS NOT NULL THEN 'Selesai' ELSE 'Aktif' END AS status,
      CASE WHEN d.namadepartemen ILIKE '%rawat inap%' THEN 'Rawat Inap' ELSE 'Rawat Jalan' END AS jenis_rawat
    FROM pasiendaftar_t pd
    LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
    LEFT JOIN departemen_m d ON d.id = ru.objectdepartemenfk
    LEFT JOIN antrianpasiendiperiksa_t a ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
    LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
    WHERE pd.nocmfk = $1 ORDER BY pd.tglregistrasi DESC`, [pasienBaruId]);
  ok(rw.rows.length === 1 && rw.rows[0].status === 'Aktif' && rw.rows[0].jenis_rawat === 'Rawat Jalan' && rw.rows[0].namadokter === 'dr. Test Dokter SpPD', 'get_riwayat OK (status/jenis/dokter)');
}

console.log('\n═══ f) CHECK_ACTIVE_BOOKING (deteksi double booking) ═══');
{
  const aktif = await q(`SELECT pd.noregistrasi, pd.tglregistrasi, ru.namaruangan, pg.namalengkap AS dokter
    FROM pasiendaftar_t pd
    LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
    LEFT JOIN antrianpasiendiperiksa_t a ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
    LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
    WHERE pd.nocmfk = $1 AND pd.statusenabled = true AND pd.tglpulang IS NULL
      AND (pd.ischeckin = false OR pd.ischeckin IS NULL) AND DATE(pd.tglregistrasi) >= $2
    ORDER BY pd.tglregistrasi ASC LIMIT 1`, [pasienBaruId, TGL.slice(0, 0) || new Date().toISOString().slice(0, 10)]);
  ok(aktif.rows.length === 1, 'check_active_booking menemukan reservasi aktif');
}

console.log('\n═══ g) CANCEL_RESERVATION lalu REBOOK (Mode B pasien lama) ═══');
{
  //ambil norec
  const reg = await q(`SELECT pd.norec, pd.tglpulang, pd.ischeckin, ap.statusantrian FROM pasiendaftar_t pd
    LEFT JOIN antrianpasiendiperiksa_t ap ON ap.noregistrasi = pd.noregistrasi
    WHERE pd.noregistrasi = $1 AND pd.nocmfk = $2 LIMIT 1 FOR UPDATE OF pd`, [noReg1, pasienBaruId]);
  const r = reg.rows[0];
  await q(`UPDATE pasiendaftar_t SET statusenabled = false WHERE norec = $1`, [r.norec]);
  await q(`UPDATE antrianpasiendiperiksa_t SET statusenabled = false WHERE noregistrasi = $1 AND statusenabled = true`, [noReg1]);
  ok(true, 'reservasi pertama dibatalkan');

  // riwayat setelah batal → status Dibatalkan
  const rw = await q(`SELECT CASE WHEN pd.statusenabled = false THEN 'Dibatalkan' WHEN pd.tglpulang IS NOT NULL THEN 'Selesai' ELSE 'Aktif' END AS status
    FROM pasiendaftar_t pd WHERE pd.nocmfk = $1`, [pasienBaruId]);
  ok(rw.rows[0].status === 'Dibatalkan', 'riwayat menampilkan Dibatalkan');

  // cek aktif kini kosong
  const aktif = await q(`SELECT pd.noregistrasi FROM pasiendaftar_t pd WHERE pd.nocmfk = $1 AND pd.statusenabled = true
    AND pd.tglpulang IS NULL AND (pd.ischeckin = false OR pd.ischeckin IS NULL) AND DATE(pd.tglregistrasi) >= $2 LIMIT 1`,
    [pasienBaruId, new Date().toISOString().slice(0, 10)]);
  ok(aktif.rows.length === 0, 'check_active_booking bersih setelah batal');

  // Mode B rebook
  const cnt = await q(`SELECT COUNT(*) AS c FROM pasiendaftar_t WHERE noregistrasi LIKE $1`, ['REG' + TGL.slice(2).replaceAll('-', '') + '%']);
  const noReg2 = 'REG' + TGL.slice(2).replaceAll('-', '') + String(cnt.rows[0].c + 1).padStart(4, '0');
  const used = await q(`SELECT COUNT(*) AS c FROM antrianpasiendiperiksa_t a JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
    WHERE a.statusenabled = true AND a.objectruanganfk = $1 AND DATE(a.tglregistrasi) = $2`, [100, TGL]);
  ok(used.rows[0].c === 0, 'antrian yang dibatalkan tidak dihitung ulang (quota bebas)');
  await q(`INSERT INTO pasiendaftar_t (norec,kdprofile,statusenabled,noregistrasi,nocmfk,tglregistrasi,
      objectruanganlastfk,objectpegawaifk,objectkelompokpasienlastfk,objectkelasfk,statuspasien,created_at)
    VALUES ('norec-r2',1,true,$1,$2,$3,100,500,1,6,'Pasien Lama',NOW())`, [noReg2, pasienBaruId, TGL + ' 10:00:00']);
  await q(`INSERT INTO antrianpasiendiperiksa_t (norec,kdprofile,statusenabled,noregistrasifk,noregistrasi,
      objectruanganfk,objectpegawaifk,objectkelasfk,kelasfk,noantrian,prefixnoantrian,tglregistrasi,statusantrian,created_at)
    VALUES ('norec-a2',1,true,'norec-r2',$1,100,500,6,6,$2,'A',$3,'0',NOW())`, [noReg2, used.rows[0].c + 1, TGL + ' 10:00:00']);
  ok(true, `rebook pasien lama OK: ${noReg2}`);
  globalThis.noReg2 = noReg2;
}

console.log('\n═══ h) CHECK-IN (barcode umum harian) ═══');
{
  const barcode = 'CHECKIN-RSUD-MALANGBONG-' + new Date().toISOString().slice(0, 10).replaceAll('-', '');
  const patOk = /^CHECKIN-RSUD-MALANGBONG-\d{8}$/.test(barcode);
  ok(patOk, 'format barcode valid');
  const st = await q(`SELECT pd.norec, pd.statusenabled, pd.tglpulang, pd.ischeckin, ap.norec AS norec_apd, ap.statusantrian
    FROM pasiendaftar_t pd
    LEFT JOIN antrianpasiendiperiksa_t ap ON ap.noregistrasi = pd.noregistrasi AND COALESCE(ap.statusenabled, true) = true
    WHERE pd.noregistrasi = $1 AND pd.nocmfk = $2 LIMIT 1 FOR UPDATE OF pd`, [globalThis.noReg2, pasienBaruId]);
  const reg = st.rows[0];
  ok(reg.statusenabled && !reg.tglpulang && !reg.ischeckin, 'reg layak check-in');
  const maxStruk = await q(`SELECT COALESCE(MAX(CAST(SUBSTRING(nostruk FROM 2) AS BIGINT)), 0) AS m FROM strukpelayanan_t
    WHERE nostruk LIKE 'S%' AND LENGTH(nostruk) > 1 AND SUBSTRING(nostruk FROM 2) ~ '^[0-9]+$'`);
  const noStruk = 'S' + String((maxStruk.rows[0].m ?? 0) + 1).padStart(9, '0');
  await q(`INSERT INTO strukpelayanan_t (norec,kdprofile,statusenabled,noregistrasifk,noregistrasi,tglstruk,
      totalharusdibayar,nostruk,objectkelompoktransaksifk,created_at)
    VALUES ($1,1,true,$2,$3,NOW(),75000,$4,2,NOW())`, ['norec-struk', reg.norec, globalThis.noReg2, noStruk]);
  await q(`INSERT INTO pelayananpasien_t (norec,kdprofile,statusenabled,noregistrasifk,noregistrasi,tglregistrasi,
      produkfk,jumlah,hargasatuan,hargajual,harganetto,kelasfk,kdkelompoktransaksi,keteranganlain,ischeckin,created_at)
    VALUES ($1,1,true,$2,$3,NOW(),6164,1,75000,75000,75000,6,2,'BIAYA REGISTRASI CHECKIN',true,NOW())`,
    ['norec-pel', reg.norec, globalThis.noReg2]);
  await q(`UPDATE pasiendaftar_t SET ischeckin = true WHERE norec = $1`, [reg.norec]);
  await q(`UPDATE antrianpasiendiperiksa_t SET statusantrian = '1' WHERE norec = $1`, [reg.norec_apd]);
  ok(true, `check-in tersimpan (struk ${noStruk})`);

  // coba check-in lagi → harus terdeteksi
  const lagi = await q(`SELECT ischeckin FROM pasiendaftar_t WHERE norec = $1`, [reg.norec]);
  ok(lagi.rows[0].ischeckin === true, 'double check-in terblokir (ischeckin=true)');

  // cancel setelah check-in → harus terdeteksi
  const cek2 = await q(`SELECT pd.tglpulang, pd.ischeckin FROM pasiendaftar_t pd WHERE pd.noregistrasi = $1`, [globalThis.noReg2]);
  ok(cek2.rows[0].ischeckin === true, 'cancel setelah check-in terblokir');
}

console.log('\n═══ i) RIWAYAT AKHIR (3 item: Dibatalkan + Aktif/Checkin) ═══');
{
  const rw = await q(`SELECT pd.noregistrasi,
      CASE WHEN pd.statusenabled = false THEN 'Dibatalkan' WHEN pd.tglpulang IS NOT NULL THEN 'Selesai' ELSE 'Aktif' END AS status,
      pd.ischeckin,
      CASE WHEN pd.statusenabled = false THEN NULL ELSE a.prefixnoantrian || '-' || LPAD(a.noantrian::text, 3, '0') END AS noantrian_full
    FROM pasiendaftar_t pd
    LEFT JOIN antrianpasiendiperiksa_t a ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
    WHERE pd.nocmfk = $1 ORDER BY pd.tglregistrasi DESC`, [pasienBaruId]);
  ok(rw.rows.length === 2, '2 registrasi pada riwayat');
  const dibatalkan = rw.rows.find(r => r.status === 'Dibatalkan');
  const aktif = rw.rows.find(r => r.status === 'Aktif');
  ok(!!dibatalkan, 'status Dibatalkan ada');
  ok(!!aktif && aktif.ischeckin === true && aktif.noantrian_full === 'A-001', 'registrasi aktif ter-check-in dengan antrian A-001', JSON.stringify(aktif));
}

console.log(`\n══════════ HASIL: ${pass} lulus, ${fail} gagal ══════════`);
process.exit(fail ? 1 : 0);
