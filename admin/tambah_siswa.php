<?php
// Tampilkan error saat pengembangan

session_start();
require_once '../config/database.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success = '';
$error = '';

$import_success = '';
$import_errors = [];

// Ambil daftar kelas dari database
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// ============ PROSES IMPORT MASAL (Upload CSV) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_csv'])) {
    $file = $_FILES['file_csv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $import_success = 'Gagal mengunggah file. Pastikan file CSV dipilih dengan benar.';
        $import_errors[] = 'error';
    } elseif ($file['size'] <= 0) {
        $import_success = 'File yang diunggah kosong.';
        $import_errors[] = 'error';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv' && $ext !== 'txt') {
            $import_success = 'Format file harus CSV (.csv) — gunakan kolom: NISN, Nama, Kelas.';
            $import_errors[] = 'error';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $first_line = fgets($handle);
                rewind($handle);
                $delimiter = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

                $berhasil = 0;
                $duplikat = 0;
                $gagal = 0;
                $baris = 0;
                $row_errors = [];
                $nisn_seen = [];

                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $baris++;

                    // Lewati baris kosong atau header (NISN, Nama, Kelas)
                    $kolom1 = trim(preg_replace('/^\xEF\xBB\xBF/', '', isset($row[0]) ? $row[0] : ''));
                    if (strtolower($kolom1) === 'nisn') continue;
                    if (count($row) < 3 || trim(implode('', $row)) === '') continue;

                    $nisn = $kolom1;
                    $name = trim(isset($row[1]) ? $row[1] : '');
                    $class = trim(isset($row[2]) ? $row[2] : '');
                    $tempat_lahir = trim(isset($row[3]) ? $row[3] : '');
                    $tanggal_lahir = trim(isset($row[4]) ? $row[4] : '');
                    $jenis_kelamin = trim(isset($row[5]) ? $row[5] : '');
                    $alamat = trim(isset($row[6]) ? $row[6] : '');

                    // Normalisasi tanggal lahir (dukung YYYY-MM-DD atau DD-MM-YYYY, validasi ketat)
                    $tgl_lahir_db = null;
                    if (!empty($tanggal_lahir)) {
                        $raw_tgl = str_replace('/', '-', $tanggal_lahir);
                        foreach (array('Y-m-d', 'd-m-Y') as $fmt) {
                            $d = DateTime::createFromFormat($fmt, $raw_tgl);
                            if ($d && $d->format($fmt) === $raw_tgl) {
                                $tgl_lahir_db = $d->format('Y-m-d');
                                break;
                            }
                        }
                    }
                    // Normalisasi jenis kelamin: terima L/P, laki-laki/perempuan, pria/wanita, dll.
                    $jk_upper = strtoupper(trim($jenis_kelamin));
                    if (in_array($jk_upper, array('L', 'LAKI', 'LAKI-LAKI', 'LAKI LAKI', 'PRIA', 'COWOK', 'MALE'))) {
                        $jenis_kelamin = 'L';
                    } elseif (in_array($jk_upper, array('P', 'PEREMPUAN', 'WANITA', 'CEWEK', 'CEW', 'FEMALE'))) {
                        $jenis_kelamin = 'P';
                    } else {
                        $jenis_kelamin = null;
                    }

                    if (empty($nisn) || empty($name) || empty($class)) {
                        $gagal++;
                        $row_errors[] = "Baris $baris: kolom tidak lengkap (NISN, Nama, Kelas wajib diisi).";
                        continue;
                    }

                    // Cek duplikat NISN di dalam file
                    if (isset($nisn_seen[$nisn])) {
                        $duplikat++;
                        $row_errors[] = "Baris $baris: NISN <strong>$nisn</strong> muncul lebih dari satu kali di file.";
                        continue;
                    }
                    $nisn_seen[$nisn] = true;

                    try {
                        // Cek NISN sudah terdaftar di database
                        $stmt_check = $pdo->prepare("SELECT id FROM students WHERE nisn = ?");
                        $stmt_check->execute([$nisn]);
                        if ($stmt_check->rowCount() > 0) {
                            $duplikat++;
                            $row_errors[] = "Baris $baris: NISN <strong>$nisn</strong> sudah terdaftar di sistem.";
                            continue;
                        }

                        // Mulai transaksi database per baris
                        $pdo->beginTransaction();

                        // Pastikan kelas terdaftar di tabel kelas (auto-daftar jika belum ada)
                        $stmt_cek_kelas = $pdo->prepare("SELECT id FROM kelas WHERE name = ?");
                        $stmt_cek_kelas->execute([$class]);
                        if ($stmt_cek_kelas->rowCount() === 0) {
                            $pdo->prepare("INSERT INTO kelas (name) VALUES (?)")->execute([$class]);
                        }

                        // Buat akun login: username & password default = NISN
                        $hashed_password = password_hash($nisn, PASSWORD_DEFAULT);
                        $stmt_user = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                        $stmt_user->execute([$nisn, $hashed_password]);
                        $user_id = $pdo->lastInsertId();

                        // Generate Token QR Code Unik
                        $random_string = bin2hex(random_bytes(16));
                        $qr_token = $random_string . '_' . $nisn;

                        $stmt_student = $pdo->prepare("INSERT INTO students (user_id, nis, nisn, name, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat, class, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_student->execute([$user_id, $nisn, $nisn, $name, $tempat_lahir, $tgl_lahir_db, $jenis_kelamin, $alamat, $class, $qr_token]);

                        $pdo->commit();
                        $berhasil++;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $gagal++;
                        $row_errors[] = "Baris $baris: $nisn — " . $e->getMessage();
                    }
                }
                fclose($handle);

                $import_success = "Import selesai! <strong>$berhasil siswa</strong> berhasil ditambahkan.";
                if ($duplikat > 0) {
                    $import_success .= " <strong>$duplikat</strong> dilewati karena NISN duplikat.";
                }
                if ($gagal > 0) {
                    $import_success .= " <strong>$gagal</strong> baris gagal diproses.";
                }
                $import_errors = $row_errors;
            } else {
                $import_success = 'Tidak dapat membaca file yang diunggah.';
                $import_errors[] = 'error';
            }
        }
    }
}

