<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya siswa yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt_student = $pdo->prepare("SELECT id, name FROM students WHERE user_id = ?");
$stmt_student->execute([$user_id]);
$student = $stmt_student->fetch();

if (!$student) {
    die("Data siswa tidak ditemukan.");
}

$student_id = $student['id'];

// Ambil Riwayat Kehadiran Pribadi Siswa
$stmt_attendance = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? ORDER BY date DESC, time DESC");
$stmt_attendance->execute([$student_id]);
$attendance_logs = $stmt_attendance->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Absensi - Portal Siswa</title>
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
        
        /* Data Panel & Table */
        .data-panel { background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .panel-header { margin-bottom: 25px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        .panel-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .panel-header h3 i { color: var(--primary); }
        .panel-header p { font-size: 14px; color: var(--gray); margin-top: 5px; margin-left: 28px; }
        
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        th { padding: 16px; background: var(--bg-light); color: var(--gray); font-weight: 600; font-size: 14px; border-bottom: 2px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 15px; transition: background 0.2s; }
        tbody tr:hover td { background: var(--bg-light); }
        
        /* Badge Status (Premium UI) */
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
            .navbar h2 { font-size: 18px; }
            .user-profile span { display: none; } /* Sembunyikan teks nama di HP agar rapi */
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
            <span><?= htmlspecialchars($student['name']) ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 11px; opacity: 0.8;"></i>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-name"><?= htmlspecialchars($student['name']) ?></div>
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
            <li><a href="index"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a></li>
            <li><a href="riwayat" class="active"><i class="fa-solid fa-calendar-check"></i> <span>Riwayat Absen</span></a></li>
            <li><a href="pengaturan"><i class="fa-solid fa-gear"></i> <span>Pengaturan</span></a></li>
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
                <h2>Jurnal Absensi</h2>
            </div>
            
        </div>

        <div class="data-panel">
            <div class="panel-header">
                <h3><i class="fa-solid fa-clock-rotate-left"></i> Riwayat Kehadiran Pribadi</h3>
                <p>Rekapitulasi seluruh aktivitas absensi Anda di sekolah.</p>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Tanggal Kehadiran</th>
                            <th>Jam Absen Masuk</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($attendance_logs)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--gray); padding: 40px 20px;">
                                    <i class="fa-solid fa-calendar-xmark" style="font-size: 36px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                                    Anda belum memiliki riwayat rekaman absensi di sistem.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach($attendance_logs as $log): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><strong><?= date('d M Y', strtotime($log['date'])) ?></strong></td>
                                    <td><i class="fa-regular fa-clock" style="color: var(--gray); margin-right: 6px;"></i> <?= !empty($log['time_in']) ? htmlspecialchars($log['time_in']) : htmlspecialchars($log['time']) ?> WIB</td>
                                    <td>
                                        <span class="badge <?= strtolower($log['status']) ?>">
                                            <?php if($log['status'] == 'Hadir') echo '<i class="fa-solid fa-check"></i>'; ?>
                                            <?php if($log['status'] == 'Sakit') echo '<i class="fa-solid fa-pills"></i>'; ?>
                                            <?php if($log['status'] == 'Izin') echo '<i class="fa-solid fa-envelope"></i>'; ?>
                                            <?php if($log['status'] == 'Alpa') echo '<i class="fa-solid fa-xmark"></i>'; ?>
                                            <?= $log['status'] ?>
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