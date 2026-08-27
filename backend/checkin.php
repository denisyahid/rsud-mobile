<?php
// checkin.php - Backend API untuk functionality check-in pasien
// File ini berisi fungsi-fungsi untuk check-in dan scan barcode

// Set timezone ke Indonesia (WIB/WITA/WIT)
date_default_timezone_set('Asia/Jakarta');

// ============================================================
// HELPER: Generate UUID
// ============================================================
function generateUuid() {
    return sprintf('%08x-%04x-%04x-%04x-%04x%08x',
        mt_rand(0, 0xffffffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffffffff)
    );
}

// ============================================================
// CREATE QR/BARCODE UNTUK CHECKIN
// ============================================================
// Barcode check-in bersifat UMUM (satu QR dari halaman barcode.php
// untuk semua pasien). Nilainya memuat tanggal hari ini sehingga
// otomatis berganti setiap hari.

function generateCheckinBarcode($noregistrasi = null) {
    return 'CHECKIN-RSUD-MALANGBONG-' . date('Ymd');
}

// ============================================================
// VALIDATE BARCODE CHECKIN
// ============================================================

function validateCheckinBarcode($barcode, $noregistrasi = null) {
    // Format: CHECKIN-RSUD-MALANGBONG-YYYYMMDD
    $pattern = '/^CHECKIN-RSUD-MALANGBONG-\d{8}$/';
    
    if (!preg_match($pattern, $barcode)) {
        error_log("Barcode format invalid: " . $barcode);
        return false;
    }
    
    // Ambil tanggal dari barcode
    $datePart = substr($barcode, -8);
    $today = date('Ymd');
    $yesterday = date('Ymd', strtotime('-1 day'));
    $tomorrow = date('Ymd', strtotime('+1 day'));
    
    // Validasi: H-1, hari ini, atau H+1 (flexible untuk perbedaan timezone)
    $isValid = in_array($datePart, [$yesterday, $today, $tomorrow]);
    
    if (!$isValid) {
        error_log("Barcode date invalid: $datePart (today: $today)");
    }
    
    return $isValid;
}

// ============================================================
// SAVE CHECKIN DATA TO DATABASE
// ============================================================

