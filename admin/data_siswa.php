<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success = '';
$error = '';
$edit_student = null;

// Proses Edit Siswa (Update NISN, Nama, Kelas, dan Data Profil)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id_edit = intval($_POST['id']);
    $nisn = trim(isset($_POST['nisn']) ? $_POST['nisn'] : '');
    $name = trim($_POST['name']);
    $class = trim($_POST['class']);
    $tempat_lahir = trim(isset($_POST['tempat_lahir']) ? $_POST['tempat_lahir'] : '');
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = isset($_POST['jenis_kelamin']) ? strtoupper(trim($_POST['jenis_kelamin'])) : null;
    if (!in_array($jenis_kelamin, array('L', 'P'))) $jenis_kelamin = null;
    $alamat = trim(isset($_POST['alamat']) ? $_POST['alamat'] : '');
    $foto_baru = '';

    if (!empty($nisn) && !empty($name) && !empty($class)) {
        try {
            // Cek NISN duplikat (kecuali siswa yang sedang diedit)
            $stmt_check = $pdo->prepare("SELECT id FROM students WHERE nisn = ? AND id != ?");
            $stmt_check->execute([$nisn, $id_edit]);

            if ($stmt_check->rowCount() > 0) {
                $error = 'Gagal: NISN <strong>' . htmlspecialchars($nisn) . '</strong> sudah digunakan siswa lain!';
                $edit_student = ['id' => $id_edit, 'nisn' => $nisn, 'name' => $name, 'class' => $class, 'tempat_lahir' => $tempat_lahir, 'tanggal_lahir' => $tanggal_lahir, 'jenis_kelamin' => $jenis_kelamin, 'alamat' => $alamat];
            } else {
                // Ambil foto lama untuk fallback jika tidak upload foto baru
                $stmt_foto = $pdo->prepare("SELECT foto FROM students WHERE id = ?");
                $stmt_foto->execute([$id_edit]);
                $foto_lama = $stmt_foto->fetchColumn();

                // Upload foto baru (opsional, hanya jika ada file)
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $allowed = array('jpg', 'jpeg', 'png', 'webp');
                    if (in_array($ext, $allowed) && $_FILES['foto']['size'] <= 2097152) {
                        $foto_name = $nisn . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], '../assets/foto_siswa/' . $foto_name)) {
                            $foto_baru = 'assets/foto_siswa/' . $foto_name;
                            if ($foto_lama) {
                                @unlink('../' . $foto_lama);
                            }
                        }
                    } else {
                        $error = 'Foto harus berformat JPG/PNG/WEBP maksimal 2MB.';
                    }
                }

                if (empty($error)) {
                    // Update data siswa (akun login tidak diubah)
                    $foto_final = $foto_baru !== '' ? $foto_baru : $foto_lama;
                    $stmt_upd = $pdo->prepare("UPDATE students SET nisn = ?, name = ?, tempat_lahir = ?, tanggal_lahir = ?, jenis_kelamin = ?, alamat = ?, foto = ?, class = ? WHERE id = ?");
                    $stmt_upd->execute([$nisn, $name, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $alamat, $foto_final, $class, $id_edit]);

                    $success = 'Berhasil! Data siswa <strong>' . htmlspecialchars($name) . '</strong> telah diperbarui.';
                } else {
                    $edit_student = ['id' => $id_edit, 'nisn' => $nisn, 'name' => $name, 'class' => $class, 'tempat_lahir' => $tempat_lahir, 'tanggal_lahir' => $tanggal_lahir, 'jenis_kelamin' => $jenis_kelamin, 'alamat' => $alamat];
                }
            }
        } catch (Exception $e) {
            $error = 'Database Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Perhatian: Semua kolom wajib diisi!';
        $edit_student = ['id' => $id_edit, 'nisn' => $nisn, 'name' => $name, 'class' => $class, 'tempat_lahir' => $tempat_lahir, 'tanggal_lahir' => $tanggal_lahir, 'jenis_kelamin' => $jenis_kelamin, 'alamat' => $alamat];
    }
}

// Ambil data siswa untuk form edit (jika action=edit)
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id_edit = intval($_GET['id']);
    $stmt_edit = $pdo->prepare("SELECT s.id, s.nisn, s.name, s.tempat_lahir, s.tanggal_lahir, s.jenis_kelamin, s.alamat, s.class FROM students s WHERE s.id = ?");
    $stmt_edit->execute([$id_edit]);
    $edit_student = $stmt_edit->fetch();
}

