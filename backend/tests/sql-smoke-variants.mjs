// ============================================================================
// Tes varian skema jadwal — memastikan logika fetchJadwalRows baru bekerja pada:
//   A) kolom hari berisi NAMA HARI HURUF BESAR ('SENIN')
//   B) jadwal PER-TANGGAL (kolom tanggal terisi), tanpa kolom quota & statusenabled
// SQL di bawah = resolusi persis seperti yang dibangun fetchJadwalRows di api.php
// (param order: [tanggal-subquery, ruangan, ..., filter-tanggal])
// ============================================================================
import { PGlite } from '@electric-sql/pglite';

const mkDb = async (extraExec = () => {}) => {
  const db = new PGlite();
  await db.exec(`
    CREATE TABLE pegawai_m (id int PRIMARY KEY, namalengkap text, objectjenispegawaifk int, statusenabled bool);
    CREATE TABLE ruangan_m (id int PRIMARY KEY, namaruangan text, prefixnoantrian text, objectdepartemenfk int, statusenabled bool);
    INSERT INTO pegawai_m VALUES (500,'dr. Test',1,true);
    INSERT INTO ruangan_m VALUES (100,'Poli Dalam','A',18,true);
  `);
  await extraExec(db);
  return db;
};

function nextMonday(offsetMin = 1) {
  const d = new Date();
  d.setDate(d.getDate() + offsetMin);
  while (d.getDay() !== 1) d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}