function saveCheckinData($pdo, $pasienId, $noregistrasi, $barcode) {
    // Cek koneksi PDO
    if (!$pdo) {
        error_log("PDO connection is null");
        return ['success' => false, 'error' => 'Koneksi database error'];
    }
    
    try {
        $pdo->beginTransaction();
        
        error_log("=== START CHECKIN ===");
        error_log("Pasien ID: $pasienId, Noreg: $noregistrasi");
        
        // Cek registrasi pasien - gunakan parameter binding yang benar
        $stmt = $pdo->prepare("
            SELECT pd.norec, pd.noregistrasi, pd.nocmfk, pd.objectruanganlastfk,
                   pd.objectpegawaifk, pd.tglregistrasi, pd.statusenabled, pd.tglpulang, pd.ischeckin,
                   ap.norec as norec_apd, ap.statusantrian, ap.noantrian, ap.prefixnoantrian
            FROM pasiendaftar_t pd
            LEFT JOIN antrianpasiendiperiksa_t ap ON ap.noregistrasi = pd.noregistrasi
                 AND COALESCE(ap.statusenabled, true) = true
            WHERE pd.noregistrasi = :noregistrasi AND pd.nocmfk = :pasien_id
            LIMIT 1
        ");
        $stmt->execute([
            ':noregistrasi' => $noregistrasi, 
            ':pasien_id' => $pasienId
        ]);
        $reg = $stmt->fetch();
        
        if (!$reg) {
            $pdo->rollBack();
            error_log("Registrasi not found for pasien $pasienId, noreg $noregistrasi");
            return ['success' => false, 'error' => 'Data registrasi tidak ditemukan atau bukan milik Anda'];
        }
        
        error_log("Registrasi ditemukan: " . json_encode($reg));
        
        // Validasi status
        if (!$reg['statusenabled']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Registrasi tidak aktif atau sudah Selesai'];
        }
        
        if (!empty($reg['tglpulang'])) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Kunjungan sudah selesai pada ' . $reg['tglpulang']];
        }
        
        if ($reg['statusantrian'] == 1) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Pasien sudah dalam proses pemeriksaan'];
        }
        
        if ($reg['statusantrian'] == 2) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Kunjungan sudah selesai pemeriksaan'];
        }
        
        if ($reg['ischeckin']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Check-in sudah dilakukan sebelumnya'];
        }
        
        // ==================== INSERT STRUK TAGIHAN ====================
        $noStruk = generateStrukNumber($pdo, 1);
        $strukNorec = generateUuid();
        
        error_log("Generating struk: $noStruk, norec: $strukNorec");
        
        $sqlStruk = "INSERT INTO strukpelayanan_t (
            norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
            tglstruk, totalharusdibayar, nostruk, objectkelompoktransaksifk, created_at
        ) VALUES (
            :norec, 1, true, :norec_reg, :noregistrasi,
            NOW(), 75000, :nostruk, 2, NOW()
        )";
        
        $stmtStruk = $pdo->prepare($sqlStruk);
        $stmtStruk->execute([
            ':norec' => $strukNorec,
            ':norec_reg' => $reg['norec'],
            ':noregistrasi' => $noregistrasi,
            ':nostruk' => $noStruk
        ]);
        
        // ==================== INSERT PELAYANAN ====================
        $produkId = 6164; // BIAYA REGISTRASI
        $hargaSatuan = 75000;
        $kelasFk = 6;
        $kelompokTransaksi = 2;
        
        $pelayananNorec = generateUuid();
        // pelayananpasien_t tidak punya kolom ischeckin — ikuti skema rsud_mobile.txt
        $sqlPelayanan = "
            INSERT INTO pelayananpasien_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
                tglregistrasi, tglpelayanan, produkfk, jumlah, hargasatuan,
                hargajual, harganetto, kelasfk, kdkelompoktransaksi,
                keteranganlain, stock, jasa, dpjp, created_at
            ) VALUES (
                :norec, 1, true, :norec_reg, :noregistrasi,
                NOW(), NOW(), :produkfk, 1, :harga,
                :harga, :harga, :kelas, :kelompok,
                'BIAYA REGISTRASI CHECKIN', 0, 0, :dpjp, NOW()
            )
        ";
        $stmtPel = $pdo->prepare($sqlPelayanan);
        $stmtPel->execute([
            ':norec' => $pelayananNorec,
            ':norec_reg' => $reg['norec'],
            ':noregistrasi' => $noregistrasi,
            ':produkfk' => $produkId,
            ':harga' => $hargaSatuan,
            ':kelas' => $kelasFk,
            ':kelompok' => (string)$kelompokTransaksi,
            ':dpjp' => $reg['objectpegawaifk'] ?? null,
        ]);
        
        // ==================== UPDATE FLAG CHECK-IN ====================
        $stmtCheckinFlag = $pdo->prepare("UPDATE pasiendaftar_t SET ischeckin = true WHERE norec = :norec_reg");
        $stmtCheckinFlag->execute([':norec_reg' => $reg['norec']]);
        
        // ==================== ANTRIAN (insert bila belum ada, else update) ====================
        $apdNorec = $reg['norec_apd'] ?? null;
        $noAntrianFull = null;
        $ruanganId = $reg['objectruanganlastfk'] ?? null;
        $dokterId = $reg['objectpegawaifk'] ?? null;
        $tglReg = !empty($reg['tglregistrasi']) ? date('Y-m-d', strtotime($reg['tglregistrasi'])) : date('Y-m-d');

        if (!empty($apdNorec)) {
            $stmtApdUpdate = $pdo->prepare("
                UPDATE antrianpasiendiperiksa_t
                SET statusantrian = 1
                WHERE norec = :norec_apd
            ");
            $stmtApdUpdate->execute([':norec_apd' => $apdNorec]);
            error_log("Antrian updated: " . $apdNorec);
            if ($reg['noantrian'] !== null && $reg['noantrian'] !== '') {
                $pfx = !empty($reg['prefixnoantrian']) ? $reg['prefixnoantrian'] : 'A';
                $noAntrianFull = $pfx . '-' . str_pad((string)$reg['noantrian'], 3, '0', STR_PAD_LEFT);
            }
        } elseif (!empty($ruanganId)) {
            $stmtRu = $pdo->prepare("SELECT prefixnoantrian FROM ruangan_m WHERE id = :id");
            $stmtRu->execute([':id' => $ruanganId]);
            $prefixAntrian = trim($stmtRu->fetch()['prefixnoantrian'] ?? '') ?: 'A';

            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) AS total FROM antrianpasiendiperiksa_t
                WHERE objectruanganfk = :ruangan AND DATE(tglregistrasi) = :tgl AND statusenabled = true
            ");
            $stmtCount->execute([':ruangan' => $ruanganId, ':tgl' => $tglReg]);
            $noAntrian = ($stmtCount->fetch()['total'] ?? 0) + 1;

            $apdNorec = generateUuid();
            $sqlApd = "INSERT INTO antrianpasiendiperiksa_t (
                norec, kdprofile, statusenabled, noregistrasifk, noregistrasi,
                objectruanganfk, objectpegawaifk, objectkelasfk, kelasfk, noantrian, prefixnoantrian,
                tglregistrasi, statusantrian, created_at
            ) VALUES (
                :norec, 1, true, :noreg_fk, :noregistrasi,
                :ruangan, :dokter, 6, 6, :noantrian, :prefix,
                NOW(), '1', NOW()
            )";
            $stmtApdInsert = $pdo->prepare($sqlApd);
            $stmtApdInsert->execute([
                ':norec' => $apdNorec,
                ':noreg_fk' => $reg['norec'],
                ':noregistrasi' => $noregistrasi,
                ':ruangan' => $ruanganId,
                ':dokter' => $dokterId,
                ':noantrian' => $noAntrian,
                ':prefix' => $prefixAntrian,
            ]);
            $noAntrianFull = $prefixAntrian . '-' . str_pad((string)$noAntrian, 3, '0', STR_PAD_LEFT);
            error_log("Antrian inserted: $apdNorec ($noAntrianFull)");
        } else {
            error_log("Warning: No ruangan on registration " . $noregistrasi);
        }
        
        $pdo->commit();
        error_log("=== CHECKIN SUCCESS ===");
        
        return [
            'success' => true,
            'message' => 'Check-in berhasil! Tagihan registrasi 75.000 telah ditambahkan.',
            'data' => [
                'noregistrasi' => $noregistrasi,
                'tagihan_checkin' => 75000,
                'tgl_checkin' => date('Y-m-d H:i:s'),
                'nostruk' => $noStruk,
                'pelayanan_norec' => $pelayananNorec
            ]
        ];
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("PDO Error in saveCheckinData: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("General Error in saveCheckinData: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        return ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}