// ============ PROSES TAMBAH SISWA SATU PER SATU ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['file_csv'])) {
    $nisn = trim($_POST['nisn']);
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);
    $tempat_lahir = trim(isset($_POST['tempat_lahir']) ? $_POST['tempat_lahir'] : '');
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = isset($_POST['jenis_kelamin']) ? strtoupper(trim($_POST['jenis_kelamin'])) : null;
    if (!in_array($jenis_kelamin, array('L', 'P'))) $jenis_kelamin = null;
    $alamat = trim(isset($_POST['alamat']) ? $_POST['alamat'] : '');
    $foto = '';

    // Validasi input tidak boleh kosong
    if(!empty($nisn) && !empty($name) && !empty($class)) {
        try {
            // 1. Cek apakah NISN sudah ada untuk mencegah duplikasi
            $stmt_check = $pdo->prepare("SELECT id FROM students WHERE nisn = ?");
            $stmt_check->execute([$nisn]);
            
            if($stmt_check->rowCount() > 0) {
                $error = 'Gagal: Siswa dengan NISN <strong>' . htmlspecialchars($nisn) . '</strong> sudah terdaftar!';
            } else {
                // Upload foto (opsional)
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $allowed = array('jpg', 'jpeg', 'png', 'webp');
                    if (in_array($ext, $allowed) && $_FILES['foto']['size'] <= 2097152) {
                        $foto_name = $nisn . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], '../assets/foto_siswa/' . $foto_name)) {
                            $foto = 'assets/foto_siswa/' . $foto_name;
                        }
                    } else {
                        $error = 'Foto harus berformat JPG/PNG/WEBP maksimal 2MB.';
                    }
                }

                if (empty($error)) {
                    // Mulai transaksi database
                    $pdo->beginTransaction();
                    
                    // 2. Buat Password default = NISN
                    $hashed_password = password_hash($nisn, PASSWORD_DEFAULT);
                    
                    // 3. Masukkan ke tabel users
                    $stmt_user = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'student')");
                    $stmt_user->execute([$nisn, $hashed_password]);
                    
                    // Ambil ID dari user yang baru dibuat
                    $user_id = $pdo->lastInsertId();
                    
                    // 4. Generate Token QR Code Aman & Unik
                    if (function_exists('random_bytes')) {
                        $random_string = bin2hex(random_bytes(16));
                    } else {
                        $random_string = md5(uniqid(rand(), true));
                    }
                    $qr_token = $random_string . '_' . $nisn;
                    
                    // 5. Masukkan ke tabel students
                    $stmt_student = $pdo->prepare("INSERT INTO students (user_id, nis, nisn, name, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat, foto, class, qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_student->execute([$user_id, $nisn, $nisn, $name, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $alamat, $foto, $class, $qr_token]);
                    
                    // Konfirmasi transaksi berhasil
                    $pdo->commit();
                    $success = 'Berhasil! Data siswa <strong>' . htmlspecialchars($name) . '</strong> telah ditambahkan. QR Code siap digunakan.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database Error: ' . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Sistem Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Perhatian: NISN, Nama, dan Kelas wajib diisi!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Siswa - Admin Panel</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* CSS Reset & Variabel Konsisten */
        :root {
            --primary: #3b82f6; --primary-hover: #2563eb; --primary-light: #eff6ff;
            --success: #10b981; --success-light: #d1fae5;
            --warning: #f59e0b; --warning-light: #fef3c7;
            --danger: #ef4444; --danger-light: #fee2e2;
            --dark: #0f172a; --dark-secondary: #1e293b;
            --gray: #64748b; --bg-light: #f8fafc; --border: #e2e8f0;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); color: var(--dark-secondary); overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar { width: 260px; background: var(--dark); color: #94a3b8; padding: 24px 20px; position: fixed; height: 100vh; top: 0; left: 0; z-index: 1000; transition: transform 0.3s ease; transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,0.35); }
        .sidebar.active { transform: translateX(0); }
        .sidebar-brand { color: white; font-size: 14px; font-weight: 800; margin-bottom: 40px; display: flex; align-items: center; gap: 12px; padding-left: 10px; }
        .sidebar-brand i { color: var(--primary); font-size: 24px; }
        .sidebar-brand img.brand-logo { width: 56px; height: 56px; border-radius: 12px; background: white; padding: 6px; object-fit: contain; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li { margin-bottom: 8px; }
        .sidebar-menu a { display: flex; align-items: center; gap: 14px; padding: 12px 16px; color: #94a3b8; text-decoration: none; border-radius: 10px; font-weight: 500; transition: all 0.2s ease; }
        .sidebar-menu a i { font-size: 18px; width: 24px; text-align: center; }
        .sidebar-menu a:hover, .sidebar-menu a.active { background: var(--dark-secondary); color: white; }
        
        /* Submenu Absensi */
        .submenu-arrow { margin-left: auto; font-size: 12px; transition: transform 0.3s ease; }
        .has-submenu.open .submenu-arrow { transform: rotate(180deg); }
        .submenu { list-style: none; display: none; margin: 2px 0 6px; padding-left: 12px; }
        .has-submenu.open .submenu { display: block; }
        .submenu a { padding: 10px 12px; font-size: 14px; }
        .submenu a:hover, .submenu a.active { background: var(--dark-secondary); color: white; }
        
        /* Main Content */
        .main-content { margin-left: 0; padding: 30px 40px; min-height: 100vh; }
        
        /* Navbar */
        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar h2 { font-size: 20px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        .user-profile { display: flex; align-items: center; gap: 12px; font-weight: 600; background: var(--bg-light); padding: 8px 16px; border-radius: 30px; }
        .user-profile i { color: var(--primary); font-size: 20px; }
        
        /* Alert Message */
        .alert { padding: 16px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; font-weight: 500; border-left: 4px solid; display: flex; align-items: flex-start; gap: 12px; line-height: 1.5; }
        .alert i { font-size: 18px; margin-top: 2px; }
        .alert.success { background: var(--success-light); border-left-color: var(--success); color: #065f46; }
        .alert.danger { background: var(--danger-light); border-left-color: var(--danger); color: #991b1b; }
        
        /* Form Panel & Grid Dua Kolom */
        .grid-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
        .form-panel { background: white; padding: 35px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .form-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 25px; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .form-panel h3 i { color: var(--primary); }
        .form-panel .subtitle { color: var(--gray); font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--gray); font-weight: 600; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); transition: all 0.3s ease; background: var(--bg-light); outline: none; }
        .form-group input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        
        /* Button */
        .btn-save { padding: 14px 24px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; width: 100%; display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 10px; }
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }
        .btn-template { width: 100%; margin-top: 12px; padding: 13px 24px; background: white; color: var(--success); border: 2px solid var(--success); border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; justify-content: center; align-items: center; gap: 10px; text-decoration: none; }
        .btn-template:hover { background: var(--success-light); }
        
        /* File Zone */
        .file-zone { border: 2px dashed var(--border); border-radius: 14px; padding: 25px; text-align: center; background: var(--bg-light); transition: all 0.2s ease; margin-bottom: 20px; cursor: pointer; display: block; }
        .file-zone:hover { border-color: var(--primary); background: var(--primary-light); }
        .file-zone i { font-size: 34px; color: var(--primary); margin-bottom: 10px; display: block; }
        .file-zone p { color: var(--gray); font-size: 13px; }
        .file-zone p strong { color: var(--dark-secondary); }
        .file-zone input[type="file"] { display: none; }
        .file-zone .file-name { color: var(--primary); font-weight: 700; margin-top: 8px; font-size: 13px; }
        
        /* Info Box */
        .info-box { background: var(--primary-light); padding: 15px; border-radius: 10px; margin-top: 20px; font-size: 13px; color: var(--primary-hover); display: flex; gap: 10px; align-items: flex-start; line-height: 1.6; }
        .info-box i { font-size: 16px; margin-top: 2px; }
        
        /* Rincian Error */
        .error-list { margin-top: 12px; max-height: 200px; overflow-y: auto; font-size: 13px; padding-left: 0; }
        .error-list li { margin-bottom: 6px; list-style: none; padding: 8px 12px; background: white; border: 1px solid var(--danger-light); border-radius: 8px; color: var(--danger); }
        
        /* Mobile Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        
        /* Responsif (Mobile & Tablet) */
        @media(max-width: 992px) {
            .main-content { padding: 20px; }
            .navbar h2 { font-size: 18px; }
            .grid-panel { grid-template-columns: 1fr; }
        }
    

        /* Header Atas (satu oval, lembut) */
        .top-header { position: relative; z-index: 1500; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 20px 0; padding: 10px 14px; border-radius: 999px; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border: 1px solid rgba(255,255,255,0.18); box-shadow: 0 12px 30px -10px rgba(37, 99, 235, 0.6); transition: margin-left 0.3s ease; }
        .top-header.active { margin-left: 260px; }
        .top-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: white; cursor: pointer; }
        .top-brand img { width: 42px; height: 42px; border-radius: 50%; background: white; padding: 4px; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .top-brand h1 { color: white; font-size: 16px; font-weight: 800; letter-spacing: 0.4px; margin: 0; white-space: nowrap; }
        .top-header .user-profile { background: rgba(255,255,255,0.15); color: white; box-shadow: none; padding: 8px 16px; border-radius: 999px; }
        .top-header .user-profile i { color: white; }
        .top-header .user-profile span { color: white; }

        /* Dropdown Profil */
        .top-header .user-profile { position: relative; cursor: pointer; }
        .profile-menu { display: none; position: absolute; top: calc(100% + 12px); right: 0; background: white; border-radius: 14px; box-shadow: 0 12px 32px rgba(0,0,0,0.18); min-width: 200px; padding: 8px; z-index: 2000; }
        .profile-menu.show { display: block; }
        .profile-menu-name { padding: 10px 14px; font-weight: 700; color: var(--dark); font-size: 14px; border-bottom: 1px solid var(--border); margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .profile-menu-logout { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 10px 16px; border-radius: 999px; color: #dc2626; font-weight: 700; font-size: 14px; text-decoration: none; background: #fee2e2; }
        .profile-menu-logout:hover { background: #dc2626; color: white; }

        /* Header responsif: panjang menyesuaikan desktop/mobile */
        @media (max-width: 576px) {
            .top-header { margin: 12px 12px 0; padding: 8px 10px; gap: 8px; }
            .top-header.active { padding: 6px 8px; }
            .top-brand h1 { display: none; }
            .top-brand img { width: 38px; height: 38px; }
            .top-header .user-profile span { display: inline; }
        }

</style>
</head>
<body>
    <!-- Header Atas -->
    <header class="top-header">
        <a href="index" class="top-brand">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
            <h1>Absensi SMAN 1 Bangunrejo</h1>
        </a>
                <div class="user-profile" onclick="toggleProfileMenu(event)">
            <i class="fa-solid fa-circle-user"></i>
            <span>Administrator</span>
            <i class="fa-solid fa-chevron-down" style="font-size: 11px; opacity: 0.8;"></i>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-name">Administrator</div>
                <a href="../logout" class="profile-menu-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </header>

    <!-- Mobile Overlay -->
    <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo" class="brand-logo"> <span>Absensi SMAN 1 Bangunrejo</span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="index"><i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span></a></li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-clipboard-check"></i> <span>Absensi</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="scan?type=hadir"><i class="fa-solid fa-right-to-bracket"></i> <span>Absen Hadir</span></a></li>
                    <li><a href="scan?type=pulang"><i class="fa-solid fa-right-from-bracket"></i> <span>Absen Pulang</span></a></li>
                    <li><a href="scan?type=sholat"><i class="fa-solid fa-mosque"></i> <span>Absen Sholat</span></a></li>
                    <li><a href="waktu_absensi"><i class="fa-solid fa-clock"></i> <span>Waktu Absensi</span></a></li>
                </ul>
            </li>
            <li><a href="data_siswa"><i class="fa-solid fa-users"></i> <span>Data Siswa</span></a></li>
            <li><a href="kelas"><i class="fa-solid fa-school"></i> <span>Kelas</span></a></li>
            <li><a href="cetak_kartu"><i class="fa-solid fa-id-card"></i> <span>Cetak Kartu Pelajar</span></a></li>
            <li><a href="tambah_guru"><i class="fa-solid fa-user-plus"></i> <span>Tambah Guru</span></a></li>
            <li><a href="scan"><i class="fa-solid fa-camera"></i> <span>Scan QR Code</span></a></li>
            <li><a href="input_absen"><i class="fa-solid fa-clipboard-user"></i> <span>Input Manual</span></a></li>
            <li><a href="laporan"><i class="fa-solid fa-file-lines"></i> <span>Laporan Absensi</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar">
            <div class="navbar-left">
                <button class="btn-menu" onclick="toggleSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2>Manajemen Data Siswa</h2>
            </div>
            
        </div>

        <div class="grid-panel">
            <!-- Panel: Tambah Siswa Satuan -->
            <div class="form-panel">
                <h3><i class="fa-solid fa-address-card"></i> Formulir Registrasi Siswa Baru</h3>
                
                <?php if(!empty($success)): ?>
                    <div class="alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <div><?= $success ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if(!empty($error)): ?>
                    <div class="alert danger">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="nisn">NISN (Nomor Induk Siswa Nasional)</label>
                        <input type="text" id="nisn" name="nisn" placeholder="Contoh: 0123456789" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="name">Nama Lengkap Siswa</label>
                        <input type="text" id="name" name="name" placeholder="Masukkan nama lengkap siswa" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="tempat_lahir">Tempat Lahir</label>
                        <input type="text" id="tempat_lahir" name="tempat_lahir" placeholder="Contoh: Bangunrejo" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir">
                    </div>
                    <div class="form-group">
                        <label for="jenis_kelamin">Jenis Kelamin</label>
                        <select id="jenis_kelamin" name="jenis_kelamin" style="width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); background: var(--bg-light); outline: none;">
                            <option value="">-- Pilih Jenis Kelamin --</option>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="alamat">Alamat</label>
                        <input type="text" id="alamat" name="alamat" placeholder="Contoh: Desa Bangunrejo RT 01 RW 02" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="foto">Foto Siswa (opsional, maks 2MB)</label>
                        <input type="file" id="foto" name="foto" accept=".jpg,.jpeg,.png,.webp">
                    </div>
                    <div class="form-group">
                        <label for="class">Kelas / Rombongan Belajar</label>
                        <select id="class" name="class" required style="width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); background: var(--bg-light); outline: none;">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Data & Buat QR Code
                    </button>
                    
                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <p>Setelah disimpan, sistem akan otomatis membuat akun untuk siswa. <strong>Username</strong> dan <strong>Password</strong> default untuk login adalah NISN mereka.</p>
                    </div>
                </form>
            </div>

            <!-- Panel: Import Siswa Masal -->
            <div class="form-panel">
                <h3><i class="fa-solid fa-file-import"></i> Import Siswa Masal</h3>
                <p class="subtitle">Upload file CSV dengan format <strong>NISN, Nama, Kelas, Tempat Lahir, Tanggal Lahir, Jenis Kelamin (L/P), Alamat</strong>. Kolom ke-4 sampai ke-7 bersifat opsional. Setiap siswa otomatis mendapat akun login (username &amp; password = NISN) dan QR Code.</p>
                
                <?php if(!empty($import_success)): ?>
                    <?php $is_error = in_array('error', $import_errors); ?>
                    <div class="alert <?= $is_error ? 'danger' : 'success' ?>">
                        <i class="fa-solid <?= $is_error ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                        <div>
                            <?= $import_success ?>
                            <?php if(!empty($import_errors) && !$is_error): ?>
                                <ul class="error-list">
                                    <?php foreach($import_errors as $err): ?>
                                        <li><i class="fa-solid fa-xmark"></i> <?= $err ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <label class="file-zone" for="file_csv">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p><strong>Klik untuk memilih file CSV</strong><br>atau seret &amp; letakkan di sini</p>
                        <div class="file-name" id="file_name"></div>
                        <input type="file" id="file_csv" name="file_csv" accept=".csv,.txt" required>
                    </label>
                    
                    <button type="submit" class="btn-save" id="btn_import">
                        <i class="fa-solid fa-file-import"></i> Import Data Siswa
                    </button>
                    
                    <a href="template_siswa" class="btn-template">
                        <i class="fa-solid fa-file-csv"></i> Unduh Template CSV
                    </a>
                    
                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            <strong>Format file CSV:</strong><br>
                            NISN, Nama, Kelas, Tempat Lahir, Tanggal Lahir, Jenis Kelamin, Alamat<br>
                            <span style="font-family: monospace;">0123456789,Budi Santoso,XII IPS 1,Bangunrejo,2009-05-12,L,Desa Bangunrejo</span><br>
                            Koma (<strong>,</strong>) atau titik-koma (<strong>;</strong>) keduanya didukung. Baris pertama (header) otomatis dilewati. Kolom opsional boleh dikosongkan. Kelas yang belum terdaftar di menu <strong>Kelas</strong> akan otomatis didaftarkan.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Script Interaksi Mobile -->
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
            document.querySelector('.top-header').classList.toggle('active');
        }

        function toggleSubmenu(el) {
            el.closest('.has-submenu').classList.toggle('open');
        }
        document.getElementById('file_csv').addEventListener('change', function() {
            var label = document.getElementById('file_name');
            label.textContent = this.files.length > 0 ? this.files[0].name : '';
        });
        document.getElementById('btn_import').addEventListener('click', function(e) {
            var input = document.getElementById('file_csv');
            if (!input.files.length) {
                e.preventDefault();
                alert('Silakan pilih file CSV terlebih dahulu.');
            }
        });
    </script>


    <!-- Footer -->
    <footer style="text-align: center; padding: 18px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(100,116,139,0.2); margin-top: 24px;">
        Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
    </footer>

    <script>
        function toggleProfileMenu(e) {
            e.stopPropagation();
            document.getElementById('profileMenu').classList.toggle('show');
        }
        document.addEventListener('click', function () {
            var m = document.getElementById('profileMenu');
            if (m) m.classList.remove('show');
        });
    </script>
</body>
</html>