// Proses Hapus Siswa
if(isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id_hapus = intval($_GET['id']);
    try {
        // Cari user_id terlebih dahulu agar bisa menghapus relasi akun di tabel users
        $stmt_find = $pdo->prepare("SELECT user_id, name, foto FROM students WHERE id = ?");
        $stmt_find->execute([$id_hapus]);
        $student_data = $stmt_find->fetch();
        
        if($student_data) {
            // Hapus file foto siswa jika ada
            if (!empty($student_data['foto'])) {
                @unlink('../' . $student_data['foto']);
            }
            // Karena Foreign Key ON DELETE CASCADE, menghapus user otomatis menghapus student & attendance
            $stmt_del = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt_del->execute([$student_data['user_id']]);
            $success = "Data siswa <strong>" . htmlspecialchars($student_data['name']) . "</strong> beserta seluruh riwayat absensinya berhasil dihapus permanen.";
        } else {
            $error = "Data siswa tidak ditemukan di sistem.";
        }
    } catch (PDOException $e) {
        $error = "Gagal menghapus data: " . $e->getMessage();
    }
}

// Filter Pencarian Siswa (NISN / Nama / NIS) & Filter Kelas
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';

// Susun kondisi WHERE untuk pencarian & kelas
$where = '';
$params = [];
$conds = [];
if ($search !== '') {
    $conds[] = "(name LIKE ? OR nisn LIKE ? OR nis LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($filter_class !== '') {
    $conds[] = "class = ?";
    $params[] = $filter_class;
}
if (!empty($conds)) {
    $where = " WHERE " . implode(' AND ', $conds);
}

// Hitung total data untuk pagination
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM students" . $where);
$stmt_count->execute($params);
$total = (int)$stmt_count->fetchColumn();