// ============================================================
// GET CHECKIN STATUS
// ============================================================

function getCheckinStatus($pdo, $noregistrasi, $pasienId) {
    $stmt = $pdo->prepare("
        SELECT ischeckin FROM pasiendaftar_t 
        WHERE noregistrasi = :noregistrasi AND nocmfk = :pasien_id
        LIMIT 1
    ");
    $stmt->execute([':noregistrasi' => $noregistrasi, ':pasien_id' => $pasienId]);
    $row = $stmt->fetch();
    
    return $row ? (bool)$row['ischeckin'] : false;
}

// ============================================================
// GET CHECKIN HISTORY FOR PASIEN
// ============================================================

function getCheckinHistory($pdo, $pasienId) {
    $stmt = $pdo->prepare("
        SELECT sp.norec, sp.nostruk, sp.tglstruk, sp.totalharusdibayar AS totalbiaya,
               pd.noregistrasi, pd.tglregistrasi
        FROM strukpelayanan_t sp
        JOIN pasiendaftar_t pd ON pd.norec = sp.noregistrasifk
        WHERE pd.nocmfk = :pasien_id
          AND pd.ischeckin = true
          AND sp.statusenabled = true
        ORDER BY sp.tglstruk DESC
    ");
    $stmt->execute([':pasien_id' => $pasienId]);
    return $stmt->fetchAll();
}

// ============================================================
// HELPER: Generate Struk Number
// ============================================================

function generateStrukNumber($pdo, $kdProfile = 1) {
    try {
        // Gunakan SIMILAR TO atau LIKE untuk keamanan
        $stmt = $pdo->prepare("
            SELECT COALESCE(
                MAX(CAST(SUBSTRING(nostruk FROM 2) AS BIGINT)), 
                0
            ) AS max_no 
            FROM strukpelayanan_t 
            WHERE kdprofile = :kdprofile 
              AND nostruk LIKE 'S%'
              AND LENGTH(nostruk) > 1
              AND SUBSTRING(nostruk FROM 2) ~ '^[0-9]+$'
        ");
        $stmt->execute([':kdprofile' => $kdProfile]);
        $result = $stmt->fetch();
        $maxNo = (int)($result['max_no'] ?? 0);
        $nextNo = $maxNo + 1;
        return 'S' . str_pad($nextNo, 9, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        error_log("Error generating struk number: " . $e->getMessage());
        // Fallback: gunakan timestamp
        return 'S' . date('YmdHis') . rand(100, 999);
    }
}

// ============================================================
// API ENDPOINT: checkin
// ============================================================

function handleCheckinApi($pdo, $input) {
    error_log("=== handleCheckinApi called ===");
    error_log("Input: " . json_encode($input));
    
    $noregistrasi = trim($input['noregistrasi'] ?? '');
    $barcode = trim($input['barcode'] ?? '');
    
    if (empty($noregistrasi)) {
        error_log("Error: noregistrasi empty");
        return ['error' => 'noregistrasi required'];
    }
    if (empty($barcode)) {
        error_log("Error: barcode empty");
        return ['error' => 'Barcode tidak valid'];
    }
    
    if (!isset($_SESSION['pasien_id'])) {
        error_log("Error: Not logged in, session: " . json_encode($_SESSION));
        return ['error' => 'Unauthorized'];
    }
    
    $pasienId = $_SESSION['pasien_id'];
    error_log("Pasien ID: $pasienId, Noreg: $noregistrasi");
    
    $isValid = validateCheckinBarcode($barcode);
    error_log("Barcode validation result: " . ($isValid ? 'true' : 'false'));
    
    if (!$isValid) {
        return ['error' => 'Barcode tidak valid. Silakan scan QR Code dari halaman barcode.php loket admisi.'];
    }
    
    return saveCheckinData($pdo, $pasienId, $noregistrasi, $barcode);
}

// ============================================================
// API ENDPOINT: get_checkin_status
// ============================================================

function handleGetCheckinStatusApi($pdo) {
    if (!isset($_SESSION['pasien_id'])) {
        return ['error' => 'Unauthorized'];
    }
    
    $pasienId = $_SESSION['pasien_id'];
    $noregistrasi = $_GET['noregistrasi'] ?? '';
    
    if (empty($noregistrasi)) {
        return ['error' => 'noregistrasi required'];
    }
    
    $isCheckin = getCheckinStatus($pdo, $noregistrasi, $pasienId);
    
    return [
        'success' => true,
        'is_checkin' => $isCheckin,
        'message' => $isCheckin ? 'Sudah check-in' : 'Belum check-in'
    ];
}

// ============================================================
// END OF FILE
// ============================================================
?>