const TGL = nextMonday(1);
const q = (db, sql, p = []) => db.query(sql, p);
function addDays(dateStr, n) {
  const d = new Date(dateStr + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

let pass = 0, fail = 0;
const ok = (c, label, extra = '') => { c ? (pass++, console.log('  ✅', label)) : (fail++, console.log('  ❌', label, extra)); };

// ---------------------------------------------------------------------------
// A) hari = 'SENIN' (nama hari huruf besar semua)
// ---------------------------------------------------------------------------
console.log('\n═══ A) kolom hari = NAMA HARI HURUF BESAR ═══');
{
  const db = await mkDb(async (d) => {
    await d.exec(`CREATE TABLE jadwaldokter_m (id serial PRIMARY KEY, kdprofile int, statusenabled bool,
      norec text, objectpegawaifk int, objectruanganfk int, hari text, jammulai time, jamakhir time, quota int);
      INSERT INTO jadwaldokter_m (kdprofile,statusenabled,norec,objectpegawaifk,objectruanganfk,hari,jammulai,jamakhir,quota)
      VALUES (1,true,'j1',500,100,'SENIN','08:00','12:00',20);`);
  });
  // resolusi fetchJadwalRows: keys = ['SENIN','1','1'] (Senin) → IN 2 placeholder (array_unique)
  const sql = `SELECT pg.id AS dokter_id, pg.namalengkap, ru.id AS ruangan_id, ru.namaruangan,
      MIN(jd.jammulai)::text AS jammulai, MAX(jd.jamakhir)::text AS jamakhir,
      COALESCE(SUM(jd.quota), 40) AS quota,
      (SELECT COUNT(*) FROM antrianpasiendiperiksa_t a
       JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
       WHERE a.statusenabled = true AND a.objectruanganfk = ru.id
         AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = $1) AS terpakai
    FROM jadwaldokter_m jd
    JOIN pegawai_m pg ON pg.id = jd.objectpegawaifk AND pg.statusenabled = true
    JOIN ruangan_m ru ON ru.id = jd.objectruanganfk AND ru.statusenabled = true
    WHERE jd.objectruanganfk = $2 AND UPPER(jd.hari::text) IN ($3,$4)
      AND COALESCE(jd.statusenabled, true) = true
    GROUP BY pg.id, pg.namalengkap, ru.id, ru.namaruangan ORDER BY pg.namalengkap`;
  // buat tabel antrian dummy agar subquery jalan
  await db.exec(`CREATE TABLE antrianpasiendiperiksa_t (norec text, kdprofile int, statusenabled bool, noregistrasifk text,
    noregistrasi text, objectruanganfk int, objectpegawaifk int, objectkelasfk int, kelasfk int, noantrian int,
    prefixnoantrian text, tglregistrasi timestamp, statusantrian text, created_at timestamp);
    CREATE TABLE pasiendaftar_t (norec text, kdprofile int, statusenabled bool, noregistrasi text, nocmfk text,
    tglregistrasi timestamp, objectruanganlastfk int, objectpegawaifk int, tglpulang timestamp, ischeckin bool);`);
  const r = await q(db, sql, [TGL, 100, 'SENIN', '1']);
  ok(r.rows.length === 1 && r.rows[0].namalengkap === 'dr. Test' && r.rows[0].quota == 20,
    "hari 'SENIN' cocok untuk tanggal Senin", JSON.stringify(r.rows));
  // cek hari lain (Selasa) harus kosong
  const selasa = addDays(TGL, 1);
  const r2 = await q(db, sql, [selasa, 100, 'SELASA', '2']);
  ok(r2.rows.length === 0, "hari 'SELASA' tidak cocok utk jadwal SENIN");
  await db.close();
}

// ---------------------------------------------------------------------------
// B) jadwal PER-TANGGAL, tanpa kolom quota & tanpa kolom statusenabled
// ---------------------------------------------------------------------------
console.log('\n═══ B) jadwal PER-TANGGAL (tanpa quota & statusenabled) ═══');
{
  const db = await mkDb(async (d) => {
    await d.exec(`CREATE TABLE jadwal_dokter_m (id serial PRIMARY KEY, objectpegawaifk int, objectruanganfk int,
      hari text, tanggal date, jammulai time, jamakhir time);
      INSERT INTO jadwal_dokter_m (objectpegawaifk,objectruanganfk,hari,tanggal,jammulai,jamakhir)
      VALUES (500,100,'Senin','${TGL}','09:00','13:00');`);
  });
  await db.exec(`CREATE TABLE antrianpasiendiperiksa_t (norec text, kdprofile int, statusenabled bool, noregistrasifk text,
    noregistrasi text, objectruanganfk int, objectpegawaifk int, objectkelasfk int, kelasfk int, noantrian int,
    prefixnoantrian text, tglregistrasi timestamp, statusantrian text, created_at timestamp);
    CREATE TABLE pasiendaftar_t (norec text, kdprofile int, statusenabled bool, noregistrasi text, nocmfk text,
    tglregistrasi timestamp, objectruanganlastfk int, objectpegawaifk int, tglpulang timestamp, ischeckin bool);`);
  // resolusi: mode_tanggal=true → DATE(jd.tanggal) = ? ; quota tak ada → SUM(40); status → TRUE
  const sql = `SELECT pg.id AS dokter_id, pg.namalengkap, ru.id AS ruangan_id, ru.namaruangan,
      MIN(jd.jammulai)::text AS jammulai, MAX(jd.jamakhir)::text AS jamakhir,
      COALESCE(SUM(40), 40) AS quota,
      (SELECT COUNT(*) FROM antrianpasiendiperiksa_t a
       JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
       WHERE a.statusenabled = true AND a.objectruanganfk = ru.id
         AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = $1) AS terpakai
    FROM jadwal_dokter_m jd
    JOIN pegawai_m pg ON pg.id = jd.objectpegawaifk AND pg.statusenabled = true
    JOIN ruangan_m ru ON ru.id = jd.objectruanganfk AND ru.statusenabled = true
    WHERE jd.objectruanganfk = $2 AND DATE(jd.tanggal) = $3 AND TRUE
    GROUP BY pg.id, pg.namalengkap, ru.id, ru.namaruangan ORDER BY pg.namalengkap`;
  const r = await q(db, sql, [TGL, 100, TGL]);
  ok(r.rows.length === 1 && r.rows[0].quota == 40 && r.rows[0].jammulai?.startsWith('09:00'),
    'jadwal per-tanggal ditemukan, kuota default 40', JSON.stringify(r.rows));
  // tanggal lain → kosong
  const lain = addDays(TGL, 1);
  const r2 = await q(db, sql, [lain, 100, lain]);
  ok(r2.rows.length === 0, 'tanggal lain tanpa jadwal → kosong');
  await db.close();
}

// ---------------------------------------------------------------------------
// C) kolom jam bernama jam_mulai/jam_akhir + ruanganfk
// ---------------------------------------------------------------------------
console.log('\n═══ C) varian nama kolom: jam_mulai/jam_akhir, ruanganfk, objectdokterfk ═══');
{
  const db = await mkDb(async (d) => {
    await d.exec(`CREATE TABLE jadwaldokter (id serial PRIMARY KEY, objectdokterfk int, ruanganfk int,
      hari int, jam_mulai time, jam_akhir time, kuota int);
      INSERT INTO jadwaldokter (objectdokterfk,ruanganfk,hari,jam_mulai,jam_akhir,kuota)
      VALUES (500,100,1,'07:30','11:00',15);`);
  });
  await db.exec(`CREATE TABLE antrianpasiendiperiksa_t (norec text, kdprofile int, statusenabled bool, noregistrasifk text,
    noregistrasi text, objectruanganfk int, objectpegawaifk int, objectkelasfk int, kelasfk int, noantrian int,
    prefixnoantrian text, tglregistrasi timestamp, statusantrian text, created_at timestamp);
    CREATE TABLE pasiendaftar_t (norec text, kdprofile int, statusenabled bool, noregistrasi text, nocmfk text,
    tglregistrasi timestamp, objectruanganlastfk int, objectpegawaifk int, tglpulang timestamp, ischeckin bool);`);
  const sql = `SELECT pg.id AS dokter_id, pg.namalengkap,
      MIN(jd.jam_mulai)::text AS jammulai, MAX(jd.jam_akhir)::text AS jamakhir,
      COALESCE(SUM(jd.kuota), 40) AS quota,
      (SELECT COUNT(*) FROM antrianpasiendiperiksa_t a
       JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
       WHERE a.statusenabled = true AND a.objectruanganfk = ru.id
         AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = $1) AS terpakai
    FROM jadwaldokter jd
    JOIN pegawai_m pg ON pg.id = jd.objectdokterfk AND pg.statusenabled = true
    JOIN ruangan_m ru ON ru.id = jd.ruanganfk AND ru.statusenabled = true
    WHERE jd.ruanganfk = $2 AND UPPER(jd.hari::text) IN ($3,$4) AND TRUE
    GROUP BY pg.id, pg.namalengkap, ru.id, ru.namaruangan ORDER BY pg.namalengkap`;
  const r = await q(db, sql, [TGL, 100, 'SENIN', '1']);
  ok(r.rows.length === 1 && r.rows[0].quota == 15, "kolom objectdokterfk/ruanganfk/jam_mulai/jam_akhir/kuota dikenali", JSON.stringify(r.rows));
  await db.close();
}

console.log(`\n════════ HASIL VARIAN: ${pass} lulus, ${fail} gagal ════════`);
process.exit(fail ? 1 : 0);