// Pagination: 10 siswa per halaman
$per_page = 10;
$total_pages = max(1, (int)ceil($total / $per_page));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// Ambil data siswa sesuai halaman & pencarian
$stmt = $pdo->prepare("SELECT * FROM students" . $where . " ORDER BY class ASC, name ASC LIMIT " . $per_page . " OFFSET " . $offset);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Ambil daftar kelas dari database
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Data Siswa - Admin Panel</title>
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
        .alert { padding: 16px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; font-weight: 500; border-left: 4px solid; display: flex; align-items: center; gap: 10px; }
        .alert.success { background: var(--success-light); border-left-color: var(--success); color: #065f46; }
        .alert.danger { background: var(--danger-light); border-left-color: var(--danger); color: #991b1b; }
        
        /* Data Panel & Buttons */
        .data-panel { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .panel-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); }
        .btn-add { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--primary); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; transition: background 0.2s; }
        .btn-add:hover { background: var(--primary-hover); }

        /* Form Pencarian */
        .search-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .search-input { padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; font-size: 14px; min-width: 200px; outline: none; color: var(--dark-secondary); background: var(--bg-light); transition: all 0.2s; }
        .search-input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn-search { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--dark); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s; }
        .btn-search:hover { background: var(--dark-secondary); }
        .btn-clear { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 8px; background: var(--danger-light); color: var(--danger); text-decoration: none; font-size: 14px; transition: all 0.2s; }
        .btn-clear:hover { background: var(--danger); color: white; }

        /* Pagination */
        .pagination-info { margin-top: 18px; font-size: 13px; color: var(--gray); font-weight: 600; }
        .pagination { display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
        .page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; height: 38px; padding: 0 12px; border: 1px solid var(--border); border-radius: 8px; color: var(--dark-secondary); text-decoration: none; font-size: 14px; font-weight: 600; background: white; transition: all 0.2s; }
        .page-link:hover { border-color: var(--primary); color: var(--primary); }
        .page-link.active { background: var(--primary); border-color: var(--primary); color: white; }
        
        /* Table Styles */
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        th { padding: 16px; background: var(--bg-light); color: var(--gray); font-weight: 600; font-size: 14px; border-bottom: 2px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 15px; transition: background 0.2s; }
        tbody tr:hover td { background: var(--bg-light); }
        
        /* Action Buttons in Table */
        .btn-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.2s ease; margin-right: 6px; border: 1px solid transparent; }
        .btn-action.qr { background: var(--primary-light); color: var(--primary); }
        .btn-action.qr:hover { border-color: var(--primary); background: white; }
        .btn-action.edit { background: var(--warning-light); color: var(--warning); }
        .btn-action.edit:hover { border-color: var(--warning); background: white; }
        .btn-action.delete { background: var(--danger-light); color: var(--danger); }
        .btn-action.delete:hover { border-color: var(--danger); background: white; }
        
        /* Modal Style for QR Display */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background-color: white; padding: 35px; border-radius: 20px; text-align: center; max-width: 380px; width: 90%; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; animation: modalFadeIn 0.3s ease; }
        @keyframes modalFadeIn { from { opacity: 0; transform: translateY(-20px); scale: 0.95; } to { opacity: 1; transform: translateY(0); scale: 1; } }
        
        .close-modal { position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: var(--gray); font-weight: bold; transition: color 0.2s; }
        .close-modal:hover { color: var(--dark); }
        .modal-content img { width: 220px; height: 220px;x; margin: 25px 0; border: 2px solid var(--border); padding: 10px; border-radius: 12px; background: white; }
        .btn-print-qr { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; background: var(--success); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 15px; transition: background 0.2s; }
        .btn-print-qr:hover { background: #059669; }
        
        /* Edit Modal Form */
        .modal-title { color: var(--dark); font-size: 20px; margin-bottom: 6px; }
        .edit-form { text-align: left; margin-top: 20px; }
        .edit-form .form-group { margin-bottom: 16px; }
        .edit-form .form-group label { display: block; margin-bottom: 8px; color: var(--gray); font-weight: 600; font-size: 13px; }
        .edit-form .form-group input { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); transition: all 0.3s ease; background: var(--bg-light); outline: none; }
        .edit-form .form-group select { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); background: var(--bg-light); outline: none; }
        .edit-form .form-group input:focus, .edit-form .form-group select:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .btn-save-edit { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 15px; transition: background 0.2s; margin-top: 6px; }
        .btn-save-edit:hover { background: var(--primary-hover); }
        .btn-cancel { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 13px; background: white; color: var(--gray); border: 2px solid var(--border); border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 14px; text-decoration: none; margin-top: 10px; transition: all 0.2s; }
        .btn-cancel:hover { background: var(--bg-light); border-color: var(--gray); }

        /* Mobile Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* Responsif (Mobile & Tablet) */
        @media(max-width: 992px) {
                        .main-content { padding: 20px; }
            .navbar h2 { font-size: 18px; }
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
        }</style>
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
                <a href="../logout.php" class="profile-menu-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
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
            <li><a href="data_siswa" class="active"><i class="fa-solid fa-users"></i> <span>Data Siswa</span></a></li>
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

        <div class="data-panel">
            <!-- Notifikasi Pesan -->
            <?php if(!empty($success)): ?>
                <div class="alert success">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?= $success ?></div>
                </div>
            <?php endif; ?>
            
            <?php if(!empty($error)): ?>
                <div class="alert danger">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?= $error ?></div>
                </div>
            <?php endif; ?>

            <div class="panel-header">
                <h3><i class="fa-solid fa-list" style="color: var(--primary); margin-right: 8px;"></i> Daftar Seluruh Siswa</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <form method="GET" action="data_siswa" class="search-form">
                        <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Cari NISN / Nama / NIS..." autocomplete="off">
                        <select name="filter_class" class="search-input" style="min-width: 150px;">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $filter_class === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Cari Siswa</button>
                        <?php if ($search !== '' || $filter_class !== ''): ?>
                            <a href="data_siswa" class="btn-clear" title="Hapus pencarian"><i class="fa-solid fa-xmark"></i></a>
                        <?php endif; ?>
                    </form>
                    <button onclick="openCetakQR()" class="btn-add" style="background: var(--success); border: none; cursor: pointer;"><i class="fa-solid fa-print"></i> Cetak QR</button>
                    <a href="tambah_siswa" class="btn-add"><i class="fa-solid fa-plus"></i> Tambah Siswa Baru</a>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>NISN</th>
                            <th>Nama Lengkap</th>
                            <th>Kelas</th>
                            <th>Aksi Operasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($students)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--gray); padding: 30px;">
                                    <i class="fa-solid fa-users-slash" style="font-size: 32px; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                                    <?= ($search !== '' || $filter_class !== '') ? 'Tidak ada siswa yang cocok dengan pencarian / filter yang dipilih.' : 'Sistem belum memiliki data siswa terdaftar.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($students as $student): ?>
                                <tr>
                                    <td><span style="font-family: monospace; font-weight: 600; color: var(--gray);"><?= htmlspecialchars(!empty($student['nisn']) ? $student['nisn'] : '-') ?></span></td>
                                    <td><strong><?= htmlspecialchars($student['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($student['class']) ?></td>
                                    <td>
                                        <button class="btn-action qr" onclick="showQR('<?= $student['qr_token'] ?>', '<?= htmlspecialchars(addslashes($student['name'])) ?>', '<?= htmlspecialchars(!empty($student['nisn']) ? $student['nisn'] : '-') ?>')">
                                            <i class="fa-solid fa-magnifying-glass"></i> Lihat QR
                                        </button>
                                        <a href="data_siswa?action=edit&id=<?= $student['id'] ?>" class="btn-action edit">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </a>
                                        <a href="data_siswa?action=delete&id=<?= $student['id'] ?>" class="btn-action delete" onclick="return confirm('Peringatan: Menghapus data <?= htmlspecialchars(addslashes($student['name'])) ?> juga akan menghapus akun login dan seluruh riwayat absensinya. Anda yakin?')">
                                            <i class="fa-solid fa-trash-can"></i> Hapus
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total > 0): ?>
                <div class="pagination-info">
                    Menampilkan <?= $offset + 1 ?>â€“<?= min($offset + $per_page, $total) ?> dari <?= $total ?> siswa
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="data_siswa?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&filter_class=<?= urlencode($filter_class) ?>">&laquo; Sebelumnya</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="data_siswa?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter_class=<?= urlencode($filter_class) ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a class="page-link" href="data_siswa?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&filter_class=<?= urlencode($filter_class) ?>">Berikutnya &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Pilih Kelas untuk Cetak QR -->
    <div id="cetakModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeCetakQR()"><i class="fa-solid fa-xmark"></i></span>
            <h3 class="modal-title"><i class="fa-solid fa-print" style="color: var(--success); margin-right: 8px;"></i> Cetak QR Code</h3>
            <p style="color: var(--gray); font-size: 14px; font-weight: 600; margin-top: 6px;">Pilih kelas, semua kartu QR siswa akan dicetak sekaligus.</p>
            <form method="GET" action="cetak_qr" class="edit-form">
                <div class="form-group">
                    <label for="cetak_class">Pilih Kelas</label>
                    <select id="cetak_class" name="filter_class" required>
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-save-edit" style="background: var(--success);">
                    <i class="fa-solid fa-print"></i> Cetak QR Kelas
                </button>
                <button type="button" class="btn-cancel" onclick="closeCetakQR()"><i class="fa-solid fa-xmark"></i> Batal</button>
            </form>
        </div>
    </div>

    <!-- Modal Edit Data Siswa -->
    <div id="editModal" class="modal <?= !empty($edit_student) ? 'active' : '' ?>">
        <div class="modal-content">
            <span class="close-modal" onclick="closeEdit()"><i class="fa-solid fa-xmark"></i></span>
            <h3 class="modal-title"><i class="fa-solid fa-pen-to-square" style="color: var(--primary); margin-right: 8px;"></i> Edit Data Siswa</h3>
            <p style="color: var(--gray); font-size: 14px; font-weight: 600; margin-top: 6px;">Ubah NISN, Nama, Kelas, dan data profil siswa</p>
            
            <?php if(!empty($edit_student)): ?>
                <?php if(!empty($error)): ?>
                    <div class="alert danger" style="margin-top: 20px; margin-bottom: 0;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>
                <form method="POST" action="data_siswa" class="edit-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$edit_student['id'] ?>">
                    <div class="form-group">
                        <label for="edit_nisn">NISN (Nomor Induk Siswa Nasional)</label>
                        <input type="text" id="edit_nisn" name="nisn" value="<?= htmlspecialchars($edit_student['nisn']) ?>" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="edit_name">Nama Lengkap Siswa</label>
                        <input type="text" id="edit_name" name="name" value="<?= htmlspecialchars($edit_student['name']) ?>" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="edit_tempat_lahir">Tempat Lahir</label>
                        <input type="text" id="edit_tempat_lahir" name="tempat_lahir" value="<?= htmlspecialchars($edit_student['tempat_lahir']) ?>" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="edit_tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="edit_tanggal_lahir" name="tanggal_lahir" value="<?= htmlspecialchars($edit_student['tanggal_lahir']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_jenis_kelamin">Jenis Kelamin</label>
                        <select id="edit_jenis_kelamin" name="jenis_kelamin">
                            <option value="">-- Pilih Jenis Kelamin --</option>
                            <option value="L" <?= $edit_student['jenis_kelamin'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="P" <?= $edit_student['jenis_kelamin'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_alamat">Alamat</label>
                        <input type="text" id="edit_alamat" name="alamat" value="<?= htmlspecialchars($edit_student['alamat']) ?>" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="edit_foto">Foto Siswa (opsional, maks 2MB)</label>
                        <input type="file" id="edit_foto" name="foto" accept=".jpg,.jpeg,.png,.webp">
                    </div>
                    <div class="form-group">
                        <label for="edit_class">Kelas / Rombongan Belajar</label>
                        <select id="edit_class" name="class" required>
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $edit_student['class'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-save-edit">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                    </button>
                    <a href="data_siswa" class="btn-cancel"><i class="fa-solid fa-xmark"></i> Batal</a>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- QR Code Preview Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeQR()"><i class="fa-solid fa-xmark"></i></span>
            <h3 id="modalName" style="margin-bottom: 6px; color: var(--dark); font-size: 20px;">Nama Siswa</h3>
            <p id="modalNis" style="color: var(--gray); font-size: 14px; font-weight: 600;">NISN</p>
            <div id="printArea">
                <!-- API pembuat QR Code -->
                <img id="qrImage" src="" alt="QR Code">
            </div>
            <button class="btn-print-qr" onclick="printQR()">
                <i class="fa-solid fa-print"></i> Cetak Kartu QR Code
            </button>
        </div>
    </div>

    <script>
        // Interaksi Mobile Menu
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
            document.querySelector('.top-header').classList.toggle('active');
        }

        function toggleSubmenu(el) {
            el.closest('.has-submenu').classList.toggle('open');
        }

        // Interaksi Modal QR Code
        const modal = document.getElementById('qrModal');
        const qrImage = document.getElementById('qrImage');
        const modalName = document.getElementById('modalName');
        const modalNis = document.getElementById('modalNis');

        function showQR(token, name, nisn) {
            modalName.innerText = name;
            modalNis.innerText = "NISN: " + nisn;
            qrImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(token)}`;
            modal.style.display = 'flex';
        }

        function closeQR() {
            modal.style.display = 'none';
        }

        // Interaksi Modal Edit Siswa
        function closeEdit() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Interaksi Modal Cetak QR
        function openCetakQR() {
            document.getElementById('cetakModal').classList.add('active');
        }

        function closeCetakQR() {
            document.getElementById('cetakModal').classList.remove('active');
        }

        function printQR() {
            const win = window.open('', '_blank');
            win.document.write(`
                <html>
                <head>
                    <title>Cetak QR Code - ${modalName.innerText}</title>
                    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
                    <style>
                        body { font-family: 'Inter', sans-serif; text-align: center; padding: 40px; background: #fff; }
                        .card { border: 2px solid #1e293b; padding: 30px; display: inline-block; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
                        h2 { margin: 0 0 5px 0; color: #0f172a; font-size: 22px; text-transform: uppercase; letter-spacing: 1px; }
                        p { margin: 0 0 20px 0; color: #475569; font-weight: 600; font-size: 16px; }
                        img { width: 220px; height: 220px;



x; border: 1px solid #e2e8f0; padding: 10px; border-radius: 12px; }
                        .footer { margin-top: 20px; font-size: 12px; color: #94a3b8; font-weight: 600; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <h2>${modalName.innerText}</h2>
                        <p>${modalNis.innerText}</p>
                        <img src="${qrImage.src}" alt="QR Code">
                        <div class="footer">KARTU ABSENSI RESMI</div>
                    </div>
                    <script>
                        // Beri waktu 0.5 detik agar gambar QR ter-load penuh sebelum jendela Print terbuka
                        window.onload = function() { 
                            setTimeout(function() {
                                window.print(); 
                                setTimeout(function() { window.close(); }, 500);
                            }, 500);
                        }
                    <\/script>
                </body>
                </html>
            `);
            win.document.close();
        }

        // Tutup modal jika user mengklik area abu-abu di luar kotak putih
        window.onclick = function(event) {
            if (event.target == modal) {
                closeQR();
            }
        }
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