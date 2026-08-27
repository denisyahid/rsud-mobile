<?php
// ============================================================================
// api.php — Backend API Aplikasi Mobile Pasien RSUD Malangbong
// ----------------------------------------------------------------------------
// SATU file untuk semua action yang dipanggil frontend (src/constants + komponen):
//
//   Auth              : login, logout
//   Pendaftaran       : daftar_online  (2 mode: PASIEN BARU & PASIEN TERDAFTAR),
//                       check_active_booking
//   Master/data       : get_masters, get_poliklinik_list, get_jadwal_dokter,
//                       get_dokter_by_jadwal, search_desa, search_nik
//   Data pasien       : get_profile, get_orders, get_riwayat, get_ticket_detail
//   Check-in          : checkin, cancel_reservation
//
// Perbaikan terhadap versi lama:
//   - daftar_online mendukung mode PASIEN TERDAFTAR (payload hanya
//     ruangan_id/dokter_id/tgl_kunjungan + session) → sebelumnya selalu gagal
//     "NIK wajib diisi" (root cause pendaftaran pasien lama error).
//   - Login menerima NIK *atau* No RM (dulu hanya No RM).
//   - get_riwayat mengembalikan status (Aktif/Selesai/Dibatalkan), is_checkin,
//     jenis_rawat & namadokter yang dibutuhkan tab Riwayat.
//   - Action jadwal & tiket & checkin & batal reservasi ditambahkan.
//   - Timezone Asia/Jakarta eksplisit (dulu date() memakai UTC server).
//   - Nomor urut (No CM / antrian / registrasi) memakai advisory lock agar
//     tidak rancu saat dua pendaftar bersamaan.
//   - CORS memakai allowlist (tidak lagi memantulkan origin sembarangan).
// ============================================================================

// ============================================================
// KONFIGURASI
// ============================================================
date_default_timezone_set('Asia/Jakarta');

$DB_HOST = getenv('RSUD_DB_HOST') ?: '192.168.22.81';
$DB_PORT = getenv('RSUD_DB_PORT') ?: '5792';
$DB_NAME = getenv('RSUD_DB_NAME') ?: 'rsud_malangbong';
$DB_USER = getenv('RSUD_DB_USER') ?: 'postgres';
$DB_PASS = getenv('RSUD_DB_PASS') ?: 'Tr4nsm3d!c MaRe#T3aM';

const POLI_DEPARTEMEN_FK      = 18;   // departemen poliklinik (rawat jalan)
const BIAYA_REGISTRASI        = 75000;
const PRODUK_BIAYA_REGISTRASI = 6164; // BIAYA REGISTRASI
const KELAS_FK                = 6;
const KELOMPOK_TRANSAKSI_STRUK = 2;
const DEFAULT_QUOTA           = 40;   // dipakai bila tabel jadwal tanpa kolom kuota
const MAX_HARI_RESERVASI      = 30;   // batas reservasi ke depan (hari)

$HARI_NAMA = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

// ============================================================
// CORS (allowlist) + SESSION COOKIE
// ============================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originOk = false;
foreach (['rsudmalangbong', 'tail351109.ts.net', 'localhost', '127.0.0.1', 'capacitor://'] as $frag) {
    if ($origin !== '' && stripos($origin, $frag) !== false) { $originOk = true; break; }
}
if ($originOk) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,   // otomatis mengikuti skema request
    'httponly' => true,
    'samesite' => 'None',
]);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// ============================================================
// KONEKSI DATABASE
// ============================================================
try {
    $pdo = new PDO("pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Koneksi database gagal: ' . $e->getMessage()]);
    exit;
}

// ============================================================
// HELPER
// ============================================================
function respond($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['pasien_id']);
}

function requireLogin() {
    if (!isLoggedIn()) respond(['error' => 'Unauthorized'], 401);
    return $_SESSION['pasien_id'];
}

function jsonInput() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function validDate($s) {
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = explode('-', $s);
    return checkdate((int)$m, (int)$d, (int)$y);
}

function genUuid() {
    return sprintf('%08x-%04x-%04x-%04x-%04x%08x',
        mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));
}

