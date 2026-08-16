<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya siswa yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Ambil Informasi Detail Akun Siswa
$stmt_student = $pdo->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt_student->execute([$user_id]);
$student = $stmt_student->fetch();

if (!$student) {
    die("Data profil siswa tidak ditemukan di sistem database.");
}

$student_id = $student['id'];

// Hitung Ringkasan Kehadiran Bulan Ini
$bulan_ini = date('Y-m');
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND date LIKE ? AND status = 'Hadir'");
$stmt_count->execute([$student_id, $bulan_ini . '%']);
$total_hadir = $stmt_count->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Siswa - Absensi SMAN 1 Bangunrejo</title>
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
        
        /* Main Content */
        .main-content { margin-left: 0; padding: 30px 40px; min-height: 100vh; }
        
        /* Navbar Mobile (Hanya muncul di HP) */
        .navbar-mobile { display: none; justify-content: space-between; align-items: center; margin-bottom: 20px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-mobile h2 { font-size: 18px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        
        /* Welcome Banner */
        .welcome-box { background: linear-gradient(135deg, var(--dark) 0%, var(--dark-secondary) 100%); color: white; padding: 35px; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; }
        .welcome-text h1 { font-size: 24px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.5px; }
        .welcome-text p { color: #cbd5e1; font-size: 15px; }
        .welcome-icon { font-size: 48px; color: rgba(255, 255, 255, 0.15); }
        
        /* Grid Layout */
        .grid-student { display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; }
        
        /* Kartu QR Code */
        .card-qr { background: white; padding: 35px 30px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .qr-wrapper { background: var(--bg-light); padding: 20px; border-radius: 20px; border: 2px dashed var(--border); margin-bottom: 25px; display: inline-block; position: relative; }
        .qr-wrapper img { width: 220px; height: 220px; display: block; border-radius: 10px; background: white; padding: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .card-qr p { font-size: 14px; color: var(--gray); margin-bottom: 25px; line-height: 1.5; }
        
        .btn-action { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 700; text-decoration: none; text-align: center; font-size: 15px; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-action:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        
        /* Kartu Informasi Detail */
        .card-info { background: white; padding: 0; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; }
        .card-info-header { background: var(--bg-light); padding: 20px 30px; border-bottom: 1px solid var(--border); }
        .card-info-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .card-info-header h3 i { color: var(--primary); }
        
        .info-body { padding: 30px; }
        .info-group { margin-bottom: 25px; display: flex; align-items: flex-start; gap: 16px; }
        .info-group:last-child { margin-bottom: 0; }
        .info-icon { width: 48px; height: 48px; border-radius: 12px; background: var(--primary-light); color: var(--primary); display: flex; justify-content: center; align-items: center; font-size: 20px; flex-shrink: 0; }
        
        .info-group.success .info-icon { background: var(--success-light); color: var(--success); }
        
        .info-content { flex-grow: 1; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .info-group:last-child .info-content { border-bottom: none; padding-bottom: 0; }
        .info-label { font-size: 13px; color: var(--gray); font-weight: 600; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
        .info-value { font-size: 18px; font-weight: 700; color: var(--dark); }

        /* Mobile Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* Responsif (Mobile & Tablet) */
        @media (max-width: 992px) {
                        .main-content { padding: 20px; }
            .navbar-mobile { display: flex; }
            
            .grid-student { grid-template-columns: 1fr; }
            .welcome-icon { display: none; }
        }
        
        @media (max-width: 576px) {
            .welcome-box { padding: 25px; }
            .welcome-text h1 { font-size: 20px; }
            .qr-wrapper img { width: 180px; height: 180px; }
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
            <i class="fa-solid fa-circle-user" style="font-size: 24px; color: var(--primary);"></i>
            <span><?= htmlspecialchars($student['name'] ?? 'Siswa') ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 11px; opacity: 0.8;"></i>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-name"><?= htmlspecialchars($student['name'] ?? 'Siswa') ?></div>
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
            <li><a href="index" class="active"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a></li>
            <li><a href="riwayat"><i class="fa-solid fa-calendar-check"></i> <span>Riwayat Absen</span></a></li>
            <li><a href="pengaturan"><i class="fa-solid fa-gear"></i> <span>Pengaturan</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Navbar Mobile -->
        <div class="navbar-mobile">
            <button class="btn-menu" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h2>Portal Siswa</h2>

        </div>

        <!-- Welcome Banner -->
        <div class="welcome-box">
            <div class="welcome-text">
                <h1>Halo, <?= htmlspecialchars($student['name']) ?>!</h1>
                <p>Selamat datang di Absensi SMAN 1 Bangunrejo.</p>
            </div>
            <i class="fa-solid fa-hands-clapping welcome-icon"></i>
        </div>

        <div class="grid-student">
            <!-- Kartu QR Code -->
            <div class="card-qr">
                <div class="qr-wrapper">
                    <!-- Memanggil API QR gratis dengan resolusi tinggi -->
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($student['qr_token']) ?>" alt="QR Absen">
                </div>
                <p>Tunjukkan kartu QR Code ini di depan kamera Scanner Absensi milik petugas atau guru saat Anda masuk ke lingkungan sekolah.</p>
                <a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?= urlencode($student['qr_token']) ?>" target="_blank" class="btn-action">
                    <i class="fa-solid fa-download"></i> Buka & Unduh QR
                </a>
            </div>

            <!-- Detail Akun & Ringkasan -->
            <div class="card-info">
                <div class="card-info-header">
                    <h3><i class="fa-solid fa-id-card-clip"></i> Informasi Identitas Siswa</h3>
                </div>
                <div class="info-body">
                    <div class="info-group">
                        <div class="info-icon"><i class="fa-solid fa-id-badge"></i></div>
                        <div class="info-content">
                            <div class="info-label">NISN (Nomor Induk Siswa Nasional)</div>
                            <div class="info-value"><?= htmlspecialchars(!empty($student['nisn']) ? $student['nisn'] : '-') ?></div>
                        </div>
                    </div>
                    
                    <div class="info-group">
                        <div class="info-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div class="info-content">
                            <div class="info-label">Kelas / Rombongan Belajar</div>
                            <div class="info-value"><?= htmlspecialchars($student['class']) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-group success">
                        <div class="info-icon"><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="info-content">
                            <div class="info-label">Total Kehadiran Bulan Ini</div>
                            <div class="info-value" style="color: var(--success);"><?= $total_hadir ?> Hari Hadir</div>
                        </div>
                    </div>
                </div>
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