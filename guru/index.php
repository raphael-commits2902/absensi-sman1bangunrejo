<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya Guru yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guru') {
    header("Location: ../login.php"); exit;
}

$user_id = $_SESSION['user_id'];

// Ambil profil guru
$stmt_wk = $pdo->prepare("SELECT name, nip FROM guru WHERE user_id = ?");
$stmt_wk->execute([$user_id]);
$wk = $stmt_wk->fetch();

if(!$wk) die("Data Guru tidak valid. Silakan hubungi Administrator.");

$hari_ini = date('Y-m-d');

// Statistik Seluruh Siswa
$jml_siswa = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

$stmt_hadir = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND a.status = 'Hadir'");
$stmt_hadir->execute([$hari_ini]);
$jml_hadir = $stmt_hadir->fetchColumn();

$stmt_sakit = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND a.status = 'Sakit'");
$stmt_sakit->execute([$hari_ini]);
$jml_sakit = $stmt_sakit->fetchColumn();

$stmt_izin = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND a.status = 'Izin'");
$stmt_izin->execute([$hari_ini]);
$jml_izin = $stmt_izin->fetchColumn();

// Riwayat Absen Terbaru (Maks 10 Terbaru)
$stmt_recent = $pdo->prepare("SELECT a.*, s.name, s.nisn FROM attendance a JOIN students s ON a.student_id = s.id ORDER BY a.date DESC, a.time DESC LIMIT 10");
$stmt_recent->execute();
$recent = $stmt_recent->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Guru - Absensi SMAN 1 Bangunrejo</title>
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
        
        /* Navbar */
        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar h2 { font-size: 20px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        .user-profile { display: flex; align-items: center; gap: 12px; font-weight: 600; background: var(--bg-light); padding: 8px 16px; border-radius: 30px; }
        .user-profile i { color: var(--primary); font-size: 20px; }
        
        /* Dashboard Stats Grid */
        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 35px; }
        .card-stat { background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; transition: transform 0.3s ease, box-shadow 0.3s ease; border: 1px solid transparent; }
        .card-stat:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .card-info p { font-size: 14px; color: var(--gray); font-weight: 600; margin-bottom: 6px; }
        .card-info h3 { font-size: 28px; font-weight: 800; color: var(--dark); }
        .card-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; justify-content: center; align-items: center; font-size: 24px; }
        
        /* Color Variants for Stats */
        .stat-blue .card-icon { background: var(--primary-light); color: var(--primary); }
        .stat-blue:hover { border-color: var(--primary-light); }
        .stat-green .card-icon { background: var(--success-light); color: var(--success); }
        .stat-green:hover { border-color: var(--success-light); }
        .stat-yellow .card-icon { background: var(--warning-light); color: var(--warning); }
        .stat-yellow:hover { border-color: var(--warning-light); }
        .stat-red .card-icon { background: var(--danger-light); color: var(--danger); }
        .stat-red:hover { border-color: var(--danger-light); }
        
        /* Table Panel */
        .data-panel { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .panel-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        th { padding: 16px; background: var(--bg-light); color: var(--gray); font-weight: 600; font-size: 14px; border-bottom: 2px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 15px; transition: background 0.2s; }
        tbody tr:hover td { background: var(--bg-light); }
        
        /* Badge Status */
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 30px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .badge.hadir { background: var(--success-light); color: #065f46; }
        .badge.sakit { background: var(--warning-light); color: #92400e; }
        .badge.izin { background: #e0f2fe; color: #0369a1; }
        .badge.alpa { background: var(--danger-light); color: #991b1b; }

        /* Mobile Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* Responsif (Mobile & Tablet) */
        @media (max-width: 992px) {
                        .main-content { padding: 20px; }
            
            .navbar h2 { font-size: 16px; }
            .grid-stats { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        }

        @media (max-width: 576px) {
            .grid-stats { grid-template-columns: 1fr; }
            .user-profile span { display: none; }
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
            <i class="fa-solid fa-user-tie"></i>
            <span><?= htmlspecialchars($wk['name']) ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 11px; opacity: 0.8;"></i>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-name"><?= htmlspecialchars($wk['name']) ?></div>
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
            <li><a href="index.php" class="active"><i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span></a></li>
            <li><a href="scan.php"><i class="fa-solid fa-camera"></i> <span>Scan Absensi</span></a></li>
            <li><a href="laporan.php"><i class="fa-solid fa-file-lines"></i> <span>Laporan Absensi</span></a></li>
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
                <h2>Dashboard Guru</h2>
            </div>
            
        </div>

        <!-- Statistik Grid Khusus Kelas Tersebut -->
        <div class="grid-stats">
            <div class="card-stat stat-blue">
                <div class="card-info">
                    <p>Total Siswa</p>
                    <h3><?= $jml_siswa ?></h3>
                </div>
                <div class="card-icon"><i class="fa-solid fa-users"></i></div>
            </div>
            <div class="card-stat stat-green">
                <div class="card-info">
                    <p>Hadir (Hari Ini)</p>
                    <h3><?= $jml_hadir ?></h3>
                </div>
                <div class="card-icon"><i class="fa-solid fa-user-check"></i></div>
            </div>
            <div class="card-stat stat-yellow">
                <div class="card-info">
                    <p>Sakit (Hari Ini)</p>
                    <h3><?= $jml_sakit ?></h3>
                </div>
                <div class="card-icon"><i class="fa-solid fa-head-side-cough"></i></div>
            </div>
            <div class="card-stat stat-red">
                <div class="card-info">
                    <p>Izin/Alpa (Hari Ini)</p>
                    <h3><?= $jml_izin ?></h3>
                </div>
                <div class="card-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
            </div>
        </div>

        <!-- Tabel Riwayat Terbaru Kelas Tersebut -->
        <div class="data-panel">
            <div class="panel-header">
                <h3><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Aktivitas Absensi Terbaru</h3>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>NISN</th>
                            <th>Nama Siswa</th>
                            <th>Tanggal</th>
                            <th>Jam Masuk</th>
                            <th>Status Kehadiran</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($recent)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--gray); padding: 30px;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 32px; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                                    Belum ada aktivitas absensi hari ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($recent as $r): ?>
                                <tr>
                                    <td><span style="font-family: monospace; font-weight: 600; color: var(--gray);"><?= htmlspecialchars(!empty($r['nisn']) ? $r['nisn'] : '-') ?></span></td>
                                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                                    <td><?= date('d M Y', strtotime($r['date'])) ?></td>
                                    <td><i class="fa-regular fa-clock" style="color: var(--gray); margin-right: 4px;"></i> <?= htmlspecialchars($r['time']) ?></td>
                                    <td>
                                        <span class="badge <?= strtolower($r['status']) ?>">
                                            <?php if($r['status'] == 'Hadir') echo '<i class="fa-solid fa-check"></i>'; ?>
                                            <?php if($r['status'] == 'Sakit') echo '<i class="fa-solid fa-pills"></i>'; ?>
                                            <?php if($r['status'] == 'Izin') echo '<i class="fa-solid fa-envelope"></i>'; ?>
                                            <?php if($r['status'] == 'Alpa') echo '<i class="fa-solid fa-xmark"></i>'; ?>
                                            <?= $r['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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