<?php
// api.php - Backend API untuk aplikasi pasien

// ============================================================
// CORS HEADERS - Penting untuk akses dari domain lain
// ============================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,      // true jika HTTPS
    'httponly' => true,
    'samesite' => 'None'
]);




// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// SESSION START
// ============================================================
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,          // karena sekarang pakai HTTPS
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();
// ============================================================
// KONEKSI DATABASE
// ============================================================
$host = "192.168.22.81";
$port = "5792";
$dbname = "rsud_malangbong";
$user = "postgres";
$password = "Tr4nsm3d!c MaRe#T3aM";

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Koneksi database gagal: ' . $e->getMessage()]);
    exit;
}

// ============================================================
// HELPER FUNCTIONS
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

// ============================================================
// HANDLE REQUEST
// ============================================================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    // ---------- LOGIN ----------
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['error' => 'Method not allowed'], 405);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $nocm = trim($input['nocm'] ?? '');
        if (empty($nocm)) {
            respond(['error' => 'No RM tidak boleh kosong']);
        }
        $stmt = $pdo->prepare("SELECT id, namapasien, nocm FROM pasien_m WHERE nocm = :nocm AND statusenabled = true");
        $stmt->execute([':nocm' => $nocm]);
        $pasien = $stmt->fetch();
        if ($pasien) {
            $_SESSION['pasien_id'] = $pasien['id'];
            $_SESSION['pasien_nama'] = $pasien['namapasien'];
            $_SESSION['pasien_nocm'] = $pasien['nocm'];
            respond(['success' => true, 'data' => $pasien]);
        } else {
            respond(['error' => 'No RM tidak ditemukan atau tidak aktif']);
        }
        break;

    // ---------- LOGOUT ----------
    case 'logout':
        session_destroy();
        respond(['success' => true]);
        break;

    // ---------- DAFTAR ONLINE (PASIEN BARU PEMBAYARAN UMUM) ----------
    case 'daftar_online':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['error' => 'Method not allowed'], 405);
        }
        $input = json_decode(file_get_contents('php://input'), true);

        $nik = trim($input['nik'] ?? $input['noidentitas'] ?? '');
        $namaPasien = trim($input['namapasien'] ?? '');
        $tempatLahir = trim($input['tempatlahir'] ?? '');
        $tglLahir = trim($input['tgllahir'] ?? '');
        $jenisKelamin = intval($input['jeniskelamin'] ?? 0); // 1: Perempuan, 2: Laki-laki
        $nohp = trim($input['nohp'] ?? '');
        $agama = !empty($input['agama']) ? intval($input['agama']) : null;
        $kebangsaan = !empty($input['kebangsaan']) ? intval($input['kebangsaan']) : 1;
        $negara = isset($input['negara']) && $input['negara'] !== '' ? intval($input['negara']) : 0;
        
        $alamat = trim($input['alamat'] ?? '');
        $rtrw = trim($input['rtrw'] ?? '');
        $desakelurahanId = !empty($input['desakelurahan_id']) ? intval($input['desakelurahan_id']) : null;
        $kecamatanId = !empty($input['kecamatan_id']) ? intval($input['kecamatan_id']) : null;
        $kotakabupatenId = !empty($input['kotakabupaten_id']) ? intval($input['kotakabupaten_id']) : null;
        $provinsiId = !empty($input['provinsi_id']) ? intval($input['provinsi_id']) : null;
        $kodepos = trim($input['kodepos'] ?? '');
        
        $namaIbu = trim($input['nama_ibu'] ?? '');
        $email = trim($input['email'] ?? '');
        $statusPerkawinan = !empty($input['status_perkawinan']) ? intval($input['status_perkawinan']) : null;
        $goldar = !empty($input['goldar']) ? intval($input['goldar']) : null;
        $pendidikan = !empty($input['pendidikan']) ? intval($input['pendidikan']) : null;
        $pekerjaan = !empty($input['pekerjaan']) ? intval($input['pekerjaan']) : null;
        $etnis = !empty($input['etnis']) ? intval($input['etnis']) : null;
        $namaAyah = trim($input['nama_ayah'] ?? '');
        $namaSuamiIstri = trim($input['nama_suami_istri'] ?? '');

        // Penanggung Jawab
        $namaPenanggung = trim($input['nama_penanggung'] ?? '');
        $hubunganPenanggung = !empty($input['hubungan_penanggung']) ? intval($input['hubungan_penanggung']) : null;
        $telpPenanggung = trim($input['telp_penanggung'] ?? '');
        $jenisKelaminPenanggung = !empty($input['jenis_kelamin_penanggung']) ? intval($input['jenis_kelamin_penanggung']) : null;
        $alamatPenanggung = trim($input['alamat_penanggung'] ?? '');

        // Kunjungan Poliklinik & Dokter
        $ruanganId = !empty($input['ruangan_id']) ? intval($input['ruangan_id']) : null;
        $dokterId = !empty($input['dokter_id']) ? intval($input['dokter_id']) : null;
        $tglKunjungan = trim($input['tgl_kunjungan'] ?? date('Y-m-d'));

        // Validasi
        if (empty($nik)) respond(['error' => 'NIK / No Identitas wajib diisi']);
        if (empty($namaPasien)) respond(['error' => 'Nama Pasien wajib diisi']);
        if (empty($tempatLahir)) respond(['error' => 'Tempat Lahir wajib diisi']);
        if (empty($tglLahir)) respond(['error' => 'Tanggal Lahir wajib diisi']);
        if (empty($jenisKelamin)) respond(['error' => 'Jenis Kelamin wajib dipilih']);
        if (empty($nohp)) respond(['error' => 'No HP / Ponsel wajib diisi']);
        if (empty($alamat)) respond(['error' => 'Alamat Lengkap wajib diisi']);
        if (empty($ruanganId)) respond(['error' => 'Poliklinik Tujuan wajib dipilih']);
        if (empty($dokterId)) respond(['error' => 'Dokter wajib dipilih']);

        try {
            $pdo->beginTransaction();

            // 1. Cek duplikasi NIK
            $stmtCekNik = $pdo->prepare("SELECT id, nocm, namapasien FROM pasien_m WHERE noidentitas = :nik AND statusenabled = true LIMIT 1");
            $stmtCekNik->execute([':nik' => $nik]);
            $existing = $stmtCekNik->fetch();
            if ($existing) {
                $pdo->rollBack();
                respond(['error' => "NIK ini sudah terdaftar atas nama " . $existing['namapasien'] . " (No RM: " . $existing['nocm'] . ")"]);
            }

            // 2. Generate No CM
            $stmtMaxCm = $pdo->query("SELECT MAX(CAST(nocm AS INTEGER)) AS max_cm FROM pasien_m WHERE nocm ~ '^[0-9]+$' AND LENGTH(nocm) <= 8");
            $maxRow = $stmtMaxCm->fetch();
            $nextCmInt = ($maxRow['max_cm'] ?? 0) + 1;
            $noCm = str_pad($nextCmInt, 8, '0', STR_PAD_LEFT);

            // 3. Generate Pasien ID & Norec
            $stmtMaxId = $pdo->query("SELECT MAX(CAST(id AS INTEGER)) AS max_id FROM pasien_m WHERE id ~ '^[0-9]+$'");
            $maxIdRow = $stmtMaxId->fetch();
            $pasienId = (string)(($maxIdRow['max_id'] ?? 0) + 1);
            $pasienNorec = sprintf('%08x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));

            // 4. Insert Pasien Baru
            $sqlPasien = "INSERT INTO pasien_m (
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
            )";

            $stmtInsPasien = $pdo->prepare($sqlPasien);
            $stmtInsPasien->execute([
                ':id' => $pasienId,
                ':norec' => $pasienNorec,
                ':nocm' => $noCm,
                ':namapasien' => strtoupper($namaPasien),
                ':noidentitas' => $nik,
                ':tempatlahir' => strtoupper($tempatLahir),
                ':tgllahir' => $tglLahir,
                ':jeniskelamin' => $jenisKelamin,
                ':nohp' => $nohp,
                ':agama' => $agama,
                ':kebangsaan' => $kebangsaan,
                ':negara' => $negara,
                ':status_perkawinan' => $statusPerkawinan,
                ':goldar' => $goldar,
                ':pendidikan' => $pendidikan,
                ':pekerjaan' => $pekerjaan,
                ':etnis' => $etnis,
                ':namaibu' => strtoupper($namaIbu),
                ':namaayah' => strtoupper($namaAyah),
                ':namasuamiistri' => strtoupper($namaSuamiIstri),
                ':email' => $email,
                ':penanggungjawab' => strtoupper($namaPenanggung),
                ':hubungankeluargapj' => $hubunganPenanggung,
                ':telponpenanggungjawab' => $telpPenanggung,
                ':jeniskelaminpenanggungjawab' => $jenisKelaminPenanggung,
                ':alamatrmh' => strtoupper($alamatPenanggung),
                ':alamatlengkap' => strtoupper($alamat)
            ]);

            // 5. Insert Alamat Pasien
            $alamatNorec = sprintf('%08x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));
            $stmtMaxAlmId = $pdo->query("SELECT MAX(CAST(id AS INTEGER)) AS max_id FROM alamat_m WHERE id ~ '^[0-9]+$'");
            $maxAlmRow = $stmtMaxAlmId->fetch();
            $alamatId = (string)(($maxAlmRow['max_id'] ?? 0) + 1);

            $sqlAlamat = "INSERT INTO alamat_m (
                id, kdprofile, statusenabled, norec, nocmfk, alamatlengkap, rtrw,
                objectpropinsifk, objectkotakabupatenfk, objectkecamatanfk, objectdesakelurahanfk,
                kodepos, objectjenisalamatfk, objecthubungankeluargafk, created_at
            ) VALUES (
                :id, 1, true, :norec, :nocmfk, :alamatlengkap, :rtrw,
                :propinsi, :kota, :kecamatan, :desa,
                :kodepos, '1', 1, NOW()
            )";
            $stmtInsAlamat = $pdo->prepare($sqlAlamat);
            $stmtInsAlamat->execute([
                ':id' => $alamatId,
                ':norec' => $alamatNorec,
                ':nocmfk' => $pasienId,
                ':alamatlengkap' => strtoupper($alamat),
                ':rtrw' => $rtrw,
                ':propinsi' => $provinsiId,
                ':kota' => $kotakabupatenId,
                ':kecamatan' => $kecamatanId,
                ':desa' => $desakelurahanId,
                ':kodepos' => $kodepos
            ]);

            // 6. Registrasi Pasien (pasiendaftar_t) - Pembayaran UMUM (id = 1)
            $regNorec = sprintf('%08x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));
            $prefixReg = 'REG' . date('ymd');
            $stmtMaxReg = $pdo->query("SELECT COUNT(*) AS count_reg FROM pasiendaftar_t WHERE noregistrasi LIKE '$prefixReg%'");
            $countReg = ($stmtMaxReg->fetch()['count_reg'] ?? 0) + 1;
            $noRegistrasi = $prefixReg . str_pad($countReg, 4, '0', STR_PAD_LEFT);

            $sqlPasiendaftar = "INSERT INTO pasiendaftar_t (
                norec, kdprofile, statusenabled, noregistrasi, nocmfk, tglregistrasi,
                objectruanganlastfk, objectpegawaifk, objectkelompokpasienlastfk, objectkelasfk,
                statuspasien, created_at
            ) VALUES (
                :norec, 1, true, :noregistrasi, :nocmfk, :tglregistrasi,
                :ruangan, :dokter, 1, 6,
                'Pasien Baru', NOW()
            )";
            $stmtInsReg = $pdo->prepare($sqlPasiendaftar);
            $stmtInsReg->execute([
                ':norec' => $regNorec,
                ':noregistrasi' => $noRegistrasi,
                ':nocmfk' => $pasienId,
                ':tglregistrasi' => $tglKunjungan . ' ' . date('H:i:s'),
                ':ruangan' => $ruanganId,
                ':dokter' => $dokterId
            ]);

            // 7. Queue Antrian (antrianpasiendiperiksa_t)
            $apdNorec = sprintf('%08x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffff));
            $stmtRuangan = $pdo->prepare("SELECT namaruangan, prefixnoantrian FROM ruangan_m WHERE id = :id");
            $stmtRuangan->execute([':id' => $ruanganId]);
            $ruanganInfo = $stmtRuangan->fetch();
            $namaRuangan = $ruanganInfo['namaruangan'] ?? 'Poliklinik';
            $prefixAntrian = !empty($ruanganInfo['prefixnoantrian']) ? trim($ruanganInfo['prefixnoantrian']) : 'A';

            $stmtDokter = $pdo->prepare("SELECT namalengkap FROM pegawai_m WHERE id = :id");
            $stmtDokter->execute([':id' => $dokterId]);
            $dokterInfo = $stmtDokter->fetch();
            $namaDokter = $dokterInfo['namalengkap'] ?? '-';

            $stmtCountApd = $pdo->prepare("SELECT COUNT(*) AS count_antrian FROM antrianpasiendiperiksa_t WHERE objectruanganfk = :ruangan AND DATE(tglregistrasi) = :tgl");
            $stmtCountApd->execute([':ruangan' => $ruanganId, ':tgl' => $tglKunjungan]);
            $noAntrian = ($stmtCountApd->fetch()['count_antrian'] ?? 0) + 1;

            $sqlApd = "INSERT INTO antrianpasiendiperiksa_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
                objectruanganfk, objectpegawaifk, objectkelasfk, kelasfk, noantrian, prefixnoantrian,
                tglregistrasi, statusantrian, created_at
            ) VALUES (
                :norec, 1, true, :noreg_fk, :noregistrasi,
                :ruangan, :dokter, 6, 6, :noantrian, :prefix,
                :tglregistrasi, '0', NOW()
            )";
            $stmtInsApd = $pdo->prepare($sqlApd);
            $stmtInsApd->execute([
                ':norec' => $apdNorec,
                ':noreg_fk' => $regNorec,
                ':noregistrasi' => $noRegistrasi,
                ':ruangan' => $ruanganId,
                ':dokter' => $dokterId,
                ':noantrian' => $noAntrian,
                ':prefix' => $prefixAntrian,
                ':tglregistrasi' => $tglKunjungan . ' ' . date('H:i:s')
            ]);

            $pdo->commit();

            respond([
                'success' => true,
                'message' => 'Pendaftaran online pasien baru berhasil!',
                'data' => [
                    'nocm' => $noCm,
                    'noregistrasi' => $noRegistrasi,
                    'noantrian' => $prefixAntrian . '-' . str_pad($noAntrian, 3, '0', STR_PAD_LEFT),
                    'namapasien' => strtoupper($namaPasien),
                    'tgl_kunjungan' => $tglKunjungan,
                    'poliklinik' => $namaRuangan,
                    'dokter' => $namaDokter
                ]
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            respond(['error' => 'Gagal mendaftar: ' . $e->getMessage()], 500);
        }
        break;

    // ---------- SEARCH DESA / KELURAHAN ----------
    case 'search_desa':
        $query = trim($_GET['q'] ?? $_GET['namadesakelurahan'] ?? '');
        if (strlen($query) < 2) {
            respond(['success' => true, 'data' => []]);
        }
        $sql = "SELECT 
                    dk.id as id_dk, 
                    dk.namadesakelurahan, 
                    dk.kodepos, 
                    dk.objectkecamatanfk, 
                    dk.objectkotakabupatenfk, 
                    dk.objectpropinsifk, 
                    kc.namakecamatan, 
                    kk.namakotakabupaten, 
                    prop.namapropinsi 
                FROM desakelurahan_m dk 
                JOIN kecamatan_m kc ON kc.id = dk.objectkecamatanfk 
                JOIN kotakabupaten_m kk ON kk.id = dk.objectkotakabupatenfk 
                JOIN propinsi_m prop ON prop.id = dk.objectpropinsifk 
                WHERE dk.statusenabled = true 
                  AND (dk.namadesakelurahan ILIKE :q OR kc.namakecamatan ILIKE :q) 
                ORDER BY dk.namadesakelurahan 
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => "%$query%"]);
        $desaList = $stmt->fetchAll();
        respond(['success' => true, 'data' => $desaList]);
        break;

    // ---------- CHECK NIK DUPLICATE / SEARCH PASIEN ----------
    case 'search_nik':
        $nik = trim($_GET['nik'] ?? $_GET['noidentitas'] ?? '');
        if (empty($nik)) respond(['error' => 'NIK tidak boleh kosong'], 400);
        $stmt = $pdo->prepare("SELECT id, nocm, namapasien, tgllahir, nohp FROM pasien_m WHERE noidentitas = :nik AND statusenabled = true LIMIT 1");
        $stmt->execute([':nik' => $nik]);
        $pasien = $stmt->fetch();
        if ($pasien) {
            respond(['success' => true, 'exists' => true, 'data' => $pasien, 'message' => 'NIK sudah terdaftar atas nama ' . $pasien['namapasien'] . ' (No RM: ' . $pasien['nocm'] . ')']);
        } else {
            respond(['success' => true, 'exists' => false, 'message' => 'NIK belum terdaftar']);
        }
        break;

    // ---------- GET MASTER DATA (untuk dropdown daftar online) ----------
    case 'get_masters':
        $agama = $pdo->query("SELECT id, agama FROM agama_m WHERE statusenabled = true ORDER BY agama")->fetchAll();
        $kebangsaan = $pdo->query("SELECT id, name FROM kebangsaan_m WHERE statusenabled = true ORDER BY name")->fetchAll();
        $negara = $pdo->query("SELECT id, namanegara AS nama FROM negara_m WHERE statusenabled = true ORDER BY namanegara")->fetchAll();
        if (empty($negara)) {
            $negara = [['id' => 0, 'nama' => 'Indonesia']];
        }
        $hubungan = $pdo->query("SELECT id, hubungankeluarga AS nama FROM hubungankeluarga_m WHERE statusenabled = true ORDER BY id")->fetchAll();
        $pekerjaan = $pdo->query("SELECT id, pekerjaan AS nama FROM pekerjaan_m WHERE statusenabled = true ORDER BY pekerjaan")->fetchAll();
        $statusPerkawinan = $pdo->query("SELECT id, statusperkawinan AS nama FROM statusperkawinan_m WHERE statusenabled = true ORDER BY id")->fetchAll();
        $goldar = $pdo->query("SELECT id, golongandarah AS nama FROM golongandarah_m WHERE statusenabled = true ORDER BY golongandarah")->fetchAll();
        $pendidikan = $pdo->query("SELECT id, pendidikan AS nama FROM pendidikan_m WHERE statusenabled = true ORDER BY id")->fetchAll();
        $etnis = $pdo->query("SELECT id, suku AS nama FROM suku_m WHERE statusenabled = true ORDER BY suku")->fetchAll();
        
        $poliklinik = $pdo->query("SELECT id, namaruangan, prefixnoantrian FROM ruangan_m WHERE objectdepartemenfk = 18 AND statusenabled = true ORDER BY namaruangan")->fetchAll();
        $dokter = $pdo->query("SELECT id, namalengkap FROM pegawai_m WHERE statusenabled = true AND (objectjenispegawaifk = 1 OR namalengkap ILIKE 'dr%') ORDER BY namalengkap")->fetchAll();
        $kelompokPasien = $pdo->query("SELECT id, kelompokpasien FROM kelompokpasien_m WHERE statusenabled = true ORDER BY id")->fetchAll();

        respond([
            'success' => true,
            'data' => [
                'agama' => $agama,
                'kebangsaan' => $kebangsaan,
                'negara' => $negara,
                'hubungan' => $hubungan,
                'pekerjaan' => $pekerjaan,
                'status_perkawinan' => $statusPerkawinan,
                'goldar' => $goldar,
                'pendidikan' => $pendidikan,
                'etnis' => $etnis,
                'poliklinik' => $poliklinik,
                'dokter' => $dokter,
                'kelompok_pasien' => $kelompokPasien,
            ]
        ]);
        break;

    // ---------- GET PROFILE ----------
    case 'get_profile':
        if (!isLoggedIn()) {
            respond(['error' => 'Unauthorized'], 401);
        }
        $pasien_id = $_SESSION['pasien_id'];
        $stmt = $pdo->prepare("SELECT id, namapasien, nocm, tgllahir, objectjeniskelaminfk FROM pasien_m WHERE id = :id");
        $stmt->execute([':id' => $pasien_id]);
        $profile = $stmt->fetch();
        if (!$profile) {
            respond(['error' => 'Data pasien tidak ditemukan']);
        }
        // Ambil jenis kelamin
        $jk = $profile['objectjeniskelaminfk'];
        $jenisKelamin = '-';
        if ($jk) {
            $stmtJk = $pdo->prepare("SELECT jeniskelamin FROM jeniskelamin_m WHERE id = :id");
            $stmtJk->execute([':id' => $jk]);
            $jkData = $stmtJk->fetch();
            $jenisKelamin = $jkData['jeniskelamin'] ?? '-';
        }
        // Hitung umur
        $umur = '-';
        if (!empty($profile['tgllahir'])) {
            $birth = new DateTime($profile['tgllahir']);
            $now = new DateTime();
            $diff = $now->diff($birth);
            $umur = $diff->y . ' thn';
            if ($diff->y == 0 && $diff->m > 0) $umur = $diff->m . ' bln';
            if ($diff->y == 0 && $diff->m == 0) $umur = $diff->d . ' hr';
        }
        $profile['jenis_kelamin'] = $jenisKelamin;
        $profile['umur'] = $umur;
        unset($profile['objectjeniskelaminfk']);
        respond(['success' => true, 'data' => $profile]);
        break;

    // ---------- GET ORDERS (Lab & Rad) ----------
    case 'get_orders':
        if (!isLoggedIn()) {
            respond(['error' => 'Unauthorized'], 401);
        }
        $pasien_id = $_SESSION['pasien_id'];

        // Ambil semua registrasi
        $sqlReg = "SELECT norec FROM pasiendaftar_t WHERE nocmfk = :pasien_id AND statusenabled = true";
        $stmtReg = $pdo->prepare($sqlReg);
        $stmtReg->execute([':pasien_id' => $pasien_id]);
        $registrasi = $stmtReg->fetchAll();
        $norecList = array_column($registrasi, 'norec');

        $orders = [];
        if (!empty($norecList)) {
            $inPlaceholders = implode(',', array_fill(0, count($norecList), '?'));
            $sqlOrder = "
                SELECT 
                    so.norec AS norec_so,
                    so.noorder,
                    so.tglorder,
                    so.keteranganorder,
                    so.statusorder,
                    so.noregistrasi,
                    so.norec_apd,
                    ru.namaruangan AS ruangan_tujuan,
                    ruasal.namaruangan AS ruangan_asal,
                    pg_order.namalengkap AS dokter_order,
                    pr.namaproduk,
                    pr.id AS produk_id,
                    pp.norec AS norec_pp,
                    hr.keterangan AS expertise,
                    hr.norec AS norec_exper,
                    pg_baca.namalengkap AS dokter_baca
                FROM strukorder_t so
                JOIN ruangan_m ru ON ru.id = so.objectruangantujuanfk
                LEFT JOIN ruangan_m ruasal ON ruasal.id = so.objectruanganfk
                LEFT JOIN pegawai_m pg_order ON pg_order.id = so.objectpegawaiorderfk
                LEFT JOIN pelayananpasien_t pp ON pp.strukorderfk = so.norec AND pp.statusenabled = true
                LEFT JOIN produk_m pr ON pr.id = pp.produkfk
                LEFT JOIN hasilradiologi_t hr ON hr.pelayananpasienfk = pp.norec AND hr.statusenabled = true
                LEFT JOIN pegawai_m pg_baca ON pg_baca.id = hr.pegawaifk
                WHERE so.noregistrasifk IN ($inPlaceholders)
                  AND so.keteranganorder IN ('Order Laboratorium', 'Order Radiologi')
                  AND so.statusenabled = true
                ORDER BY so.tglorder DESC, so.noorder
            ";
            $stmtOrder = $pdo->prepare($sqlOrder);
            $stmtOrder->execute($norecList);
            $rawOrders = $stmtOrder->fetchAll();

            foreach ($rawOrders as $row) {
                $key = $row['norec_so'];
                if (!isset($orders[$key])) {
                    $orders[$key] = [
                        'norec_so' => $row['norec_so'],
                        'noorder' => $row['noorder'],
                        'tglorder' => $row['tglorder'],
                        'keteranganorder' => $row['keteranganorder'],
                        'statusorder' => $row['statusorder'],
                        'noregistrasi' => $row['noregistrasi'],
                        'norec_apd' => $row['norec_apd'] ?? '',
                        'ruangan_tujuan' => $row['ruangan_tujuan'],
                        'ruangan_asal' => $row['ruangan_asal'] ?? '-',
                        'dokter_order' => $row['dokter_order'] ?? '-',
                        'dokter_baca' => $row['dokter_baca'] ?? '-',
                        'expertise' => $row['expertise'],
                        'norec_exper' => $row['norec_exper'],
                        'produk' => [],
                    ];
                }
                if (!empty($row['namaproduk'])) {
                    $exists = false;
                    foreach ($orders[$key]['produk'] as $p) {
                        if ($p['produk_id'] == $row['produk_id']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $orders[$key]['produk'][] = [
                            'namaproduk' => $row['namaproduk'],
                            'produk_id' => $row['produk_id'],
                            'norec_pp' => $row['norec_pp'],
                            'norec_exper' => $row['norec_exper'],
                        ];
                    }
                }
            }
        }

        // Filter pending
        $ordersLab = array_values(array_filter($orders, function($o) { 
            return $o['keteranganorder'] == 'Order Laboratorium' && $o['statusorder'] != 0; 
        }));
        $ordersRad = array_values(array_filter($orders, function($o) { 
            return $o['keteranganorder'] == 'Order Radiologi' && $o['statusorder'] != 0; 
        }));

        respond(['success' => true, 'lab' => $ordersLab, 'rad' => $ordersRad]);
        break;

    // ---------- GET RIWAYAT KUNJUNGAN ----------
    case 'get_riwayat':
        if (!isLoggedIn()) {
            respond(['error' => 'Unauthorized'], 401);
        }
        $pasien_id = $_SESSION['pasien_id'];
        $sql = "SELECT norec, noregistrasi, tglregistrasi, tglpulang, objectruanganlastfk 
                FROM pasiendaftar_t 
                WHERE nocmfk = :pasien_id AND statusenabled = true 
                ORDER BY tglregistrasi DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pasien_id' => $pasien_id]);
        $riwayat = $stmt->fetchAll();
        foreach ($riwayat as &$reg) {
            if (!empty($reg['objectruanganlastfk'])) {
                $stmtRu = $pdo->prepare("SELECT namaruangan FROM ruangan_m WHERE id = :id");
                $stmtRu->execute([':id' => $reg['objectruanganlastfk']]);
                $ru = $stmtRu->fetch();
                $reg['namaruangan'] = $ru['namaruangan'] ?? '-';
            } else {
                $reg['namaruangan'] = '-';
            }
            unset($reg['objectruanganlastfk']);
        }
        respond(['success' => true, 'data' => $riwayat]);
        break;

    // ---------- GET MASTER DATA (untuk dropdown daftar online) ----------
    case 'get_masters':
        $agama = $pdo->query("SELECT id, agama FROM agama_m WHERE statusenabled = true ORDER BY agama")->fetchAll();
        $kebangsaan = $pdo->query("SELECT id, name FROM kebangsaan_m WHERE statusenabled = true ORDER BY name")->fetchAll();
        $negara = [['id' => 'ID', 'nama' => 'Indonesia'], ['id' => 'MY', 'nama' => 'Malaysia']];
        $hubungan = [['id' => 1, 'nama' => 'Suami'], ['id' => 2, 'nama' => 'Istri'], ['id' => 3, 'nama' => 'Anak'], ['id' => 4, 'nama' => 'Orang Tua'], ['id' => 5, 'nama' => 'Saudara']];
        $pekerjaan = [['id' => 1, 'nama' => 'PNS'], ['id' => 2, 'nama' => 'Swasta'], ['id' => 3, 'nama' => 'Wiraswasta'], ['id' => 4, 'nama' => 'Tidak Bekerja']];
        $statusPerkawinan = [['id' => 1, 'nama' => 'Belum Kawin'], ['id' => 2, 'nama' => 'Kawin'], ['id' => 3, 'nama' => 'Cerai']];
        $goldar = [['id' => 'A', 'nama' => 'A'], ['id' => 'B', 'nama' => 'B'], ['id' => 'AB', 'nama' => 'AB'], ['id' => 'O', 'nama' => 'O']];
        $pendidikan = [['id' => 1, 'nama' => 'SD'], ['id' => 2, 'nama' => 'SMP'], ['id' => 3, 'nama' => 'SMA'], ['id' => 4, 'nama' => 'D3'], ['id' => 5, 'nama' => 'S1']];
        $etnis = [['id' => 1, 'nama' => 'Jawa'], ['id' => 2, 'nama' => 'Sunda']];
        respond([
            'success' => true,
            'data' => [
                'agama' => $agama,
                'kebangsaan' => $kebangsaan,
                'negara' => $negara,
                'hubungan' => $hubungan,
                'pekerjaan' => $pekerjaan,
                'status_perkawinan' => $statusPerkawinan,
                'goldar' => $goldar,
                'pendidikan' => $pendidikan,
                'etnis' => $etnis,
            ]
        ]);
        break;

    // ---------- DEFAULT ----------
    default:
        respond(['error' => 'Aksi tidak valid'], 400);
}