// Deteksi tabel & kolom jadwal dokter — dibuat toleran agar jalan di berbagai
// instalasi SIMRS:
//   • Nama tabel: banyak kandidat (override via env RSUD_JADWAL_TABLE)
//   • Kolom hari: angka 1-7 (Senin-first), 0-6 (Minggu-first), atau nama hari
//     (pencocokan case-insensitive: 'Senin'/'SENIN'/'senin')
//   • Jadwal mingguan ATAU per-tanggal (kolom tanggal terisi → mode per-tanggal)
//   • Kolom statusenabled boleh tidak ada (filter dilewati)
function detectJadwal($pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $candidates = [
        'jadwaldokter_m', 'jadwal_dokter_m', 'jadwaldokter_t', 'jadwalpraktikdokter_m',
        'jadwal_dokter', 'jadwaldokter', 'jadwal', 'jadwaldokter_harian',
        'jadwal_praktik_dokter', 'jadwaldokter_spesifik',
    ];
    if (getenv('RSUD_JADWAL_TABLE')) array_unshift($candidates, getenv('RSUD_JADWAL_TABLE'));

    $stTable = $pdo->prepare("SELECT 1 FROM information_schema.tables
                              WHERE table_schema = current_schema() AND table_name = :t LIMIT 1");
    $stCols  = $pdo->prepare("SELECT column_name FROM information_schema.columns
                              WHERE table_schema = current_schema() AND table_name = :t");

    foreach ($candidates as $t) {
        $stTable->execute([':t' => $t]);
        if (!$stTable->fetch()) continue;
        $stCols->execute([':t' => $t]);
        $cols = array_column($stCols->fetchAll(), 'column_name');
        $pick = function (array $names) use ($cols) {
            foreach ($names as $n) if (in_array($n, $cols, true)) return $n;
            return null;
        };
        $pegawai = $pick(['objectpegawaifk', 'objectdokterfk', 'pegawaifk', 'dokterfk']);
        $ruangan = $pick(['objectruanganfk', 'ruanganfk']);
        $hari    = $pick(['hari', 'hari_praktik', 'harijadwal', 'hari_jadwal', 'dayofweek', 'day']);
        $jammulai= $pick(['jammulai', 'jam_mulai', 'jammulaipraktik', 'jam_mulai_praktik', 'mulai']);
        $jamakhir= $pick(['jamakhir', 'jam_akhir', 'jammakhir', 'jam_selesai', 'selesai', 'akhir']);
        if (!$pegawai || !$ruangan || !$hari || !$jammulai || !$jamakhir) continue; // coba tabel kandidat lain

        // Mode per-tanggal? (kolom tanggal ada & benar-benar terisi)
        $tanggal = $pick(['tanggal', 'tanggaljadwal', 'tanggal_jadwal', 'tgljadwal']);
        $modeTanggal = false;
        if ($tanggal) {
            $c = $pdo->query("SELECT 1 FROM $t WHERE {$tanggal} IS NOT NULL LIMIT 1")->fetch();
            $modeTanggal = (bool)$c;
        }

        $cached = [
            'table'        => $t,
            'pegawai'      => $pegawai,
            'ruangan'      => $ruangan,
            'hari'         => $hari,
            'jammulai'     => $jammulai,
            'jamakhir'     => $jamakhir,
            'quota'        => $pick(['quota', 'kuota', 'kapasitas', 'quotajadwal', 'jmlkuota']),
            'tanggal'      => $tanggal,
            'mode_tanggal' => $modeTanggal,
            'punya_status' => in_array('statusenabled', $cols, true),
        ];
        return $cached;
    }
    $cached = false;
    return false;
}

// Filter hari untuk tanggal tertentu — toleran representasi hari di DB
// (angka 1-7 Senin-first, angka 0-6 Minggu-first, atau nama hari, apa pun hurufnya).
function jadwalHariKeys($tanggal, $namaHari) {
    $ts  = strtotime($tanggal);
    $n   = (int)date('N', $ts);        // 1=Senin .. 7=Minggu
    $w   = (int)date('w', $ts);        // 0=Minggu .. 6=Sabtu
    return array_values(array_unique([
        strtoupper($namaHari), (string)$n, (string)$w,
    ]));
}

// Fragment WHERE filter jadwal: per-tanggal (mode_tanggal) atau per-hari-mingguan.
// Menambahkan placeholder param ke $params sesuai urutan kemunculan di SQL.
function jadwalFilterTanggal($jad, $tanggal, $namaHari, &$params) {
    if (!empty($jad['mode_tanggal'])) {
        $params[] = $tanggal;
        return "DATE(jd.{$jad['tanggal']}) = ?";
    }
    $keys = jadwalHariKeys($tanggal, $namaHari);
    $in   = implode(',', array_fill(0, count($keys), '?'));
    foreach ($keys as $k) $params[] = $k;
    return "UPPER(jd.{$jad['hari']}::text) IN ($in)";
}

// Filter statusenabled — dilewati bila kolom tidak ada di tabel jadwal.
function jadwalStatusFilter($jad) {
    return !empty($jad['punya_status']) ? "COALESCE(jd.statusenabled, true) = true" : "TRUE";
}

// Ambil baris jadwal (group per dokter) untuk tanggal + ruangan (+ dokter opsional).
// Return [detected(bool), rows[]]. Param order: [tanggal(subquery terpakai),
// ruanganId, (dokterId), ...param filter tanggal].
function fetchJadwalRows($pdo, $tanggal, $ruanganId, $dokterId = null) {
    $jad = detectJadwal($pdo);
    if (!$jad) return [false, []];
    $ts = strtotime($tanggal);
    $namaHari = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][(int)date('N', $ts) - 1];
    $quotaExpr = $jad['quota'] ? "jd.{$jad['quota']}" : (string)DEFAULT_QUOTA;

    $params = [$tanggal]; // param pertama → subquery terpakai (di SELECT list)
    $sql = "SELECT pg.id AS dokter_id, pg.namalengkap, ru.id AS ruangan_id, ru.namaruangan,
                   MIN(jd.{$jad['jammulai']})::text AS jammulai,
                   MAX(jd.{$jad['jamakhir']})::text AS jamakhir,
                   COALESCE(SUM($quotaExpr), " . DEFAULT_QUOTA . ") AS quota,
                   " . terpakaiSubquery() . " AS terpakai
            FROM {$jad['table']} jd
            JOIN pegawai_m pg ON pg.id = jd.{$jad['pegawai']} AND pg.statusenabled = true
            JOIN ruangan_m ru ON ru.id = jd.{$jad['ruangan']} AND ru.statusenabled = true
            WHERE jd.{$jad['ruangan']} = ?";
    $params[] = $ruanganId;
    if ($dokterId !== null) {
        $sql .= " AND jd.{$jad['pegawai']} = ?";
        $params[] = $dokterId;
    }
    $sql .= " AND " . jadwalFilterTanggal($jad, $tanggal, $namaHari, $params);
    $sql .= " AND " . jadwalStatusFilter($jad);
    $sql .= " GROUP BY pg.id, pg.namalengkap, ru.id, ru.namaruangan
              ORDER BY pg.namalengkap";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return [true, $st->fetchAll()];
}

// Jumlah antrian terpakai (registrasi aktif saja — batal tidak dihitung).
// Placeholder posisi (?) — param tanggal WAJIB jadi argumen pertama di execute()
// karena subquery ini berada di awal (SELECT list) query pemakainya.
function terpakaiSubquery() {
    return "(SELECT COUNT(*) FROM antrianpasiendiperiksa_t a
             JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
             WHERE a.statusenabled = true AND a.objectruanganfk = ru.id
               AND a.objectpegawaifk = pg.id AND DATE(a.tglregistrasi) = ?)";
}

// Reservasi aktif milik pasien (belum check-in, belum pulang, tidak dibatalkan)
function findActiveBooking($pdo, $pasienId, $fromDate = null) {
    $sql = "SELECT pd.noregistrasi, pd.tglregistrasi, ru.namaruangan, pg.namalengkap AS dokter
            FROM pasiendaftar_t pd
            LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
            LEFT JOIN antrianpasiendiperiksa_t a ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
            LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
            WHERE pd.nocmfk = :pid AND pd.statusenabled = true
              AND pd.tglpulang IS NULL AND (pd.ischeckin = false OR pd.ischeckin IS NULL)";
    $params = [':pid' => $pasienId];
    if ($fromDate) {
        $sql .= " AND DATE(pd.tglregistrasi) >= :dari";
        $params[':dari'] = $fromDate;
    }
    $sql .= " ORDER BY pd.tglregistrasi ASC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}

// Validasi jadwal + kuota untuk (tanggal, ruangan, dokter). Return [ok, pesan, jadwalRow]
function cekJadwalKuota($pdo, $tanggal, $ruanganId, $dokterId) {
    [$detected, $rows] = fetchJadwalRows($pdo, $tanggal, $ruanganId, $dokterId);
    if (!$detected) return [true, null, null]; // skema jadwal tidak dikenali → jangan blokir pendaftaran
    if (empty($rows)) return [false, 'Tidak ada jadwal dokter untuk tanggal & poliklinik yang dipilih.', null];
    $row  = $rows[0];
    $sisa = (int)$row['quota'] - (int)$row['terpakai'];
    if ($sisa <= 0) return [false, 'Kuota dokter pada tanggal ini sudah penuh. Silakan pilih dokter/tanggal lain.', null];
    return [true, null, $row];
}

// Insert registrasi + antrian (dipakai kedua mode daftar_online). Return ticket.
function buatRegistrasiDanAntrian($pdo, $pasienId, $namaPasien, $nocm, $ruanganId, $dokterId, $tglKunjungan, $statusPasien) {
    $pdo->beginTransaction();
    // Advisory lock per ruangan+tanggal → nomor antrian bebas rancu (race condition)
    $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('antrian|" . $ruanganId . "|" . $tglKunjungan . "'))");

    $ruanganSt = $pdo->prepare("SELECT namaruangan, prefixnoantrian FROM ruangan_m WHERE id = :id");
    $ruanganSt->execute([':id' => $ruanganId]);
    $ruanganInfo = $ruanganSt->fetch();
    if (!$ruanganInfo) { $pdo->rollBack(); respond(['error' => 'Poliklinik tidak ditemukan'], 400); }
    $namaRuangan   = $ruanganInfo['namaruangan'];
    $prefixAntrian = !empty($ruanganInfo['prefixnoantrian']) ? trim($ruanganInfo['prefixnoantrian']) : 'A';

    $dokterSt = $pdo->prepare("SELECT namalengkap FROM pegawai_m WHERE id = :id");
    $dokterSt->execute([':id' => $dokterId]);
    $namaDokter = $dokterSt->fetch()['namalengkap'] ?? '-';

    // Validasi jadwal & kuota DI DALAM transaksi (anti tabrakan kuota)
    [$ok, $pesan] = cekJadwalKuota($pdo, $tglKunjungan, $ruanganId, $dokterId);
    if (!$ok) { $pdo->rollBack(); respond(['error' => $pesan], 400); }

    // No. registrasi: REGyymmdd + urut harian
    $prefixReg = 'REG' . date('ymd');
    $countSt = $pdo->prepare("SELECT COUNT(*) AS c FROM pasiendaftar_t WHERE noregistrasi LIKE ?");
    $countSt->execute([$prefixReg . '%']);
    $noRegistrasi = $prefixReg . str_pad(((int)$countSt->fetch()['c']) + 1, 4, '0', STR_PAD_LEFT);

    // Antrian berikutnya (hanya registrasi aktif dihitung)
    $usedSt = $pdo->prepare("SELECT COUNT(*) AS c
                             FROM antrianpasiendiperiksa_t a
                             JOIN pasiendaftar_t pd2 ON pd2.noregistrasi = a.noregistrasi AND pd2.statusenabled = true
                             WHERE a.statusenabled = true AND a.objectruanganfk = :ru
                               AND DATE(a.tglregistrasi) = :tgl");
    $usedSt->execute([':ru' => $ruanganId, ':tgl' => $tglKunjungan]);
    $noAntrian = ((int)$usedSt->fetch()['c']) + 1;

    $regNorec = genUuid();
    $stReg = $pdo->prepare("INSERT INTO pasiendaftar_t (
                norec, kdprofile, statusenabled, noregistrasi, nocmfk, tglregistrasi,
                objectruanganlastfk, objectpegawaifk, objectkelompokpasienlastfk, objectkelasfk,
                statuspasien, created_at
            ) VALUES (
                :norec, 1, true, :noregistrasi, :nocmfk, :tglregistrasi,
                :ruangan, :dokter, 1, " . KELAS_FK . ",
                :statuspasien, NOW()
            )");
    $stReg->execute([
        ':norec'         => $regNorec,
        ':noregistrasi'  => $noRegistrasi,
        ':nocmfk'        => $pasienId,
        ':tglregistrasi' => $tglKunjungan . ' ' . date('H:i:s'),
        ':ruangan'       => $ruanganId,
        ':dokter'        => $dokterId,
        ':statuspasien'  => $statusPasien,
    ]);

    $apdNorec = genUuid();
    $stApd = $pdo->prepare("INSERT INTO antrianpasiendiperiksa_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
                objectruanganfk, objectpegawaifk, objectkelasfk, kelasfk,
                noantrian, prefixnoantrian, tglregistrasi, statusantrian, created_at
            ) VALUES (
                :norec, 1, true, :noreg_fk, :noregistrasi,
                :ruangan, :dokter, " . KELAS_FK . ", " . KELAS_FK . ",
                :noantrian, :prefix, :tglregistrasi, '0', NOW()
            )");
    $stApd->execute([
        ':norec'         => $apdNorec,
        ':noreg_fk'      => $regNorec,
        ':noregistrasi'  => $noRegistrasi,
        ':ruangan'       => $ruanganId,
        ':dokter'        => $dokterId,
        ':noantrian'     => $noAntrian,
        ':prefix'        => $prefixAntrian,
        ':tglregistrasi' => $tglKunjungan . ' ' . date('H:i:s'),
    ]);

    $pdo->commit();

    return [
        'nocm'          => $nocm,
        'noregistrasi'  => $noRegistrasi,
        'noantrian'     => $prefixAntrian . '-' . str_pad((string)$noAntrian, 3, '0', STR_PAD_LEFT),
        'namapasien'    => strtoupper($namaPasien),
        'tgl_kunjungan' => $tglKunjungan,
        'poliklinik'    => $namaRuangan,
        'dokter'        => $namaDokter,
    ];
}

// ============================================================
// HANDLE REQUEST
// ============================================================
$action = $_REQUEST['action'] ?? '';

try {
switch ($action) {

    // ---------- LOGIN (NIK atau No RM) ----------
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);
        $input = jsonInput();
        $identifier = trim($input['identifier'] ?? $input['nocm'] ?? '');
        if ($identifier === '') respond(['error' => 'NIK / No RM tidak boleh kosong']);
        $stmt = $pdo->prepare("SELECT id, namapasien, nocm FROM pasien_m
                               WHERE statusenabled = true AND (nocm = :a OR noidentitas = :b)
                               LIMIT 1");
        $stmt->execute([':a' => $identifier, ':b' => $identifier]);
        $pasien = $stmt->fetch();
        if ($pasien) {
            session_regenerate_id(true);
            $_SESSION['pasien_id']   = $pasien['id'];
            $_SESSION['pasien_nama'] = $pasien['namapasien'];
            $_SESSION['pasien_nocm'] = $pasien['nocm'];
            respond(['success' => true, 'data' => $pasien]);
        }
        respond(['error' => 'NIK / No RM tidak ditemukan atau tidak aktif']);
        break;

    // ---------- LOGOUT ----------
    case 'logout':
        session_destroy();
        respond(['success' => true]);
        break;

    // ---------- DAFTAR ONLINE ----------
    // Mode A — PASIEN BARU   : payload lengkap (nik, nama, alamat, ...)
    // Mode B — PASIEN LAMA   : payload {ruangan_id, dokter_id, tgl_kunjungan} + session
    case 'daftar_online':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);
        $input = jsonInput();
        $nik          = trim($input['nik'] ?? $input['noidentitas'] ?? '');
        $ruanganId    = !empty($input['ruangan_id']) ? intval($input['ruangan_id']) : null;
        $dokterId     = !empty($input['dokter_id']) ? intval($input['dokter_id']) : null;
        $tglKunjungan = trim($input['tgl_kunjungan'] ?? date('Y-m-d'));

        // ---- Validasi umum kunjungan ----
        if (!$ruanganId) respond(['error' => 'Poliklinik Tujuan wajib dipilih'], 400);
        if (!$dokterId)  respond(['error' => 'Dokter wajib dipilih'], 400);
        if (!validDate($tglKunjungan)) respond(['error' => 'Tanggal kunjungan tidak valid'], 400);
        $hariIni  = strtotime(date('Y-m-d'));
        $tsKunj   = strtotime($tglKunjungan);
        if ($tsKunj < $hariIni)             respond(['error' => 'Tanggal kunjungan tidak boleh mundur'], 400);
        if ($tsKunj > $hariIni + MAX_HARI_RESERVASI * 86400)
            respond(['error' => 'Reservasi maksimal ' . MAX_HARI_RESERVASI . ' hari ke depan'], 400);

        // =================== MODE B: PASIEN TERDAFTAR ===================
        if ($nik === '' && isLoggedIn()) {
            $pasienId = $_SESSION['pasien_id'];

            // Cek reservasi aktif (anti double booking)
            $aktif = findActiveBooking($pdo, $pasienId, date('Y-m-d'));
            if ($aktif) {
                respond(['error' => 'Anda masih memiliki reservasi aktif (' . $aktif['noregistrasi']
                    . ' — ' . ($aktif['namaruangan'] ?: '-') . '). Silakan batalkan terlebih dahulu di tab Riwayat.'], 400);
            }

            $stP = $pdo->prepare("SELECT namapasien, nocm FROM pasien_m WHERE id = :id");
            $stP->execute([':id' => $pasienId]);
            $pasien = $stP->fetch();
            if (!$pasien) respond(['error' => 'Data pasien tidak ditemukan'], 400);

            $ticket = buatRegistrasiDanAntrian($pdo, $pasienId, $pasien['namapasien'], $pasien['nocm'],
                $ruanganId, $dokterId, $tglKunjungan, 'Pasien Lama');
            respond(['success' => true, 'message' => 'Reservasi kunjungan berhasil!', 'data' => $ticket]);
        }

        // =================== MODE A: PASIEN BARU ===================
        $namaPasien      = trim($input['namapasien'] ?? '');
        $tempatLahir     = trim($input['tempatlahir'] ?? '');
        $tglLahir        = trim($input['tgllahir'] ?? '');
        $jenisKelamin    = intval($input['jeniskelamin'] ?? 0); // 1: Perempuan, 2: Laki-laki
        $nohp            = trim($input['nohp'] ?? '');
        $agama           = !empty($input['agama']) ? intval($input['agama']) : null;
        $kebangsaan      = !empty($input['kebangsaan']) ? intval($input['kebangsaan']) : 1;
        $negara          = isset($input['negara']) && $input['negara'] !== '' ? intval($input['negara']) : 0;
        $alamat          = trim($input['alamat'] ?? '');
        $rtrw            = trim($input['rtrw'] ?? '');
        $desakelurahanId = !empty($input['desakelurahan_id']) ? intval($input['desakelurahan_id']) : null;
        $kecamatanId     = !empty($input['kecamatan_id']) ? intval($input['kecamatan_id']) : null;
        $kotakabupatenId = !empty($input['kotakabupaten_id']) ? intval($input['kotakabupaten_id']) : null;
        $provinsiId      = !empty($input['provinsi_id']) ? intval($input['provinsi_id']) : null;
        $kodepos         = trim($input['kodepos'] ?? '');
        $namaIbu         = trim($input['nama_ibu'] ?? '');
        $email           = trim($input['email'] ?? '');
        $statusPerkawinan= !empty($input['status_perkawinan']) ? intval($input['status_perkawinan']) : null;
        $goldar          = !empty($input['goldar']) ? intval($input['goldar']) : null;
        $pendidikan      = !empty($input['pendidikan']) ? intval($input['pendidikan']) : null;
        $pekerjaan       = !empty($input['pekerjaan']) ? intval($input['pekerjaan']) : null;
        $etnis           = !empty($input['etnis']) ? intval($input['etnis']) : null;
        $namaAyah        = trim($input['nama_ayah'] ?? '');
        $namaSuamiIstri  = trim($input['nama_suami_istri'] ?? '');
        $namaPenanggung  = trim($input['nama_penanggung'] ?? '');
        $hubunganPenanggung = !empty($input['hubungan_penanggung']) ? intval($input['hubungan_penanggung']) : null;
        $telpPenanggung  = trim($input['telp_penanggung'] ?? '');
        $jkPenanggung    = !empty($input['jenis_kelamin_penanggung']) ? intval($input['jenis_kelamin_penanggung']) : null;
        $alamatPenanggung= trim($input['alamat_penanggung'] ?? '');

        if ($nik === '')                     respond(['error' => 'NIK / No Identitas wajib diisi'], 400);
        if (!preg_match('/^\d{16}$/', $nik)) respond(['error' => 'NIK harus 16 digit angka'], 400);
        if ($namaPasien === '')              respond(['error' => 'Nama Pasien wajib diisi'], 400);
        if ($tempatLahir === '')             respond(['error' => 'Tempat Lahir wajib diisi'], 400);
        if (!validDate($tglLahir))           respond(['error' => 'Tanggal lahir tidak valid'], 400);
        if (!$jenisKelamin)                  respond(['error' => 'Jenis Kelamin wajib dipilih'], 400);
        if ($nohp === '')                    respond(['error' => 'No HP / Ponsel wajib diisi'], 400);
        if ($alamat === '')                  respond(['error' => 'Alamat Lengkap wajib diisi'], 400);

        try {
            $pdo->beginTransaction();
            // Kunci urutan ID pasien agar tidak rancu
            $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('pasien_id'))");

            // 1. Cek duplikasi NIK
            $stCekNik = $pdo->prepare("SELECT id, nocm, namapasien FROM pasien_m
                                       WHERE noidentitas = :nik AND statusenabled = true LIMIT 1");
            $stCekNik->execute([':nik' => $nik]);
            if ($existing = $stCekNik->fetch()) {
                $pdo->rollBack();
                respond(['error' => 'NIK ini sudah terdaftar atas nama ' . $existing['namapasien']
                    . ' (No RM: ' . $existing['nocm'] . ')'], 400);
            }

            // 2. Generate No CM
            $maxCm = $pdo->query("SELECT MAX(CAST(nocm AS INTEGER)) AS max_cm FROM pasien_m
                                  WHERE nocm ~ '^[0-9]+$' AND LENGTH(nocm) <= 8")->fetch();
            $noCm = str_pad(((int)($maxCm['max_cm'] ?? 0)) + 1, 8, '0', STR_PAD_LEFT);

            // 3. Generate Pasien ID & Norec
            $maxId = $pdo->query("SELECT MAX(CAST(id AS INTEGER)) AS max_id FROM pasien_m WHERE id ~ '^[0-9]+$'")->fetch();
            $pasienId   = (string)(((int)($maxId['max_id'] ?? 0)) + 1);
            $pasienNorec = genUuid();

            // 4. Insert Pasien Baru
            $stInsPasien = $pdo->prepare("INSERT INTO pasien_m (
                id, kdprofile, statusenabled, norec, nocm, namapasien, namaexternal, reportdisplay,
                noidentitas, tempatlahir, tgllahir, objectjeniskelaminfk, nohp, notelepon,
                objectagamafk, objectkebangsaanfk, objectnegarafk, objectstatusperkawinanfk,
                objectgolongandarahfk, objectpendidikanfk, objectpekerjaanfk, objectsukufk,
                namaibu, namaayah, namasuamiistri, email, penanggungjawab, hubungankeluargapj,
                telponpenanggungjawab, jeniskelaminpenanggungjawab, alamatrmh, alamatlengkap, tgldaftar, qpasien
            ) VALUES (
                :id, 1, true, :norec, :nocm, :namapasien, :namapasien, :namapasien,
                :noidentitas, :tempatlahir, :tgllahir, :jeniskelamin, :nohp, :nohp,
                :agama, :kebangsaan, :negara, :status_perkawinan,
                :goldar, :pendidikan, :pekerjaan, :etnis,
                :namaibu, :namaayah, :namasuamiistri, :email, :penanggungjawab, :hubungankeluargapj,
                :telponpenanggungjawab, :jeniskelaminpenanggungjawab, :alamatrmh, :alamatlengkap, NOW(), '1'
            )");
            $stInsPasien->execute([
                ':id' => $pasienId, ':norec' => $pasienNorec, ':nocm' => $noCm,
                ':namapasien' => strtoupper($namaPasien), ':noidentitas' => $nik,
                ':tempatlahir' => strtoupper($tempatLahir), ':tgllahir' => $tglLahir,
                ':jeniskelamin' => $jenisKelamin, ':nohp' => $nohp,
                ':agama' => $agama, ':kebangsaan' => $kebangsaan, ':negara' => $negara,
                ':status_perkawinan' => $statusPerkawinan, ':goldar' => $goldar,
                ':pendidikan' => $pendidikan, ':pekerjaan' => $pekerjaan, ':etnis' => $etnis,
                ':namaibu' => strtoupper($namaIbu), ':namaayah' => strtoupper($namaAyah),
                ':namasuamiistri' => strtoupper($namaSuamiIstri), ':email' => $email,
                ':penanggungjawab' => strtoupper($namaPenanggung), ':hubungankeluargapj' => $hubunganPenanggung,
                ':telponpenanggungjawab' => $telpPenanggung, ':jeniskelaminpenanggungjawab' => $jkPenanggung,
                ':alamatrmh' => strtoupper($alamatPenanggung), ':alamatlengkap' => strtoupper($alamat),
            ]);

            // 5. Insert Alamat Pasien
            $alamatId = (string)($pdo->query("SELECT COALESCE(MAX(CAST(id AS INTEGER)), 0) + 1 AS nid FROM alamat_m WHERE id ~ '^[0-9]+$'")->fetch()['nid']);
            $stInsAlamat = $pdo->prepare("INSERT INTO alamat_m (
                id, kdprofile, statusenabled, norec, nocmfk, alamatlengkap, rtrw,
                objectpropinsifk, objectkotakabupatenfk, objectkecamatanfk, objectdesakelurahanfk,
                kodepos, objectjenisalamatfk, objecthubungankeluargafk, created_at
            ) VALUES (
                :id, 1, true, :norec, :nocmfk, :alamatlengkap, :rtrw,
                :propinsi, :kota, :kecamatan, :desa,
                :kodepos, '1', 1, NOW()
            )");
            $stInsAlamat->execute([
                ':id' => $alamatId, ':norec' => genUuid(), ':nocmfk' => $pasienId,
                ':alamatlengkap' => strtoupper($alamat), ':rtrw' => $rtrw,
                ':propinsi' => $provinsiId, ':kota' => $kotakabupatenId,
                ':kecamatan' => $kecamatanId, ':desa' => $desakelurahanId, ':kodepos' => $kodepos,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['error' => 'Gagal menyimpan data pasien: ' . $e->getMessage()], 500);
        }

        // 6-7. Registrasi + antrian (transaksi tersendiri, jadwal tervalidasi)
        $ticket = buatRegistrasiDanAntrian($pdo, $pasienId, $namaPasien, $noCm,
            $ruanganId, $dokterId, $tglKunjungan, 'Pasien Baru');
        respond(['success' => true, 'message' => 'Pendaftaran online pasien baru berhasil!', 'data' => $ticket]);
        break;

    // ---------- SEARCH DESA / KELURAHAN ----------
    case 'search_desa':
        $query = trim($_GET['q'] ?? $_GET['namadesakelurahan'] ?? '');
        if (strlen($query) < 2) respond(['success' => true, 'data' => []]);
        $st = $pdo->prepare("SELECT dk.id AS id_dk, dk.namadesakelurahan, dk.kodepos,
                    dk.objectkecamatanfk, dk.objectkotakabupatenfk, dk.objectpropinsifk,
                    kc.namakecamatan, kk.namakotakabupaten, prop.namapropinsi
                FROM desakelurahan_m dk
                JOIN kecamatan_m kc ON kc.id = dk.objectkecamatanfk
                JOIN kotakabupaten_m kk ON kk.id = dk.objectkotakabupatenfk
                JOIN propinsi_m prop ON prop.id = dk.objectpropinsifk
                WHERE dk.statusenabled = true
                  AND (dk.namadesakelurahan ILIKE :q OR kc.namakecamatan ILIKE :q)
                ORDER BY dk.namadesakelurahan LIMIT 20");
        $st->execute([':q' => "%$query%"]);
        respond(['success' => true, 'data' => $st->fetchAll()]);
        break;

    // ---------- CEK NIK ----------
    case 'search_nik':
        $nik = trim($_GET['nik'] ?? $_GET['noidentitas'] ?? '');
        if ($nik === '') respond(['error' => 'NIK tidak boleh kosong'], 400);
        $st = $pdo->prepare("SELECT id, nocm, namapasien, tgllahir, nohp FROM pasien_m
                             WHERE noidentitas = :nik AND statusenabled = true LIMIT 1");
        $st->execute([':nik' => $nik]);
        if ($pasien = $st->fetch()) {
            respond(['success' => true, 'exists' => true, 'data' => $pasien,
                     'message' => 'NIK sudah terdaftar atas nama ' . $pasien['namapasien'] . ' (No RM: ' . $pasien['nocm'] . ')']);
        }
        respond(['success' => true, 'exists' => false, 'message' => 'NIK belum terdaftar']);
        break;

    // ---------- MASTER DATA ----------
    case 'get_masters':
        $data = [
            'agama'            => $pdo->query("SELECT id, agama FROM agama_m WHERE statusenabled = true ORDER BY agama")->fetchAll(),
            'kebangsaan'       => $pdo->query("SELECT id, name FROM kebangsaan_m WHERE statusenabled = true ORDER BY name")->fetchAll(),
            'negara'           => $pdo->query("SELECT id, namanegara AS nama FROM negara_m WHERE statusenabled = true ORDER BY namanegara")->fetchAll(),
            'hubungan'         => $pdo->query("SELECT id, hubungankeluarga AS nama FROM hubungankeluarga_m WHERE statusenabled = true ORDER BY id")->fetchAll(),
            'pekerjaan'        => $pdo->query("SELECT id, pekerjaan AS nama FROM pekerjaan_m WHERE statusenabled = true ORDER BY pekerjaan")->fetchAll(),
            'status_perkawinan'=> $pdo->query("SELECT id, statusperkawinan AS nama FROM statusperkawinan_m WHERE statusenabled = true ORDER BY id")->fetchAll(),
            'goldar'           => $pdo->query("SELECT id, golongandarah AS nama FROM golongandarah_m WHERE statusenabled = true ORDER BY golongandarah")->fetchAll(),
            'pendidikan'       => $pdo->query("SELECT id, pendidikan AS nama FROM pendidikan_m WHERE statusenabled = true ORDER BY id")->fetchAll(),
            'etnis'            => $pdo->query("SELECT id, suku AS nama FROM suku_m WHERE statusenabled = true ORDER BY suku")->fetchAll(),
            'poliklinik'       => $pdo->query("SELECT id, namaruangan, prefixnoantrian FROM ruangan_m
                                               WHERE objectdepartemenfk = " . POLI_DEPARTEMEN_FK . " AND statusenabled = true ORDER BY namaruangan")->fetchAll(),
            'dokter'           => $pdo->query("SELECT id, namalengkap FROM pegawai_m
                                               WHERE statusenabled = true AND (objectjenispegawaifk = 1 OR namalengkap ILIKE 'dr%')
                                               ORDER BY namalengkap")->fetchAll(),
            'kelompok_pasien'  => $pdo->query("SELECT id, kelompokpasien FROM kelompokpasien_m WHERE statusenabled = true ORDER BY id")->fetchAll(),
        ];
        if (empty($data['negara'])) $data['negara'] = [['id' => 0, 'nama' => 'Indonesia']];
        respond(['success' => true, 'data' => $data]);
        break;

    // ---------- DAFTAR POLIKLINIK ----------
    case 'get_poliklinik_list':
        $rows = $pdo->query("SELECT id, namaruangan, prefixnoantrian FROM ruangan_m
                             WHERE objectdepartemenfk = " . POLI_DEPARTEMEN_FK . " AND statusenabled = true
                             ORDER BY namaruangan")->fetchAll();
        respond(['success' => true, 'data' => $rows]);
        break;

    // ---------- JADWAL DOKTER (per poliklinik + tanggal) ----------
    case 'get_jadwal_dokter':
        $tanggal    = validDate($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : date('Y-m-d');
        $ruanganId  = intval($_GET['ruangan_id'] ?? $_GET['poliklinik_id'] ?? $_GET['ruangan'] ?? 0);
        if (!$ruanganId) respond(['error' => 'ruangan_id wajib diisi'], 400);

        [$detected, $rows] = fetchJadwalRows($pdo, $tanggal, $ruanganId);
        if (!$detected) respond(['success' => false, 'error' => 'Tabel jadwal dokter tidak ditemukan di database (set env RSUD_JADWAL_TABLE bila nama tabel berbeda)'], 500);

        $ts = strtotime($tanggal);
        $namaHari = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][(int)date('N', $ts) - 1];
        $out = [];
        foreach ($rows as $r) {
            $quota = (int)$r['quota'];
            $out[] = [
                'id'          => 'J' . $r['dokter_id'] . '-' . $r['ruangan_id'],
                'dokter_id'   => (int)$r['dokter_id'],
                'ruangan_id'  => (int)$r['ruangan_id'],
                'namadokter'  => $r['namalengkap'],
                'namaruangan' => $r['namaruangan'],
                'jammulai'    => substr($r['jammulai'] ?? '', 0, 5),
                'jamakhir'    => substr($r['jamakhir'] ?? '', 0, 5),
                'hari'        => $namaHari,
                'tanggal'     => $tanggal,
                'quota'       => $quota,
                'sisa_quota'  => max(0, $quota - (int)$r['terpakai']),
            ];
        }
        respond(['success' => true, 'tanggal' => $tanggal, 'data' => $out]);
        break;

    // ---------- DOKTER SESUAI JADWAL (untuk form pendaftaran) ----------
    case 'get_dokter_by_jadwal':
        $tanggal   = validDate($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : date('Y-m-d');
        $ruanganId = intval($_GET['ruangan_id'] ?? $_GET['poliklinik_id'] ?? $_GET['ruangan'] ?? 0);
        if (!$ruanganId) respond(['error' => 'ruangan_id wajib diisi'], 400);

        [$detected, $rows] = fetchJadwalRows($pdo, $tanggal, $ruanganId);
        if (!$detected) {
            // Skema jadwal tidak dikenali → beri tanda agar pesan frontend jelas
            respond(['success' => true, 'data' => [], 'detected' => false,
                     'message' => 'Tabel jadwal dokter tidak dikenali di server']);
        }
        $ts = strtotime($tanggal);
        $namaHari = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'][(int)date('N', $ts) - 1];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['dokter_id'], // = dokter_id (dipakai langsung frontend)
                'namalengkap'=> $r['namalengkap'],
                'jammulai'   => substr($r['jammulai'] ?? '', 0, 5),
                'jamakhir'   => substr($r['jamakhir'] ?? '', 0, 5),
                'hari'       => $namaHari,
                'tanggal'    => $tanggal,
                'quota'      => (int)$r['quota'],
                'sisa_quota' => max(0, (int)$r['quota'] - (int)$r['terpakai']),
            ];
        }
        respond(['success' => true, 'data' => $out, 'detected' => true]);
        break;

    // ---------- CEK RESERVASI AKTIF ----------
    case 'check_active_booking':
        $pasienId = requireLogin();
        $aktif = findActiveBooking($pdo, $pasienId, date('Y-m-d'));
        if ($aktif) {
            respond(['success' => true, 'has_active_booking' => true,
                     'message' => 'Anda sudah memiliki reservasi aktif (' . $aktif['noregistrasi']
                                . ' — ' . ($aktif['namaruangan'] ?: '-') . ', '
                                . date('d-m-Y', strtotime($aktif['tglregistrasi'])) . ').',
                     'data' => $aktif]);
        }
        respond(['success' => true, 'has_active_booking' => false, 'message' => 'Tidak ada reservasi aktif.']);
        break;

    // ---------- PROFILE ----------
    case 'get_profile':
        $pasienId = requireLogin();
        $st = $pdo->prepare("SELECT id, namapasien, nocm, tgllahir, objectjeniskelaminfk
                             FROM pasien_m WHERE id = :id");
        $st->execute([':id' => $pasienId]);
        if (!$profile = $st->fetch()) respond(['error' => 'Data pasien tidak ditemukan'], 404);
        $jenisKelamin = '-';
        if (!empty($profile['objectjeniskelaminfk'])) {
            $stJk = $pdo->prepare("SELECT jeniskelamin FROM jeniskelamin_m WHERE id = :id");
            $stJk->execute([':id' => $profile['objectjeniskelaminfk']]);
            $jenisKelamin = $stJk->fetch()['jeniskelamin'] ?? '-';
        }
        $umur = '-';
        if (!empty($profile['tgllahir'])) {
            $diff = (new DateTime())->diff(new DateTime($profile['tgllahir']));
            $umur = $diff->y > 0 ? $diff->y . ' thn'
                  : ($diff->m > 0 ? $diff->m . ' bln' : $diff->d . ' hr');
        }
        $profile['jenis_kelamin'] = $jenisKelamin;
        $profile['umur'] = $umur;
        unset($profile['objectjeniskelaminfk']);
        respond(['success' => true, 'data' => $profile]);
        break;

    // ---------- ORDER LAB & RAD ----------
    case 'get_orders':
        $pasienId = requireLogin();
        $stReg = $pdo->prepare("SELECT norec FROM pasiendaftar_t WHERE nocmfk = :pid AND statusenabled = true");
        $stReg->execute([':pid' => $pasienId]);
        $norecList = array_column($stReg->fetchAll(), 'norec');

        $orders = [];
        if (!empty($norecList)) {
            $in = implode(',', array_fill(0, count($norecList), '?'));
            $sql = "SELECT so.norec AS norec_so, so.noorder, so.tglorder, so.keteranganorder,
                           so.statusorder, so.noregistrasi, so.norec_apd,
                           ru.namaruangan AS ruangan_tujuan, ruasal.namaruangan AS ruangan_asal,
                           pg_order.namalengkap AS dokter_order,
                           pr.namaproduk, pr.id AS produk_id, pp.norec AS norec_pp,
                           hr.keterangan AS expertise, hr.norec AS norec_exper,
                           pg_baca.namalengkap AS dokter_baca
                    FROM strukorder_t so
                    JOIN ruangan_m ru ON ru.id = so.objectruangantujuanfk
                    LEFT JOIN ruangan_m ruasal ON ruasal.id = so.objectruanganfk
                    LEFT JOIN pegawai_m pg_order ON pg_order.id = so.objectpegawaiorderfk
                    LEFT JOIN pelayananpasien_t pp ON pp.strukorderfk = so.norec AND pp.statusenabled = true
                    LEFT JOIN produk_m pr ON pr.id = pp.produkfk
                    LEFT JOIN hasilradiologi_t hr ON hr.pelayananpasienfk = pp.norec AND hr.statusenabled = true
                    LEFT JOIN pegawai_m pg_baca ON pg_baca.id = hr.pegawaifk
                    WHERE so.noregistrasifk IN ($in)
                      AND so.keteranganorder IN ('Order Laboratorium', 'Order Radiologi')
                      AND so.statusenabled = true
                    ORDER BY so.tglorder DESC, so.noorder";
            $st = $pdo->prepare($sql);
            $st->execute($norecList);
            foreach ($st->fetchAll() as $row) {
                $key = $row['norec_so'];
                if (!isset($orders[$key])) {
                    $orders[$key] = [
                        'norec_so' => $row['norec_so'], 'noorder' => $row['noorder'],
                        'tglorder' => $row['tglorder'], 'keteranganorder' => $row['keteranganorder'],
                        'statusorder' => $row['statusorder'], 'noregistrasi' => $row['noregistrasi'],
                        'norec_apd' => $row['norec_apd'] ?? '', 'ruangan_tujuan' => $row['ruangan_tujuan'],
                        'ruangan_asal' => $row['ruangan_asal'] ?? '-', 'dokter_order' => $row['dokter_order'] ?? '-',
                        'dokter_baca' => $row['dokter_baca'] ?? '-', 'expertise' => $row['expertise'],
                        'norec_exper' => $row['norec_exper'], 'produk' => [],
                    ];
                }
                if (!empty($row['namaproduk'])
                    && !in_array($row['produk_id'], array_column($orders[$key]['produk'], 'produk_id'))) {
                    $orders[$key]['produk'][] = [
                        'namaproduk' => $row['namaproduk'], 'produk_id' => $row['produk_id'],
                        'norec_pp' => $row['norec_pp'], 'norec_exper' => $row['norec_exper'],
                    ];
                }
            }
        }
        $lab = array_values(array_filter($orders, fn($o) => $o['keteranganorder'] == 'Order Laboratorium' && $o['statusorder'] != 0));
        $rad = array_values(array_filter($orders, fn($o) => $o['keteranganorder'] == 'Order Radiologi' && $o['statusorder'] != 0));
        respond(['success' => true, 'lab' => $lab, 'rad' => $rad]);
        break;

    // ---------- RIWAYAT KUNJUNGAN ----------
    case 'get_riwayat':
        $pasienId = requireLogin();
        $sql = "SELECT pd.norec, pd.noregistrasi, pd.tglregistrasi, pd.tglpulang, pd.ischeckin,
                       ru.namaruangan, pg.namalengkap AS namadokter,
                       a.noantrian, a.prefixnoantrian,
                       CASE WHEN pd.statusenabled = false THEN 'Dibatalkan'
                            WHEN pd.tglpulang IS NOT NULL THEN 'Selesai'
                            ELSE 'Aktif' END AS status,
                       CASE WHEN d.namadepartemen ILIKE '%rawat inap%' THEN 'Rawat Inap'
                            ELSE 'Rawat Jalan' END AS jenis_rawat
                FROM pasiendaftar_t pd
                LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
                LEFT JOIN departemen_m d ON d.id = ru.objectdepartemenfk
                LEFT JOIN antrianpasiendiperiksa_t a
                       ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
                LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
                WHERE pd.nocmfk = :pid
                ORDER BY pd.tglregistrasi DESC";
        $st = $pdo->prepare($sql);
        $st->execute([':pid' => $pasienId]);
        $data = $st->fetchAll();
        foreach ($data as &$r) {
            $r['is_checkin']     = (bool)$r['ischeckin'];
            $r['noantrian_full'] = !empty($r['prefixnoantrian']) && $r['noantrian'] !== null
                ? $r['prefixnoantrian'] . '-' . str_pad((string)$r['noantrian'], 3, '0', STR_PAD_LEFT)
                : null;
            unset($r['ischeckin'], $r['noantrian'], $r['prefixnoantrian']);
        }
        respond(['success' => true, 'data' => $data]);
        break;

    // ---------- DETAIL TIKET ----------
    case 'get_ticket_detail':
        $pasienId = requireLogin();
        $noreg = trim($_GET['noregistrasi'] ?? '');
        if ($noreg === '') respond(['error' => 'noregistrasi wajib diisi'], 400);
        $st = $pdo->prepare("SELECT pd.noregistrasi, pd.tglregistrasi, pd.tglpulang, pd.ischeckin,
                                    p.namapasien, p.nocm,
                                    ru.namaruangan AS poliklinik, pg.namalengkap AS dokter,
                                    a.noantrian, a.prefixnoantrian, a.statusantrian,
                                    CASE WHEN pd.statusenabled = false THEN 'Dibatalkan'
                                         WHEN pd.tglpulang IS NOT NULL THEN 'Selesai'
                                         ELSE 'Aktif' END AS status
                             FROM pasiendaftar_t pd
                             JOIN pasien_m p ON p.id = pd.nocmfk
                             LEFT JOIN ruangan_m ru ON ru.id = pd.objectruanganlastfk
                             LEFT JOIN antrianpasiendiperiksa_t a
                                    ON a.noregistrasi = pd.noregistrasi AND a.statusenabled = true
                             LEFT JOIN pegawai_m pg ON pg.id = a.objectpegawaifk
                             WHERE pd.noregistrasi = :noreg AND pd.nocmfk = :pid
                             LIMIT 1");
        $st->execute([':noreg' => $noreg, ':pid' => $pasienId]);
        if (!$t = $st->fetch()) respond(['error' => 'Data registrasi tidak ditemukan atau bukan milik Anda'], 404);
        $t['noantrian_full'] = !empty($t['prefixnoantrian']) && $t['noantrian'] !== null
            ? $t['prefixnoantrian'] . '-' . str_pad((string)$t['noantrian'], 3, '0', STR_PAD_LEFT) : '-';
        $t['is_checkin'] = (bool)$t['ischeckin'];
        unset($t['noantrian'], $t['prefixnoantrian'], $t['ischeckin']);
        respond(['success' => true, 'data' => $t]);
        break;

    // ---------- CHECK-IN (scan QR loket admisi) ----------
    case 'checkin':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);
        $pasienId = requireLogin();
        $input = jsonInput();
        $noreg   = trim($input['noregistrasi'] ?? '');
        $barcode = trim($input['barcode'] ?? '');
        if ($noreg === '') respond(['error' => 'noregistrasi wajib diisi'], 400);
        if ($barcode === '') respond(['error' => 'Barcode tidak valid'], 400);

        // Barcode umum harian: CHECKIN-RSUD-MALANGBONG-YYYYMMDD (H-1 s/d H+1)
        if (!preg_match('/^CHECKIN-RSUD-MALANGBONG-\d{8}$/', $barcode)) {
            respond(['error' => 'Barcode tidak valid. Silakan scan QR Code di loket admisi.'], 400);
        }
        $tanggalBarcode = substr($barcode, -8);
        $doa = [date('Ymd', strtotime('-1 day')), date('Ymd'), date('Ymd', strtotime('+1 day'))];
        if (!in_array($tanggalBarcode, $doa, true)) {
            respond(['error' => 'QR Code sudah kedaluwarsa. Scan QR terbaru di loket admisi.'], 400);
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT pd.norec, pd.noregistrasi, pd.statusenabled, pd.tglpulang, pd.ischeckin,
                                    ap.norec AS norec_apd, ap.statusantrian
                             FROM pasiendaftar_t pd
                             LEFT JOIN antrianpasiendiperiksa_t ap
                                    ON ap.noregistrasi = pd.noregistrasi AND COALESCE(ap.statusenabled, true) = true
                             WHERE pd.noregistrasi = :noreg AND pd.nocmfk = :pid
                             LIMIT 1 FOR UPDATE OF pd");
        $st->execute([':noreg' => $noreg, ':pid' => $pasienId]);
        if (!$reg = $st->fetch()) { $pdo->rollBack(); respond(['error' => 'Data registrasi tidak ditemukan atau bukan milik Anda'], 404); }
        if (!$reg['statusenabled'])            { $pdo->rollBack(); respond(['error' => 'Registrasi tidak aktif atau sudah dibatalkan'], 400); }
        if (!empty($reg['tglpulang']))         { $pdo->rollBack(); respond(['error' => 'Kunjungan sudah selesai'], 400); }
        if ($reg['statusantrian'] == 2)        { $pdo->rollBack(); respond(['error' => 'Kunjungan sudah selesai pemeriksaan'], 400); }
        if ($reg['ischeckin'])                 { $pdo->rollBack(); respond(['error' => 'Check-in sudah dilakukan sebelumnya'], 400); }

        // No struk: S + 9 digit urut
        $maxStruk = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(nostruk FROM 2) AS BIGINT)), 0) AS m
                                 FROM strukpelayanan_t
                                 WHERE nostruk LIKE 'S%' AND LENGTH(nostruk) > 1
                                   AND SUBSTRING(nostruk FROM 2) ~ '^[0-9]+$'")->fetch();
        $noStruk = 'S' . str_pad(((int)($maxStruk['m'] ?? 0)) + 1, 9, '0', STR_PAD_LEFT);

        $stStruk = $pdo->prepare("INSERT INTO strukpelayanan_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
                tglstruk, totalharusdibayar, nostruk, objectkelompoktransaksifk, created_at
            ) VALUES (:norec, 1, true, :noregfk, :noregistrasi, NOW(), " . BIAYA_REGISTRASI . ", :nostruk, "
                . KELOMPOK_TRANSAKSI_STRUK . ", NOW())");
        $stStruk->execute([':norec' => genUuid(), ':noregfk' => $reg['norec'], ':noregistrasi' => $noreg, ':nostruk' => $noStruk]);

        $stPel = $pdo->prepare("INSERT INTO pelayananpasien_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi, tglregistrasi,
                produkfk, jumlah, hargasatuan, hargajual, harganetto, kelasfk,
                kdkelompoktransaksi, keteranganlain, ischeckin, created_at
            ) VALUES (:norec, 1, true, :noregfk, :noregistrasi, NOW(), " . PRODUK_BIAYA_REGISTRASI . ",
                1, " . BIAYA_REGISTRASI . ", " . BIAYA_REGISTRASI . ", " . BIAYA_REGISTRASI . ", " . KELAS_FK . ",
                " . KELOMPOK_TRANSAKSI_STRUK . ", 'BIAYA REGISTRASI CHECKIN', true, NOW())");
        $stPel->execute([':norec' => genUuid(), ':noregfk' => $reg['norec'], ':noregistrasi' => $noreg]);

        $pdo->prepare("UPDATE pasiendaftar_t SET ischeckin = true WHERE norec = :n")->execute([':n' => $reg['norec']]);
        if (!empty($reg['norec_apd'])) {
            $pdo->prepare("UPDATE antrianpasiendiperiksa_t SET statusantrian = '1' WHERE norec = :n")
                ->execute([':n' => $reg['norec_apd']]);
        }
        $pdo->commit();
        respond(['success' => true,
                 'message' => 'Check-in berhasil! Tagihan registrasi Rp ' . number_format(BIAYA_REGISTRASI, 0, ',', '.') . ' telah ditambahkan.',
                 'data' => ['noregistrasi' => $noreg, 'nostruk' => $noStruk, 'tgl_checkin' => date('Y-m-d H:i:s')]]);
        break;

    // ---------- BATAL RESERVASI ----------
    case 'cancel_reservation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);
        $pasienId = requireLogin();
        $input = jsonInput();
        $noreg = trim($input['noregistrasi'] ?? '');
        if ($noreg === '') respond(['error' => 'noregistrasi wajib diisi'], 400);

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT pd.norec, pd.tglpulang, pd.ischeckin, ap.statusantrian
                             FROM pasiendaftar_t pd
                             LEFT JOIN antrianpasiendiperiksa_t ap ON ap.noregistrasi = pd.noregistrasi
                             WHERE pd.noregistrasi = :noreg AND pd.nocmfk = :pid
                             LIMIT 1 FOR UPDATE OF pd");
        $st->execute([':noreg' => $noreg, ':pid' => $pasienId]);
        if (!$reg = $st->fetch()) { $pdo->rollBack(); respond(['error' => 'Data registrasi tidak ditemukan atau bukan milik Anda'], 404); }
        if (!$reg['tglpulang'] && $reg['ischeckin']) { $pdo->rollBack(); respond(['error' => 'Reservasi sudah check-in, tidak dapat dibatalkan'], 400); }
        if (!empty($reg['tglpulang']))               { $pdo->rollBack(); respond(['error' => 'Kunjungan sudah selesai, tidak dapat dibatalkan'], 400); }
        if ($reg['statusantrian'] !== null && (int)$reg['statusantrian'] >= 1) {
            $pdo->rollBack(); respond(['error' => 'Reservasi sudah diproses, tidak dapat dibatalkan'], 400);
        }

        $pdo->prepare("UPDATE pasiendaftar_t SET statusenabled = false WHERE norec = :n")->execute([':n' => $reg['norec']]);
        $pdo->prepare("UPDATE antrianpasiendiperiksa_t SET statusenabled = false
                       WHERE noregistrasi = :noreg AND statusenabled = true")->execute([':noreg' => $noreg]);
        $pdo->commit();
        respond(['success' => true, 'message' => "Reservasi $noreg berhasil dibatalkan."]);
        break;

    // ---------- DEFAULT ----------
    default:
        respond(['error' => 'Aksi tidak valid'], 400);
}
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => 'Server error: ' . $e->getMessage()], 500);
}
