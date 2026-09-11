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

// Statistik data yang bisa dihapus
$stat = [
    'total'  => $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn(),
    'hadir'  => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Hadir'")->fetchColumn(),
    'sakit'  => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Sakit'")->fetchColumn(),
    'izin'   => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Izin'")->fetchColumn(),
    'alpa'   => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Alpa'")->fetchColumn(),
];
$stat['terlambat'] = max(0, $stat['hadir'] - 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'hapus_hadir') {
            // Hanya daftar hadir (Hadir / Terlambat). Status 'Terlambat' bersifat
            // turunan (derived) — di DB tetap 'Hadir', jadi cukup hapus status 'Hadir'.
            // CATATAN: DELETE memakai transaksi. Reset AUTO_INCREMENT (ALTER TABLE)
            // adalah DDL yang otomatis commit, jadi dijalankan SETELAH transaksi tutup.
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM attendance WHERE status IN ('Hadir')");
            $pdo->commit();
            $pdo->exec("ALTER TABLE attendance AUTO_INCREMENT = 1");
            $success = 'Daftar hadir berhasil dihapus. Master data siswa, guru, kelas, dan akun tetap tersimpan.';
        } elseif ($action === 'hapus_semua') {
            // Seluruh riwayat absensi + reset id, master data tetap.
            // TRUNCATE meng-commit transaksi secara implisit, jadi pakai DELETE
            // agar tetap dalam transaksi, lalu reset AUTO_INCREMENT di luar transaksi.
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM attendance");
            $pdo->commit();
            $pdo->exec("ALTER TABLE attendance AUTO_INCREMENT = 1");
            $success = 'Seluruh riwayat absensi berhasil dihapus & ID direset. Master data siswa, guru, kelas, dan akun tetap tersimpan.';
        } else {
            $error = 'Aksi tidak valid.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Database Error: ' . $e->getMessage();
    }
}

// Refresh statistik setelah aksi
$stat = [
    'total'  => $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn(),
    'hadir'  => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Hadir'")->fetchColumn(),
    'sakit'  => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Sakit'")->fetchColumn(),
    'izin'   => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Izin'")->fetchColumn(),
    'alpa'   => $pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'Alpa'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bersihkan Data - Admin Panel</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
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

        .submenu-arrow { margin-left: auto; font-size: 12px; transition: transform 0.3s ease; }
        .has-submenu.open .submenu-arrow { transform: rotate(180deg); }
        .submenu { list-style: none; display: none; margin: 2px 0 6px; padding-left: 12px; }
        .has-submenu.open .submenu { display: block; }
        .submenu a { padding: 10px 12px; font-size: 14px; }
        .submenu a:hover, .submenu a.active { background: var(--dark-secondary); color: white; }

        .main-content { margin-left: 0; padding: 30px 40px; min-height: 100vh; }

        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar h2 { font-size: 20px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        .user-profile { display: flex; align-items: center; gap: 12px; font-weight: 600; background: var(--bg-light); padding: 8px 16px; border-radius: 30px; }
        .user-profile i { color: var(--primary); font-size: 20px; }

        .alert { padding: 16px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; font-weight: 500; border-left: 4px solid; display: flex; align-items: flex-start; gap: 12px; line-height: 1.5; }
        .alert i { font-size: 18px; margin-top: 2px; }
        .alert.success { background: var(--success-light); border-left-color: var(--success); color: #065f46; }
        .alert.danger { background: var(--danger-light); border-left-color: var(--danger); color: #991b1b; }

        .info-box { background: var(--warning-light); padding: 16px; border-radius: 12px; margin-bottom: 28px; font-size: 14px; color: #92400e; display: flex; gap: 12px; align-items: flex-start; line-height: 1.6; border-left: 4px solid var(--warning); }
        .info-box i { font-size: 18px; margin-top: 2px; }
        .info-box b { font-weight: 800; }

        /* Stat grid */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-mini { background: white; padding: 18px; border-radius: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); text-align: center; border: 1px solid var(--border); }
        .stat-mini p { font-size: 12px; color: var(--gray); font-weight: 600; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-mini h3 { font-size: 26px; font-weight: 800; color: var(--dark); }
        .stat-mini.blue { border-top: 4px solid var(--primary); }
        .stat-mini.green { border-top: 4px solid var(--success); }
        .stat-mini.yellow { border-top: 4px solid var(--warning); }
        .stat-mini.red { border-top: 4px solid var(--danger); }

        /* Action panels */
        .grid-panel { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 28px; align-items: start; }
        .action-panel { background: white; padding: 28px; border-radius: 18px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-top: 5px solid var(--border); }
        .action-panel.warn { border-top-color: var(--warning); }
        .action-panel.danger { border-top-color: var(--danger); }
        .action-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .action-panel p { font-size: 14px; color: var(--gray); line-height: 1.6; margin-bottom: 16px; }
        .action-panel ul { font-size: 14px; color: var(--gray); line-height: 1.7; margin: 0 0 20px 18px; }
        .action-panel li b { color: var(--dark-secondary); }

        .btn-danger { width: 100%; padding: 14px; background: var(--danger); color: white; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-2px); box-shadow: 0 8px 15px rgba(239, 68, 68, 0.3); }
        .btn-warning { width: 100%; padding: 14px; background: var(--warning); color: white; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-warning:hover { background: #d97706; transform: translateY(-2px); box-shadow: 0 8px 15px rgba(245, 158, 11, 0.3); }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

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

    <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

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
                    <li><a href="hapus_data" class="active"><i class="fa-solid fa-broom"></i> <span>Bersihkan Data</span></a></li>
                </ul>
            </li>
            <li><a href="data_siswa"><i class="fa-solid fa-users"></i> <span>Data Siswa</span></a></li>
            <li><a href="kelas"><i class="fa-solid fa-school"></i> <span>Kelas</span></a></li>
            <li><a href="cetak_kartu"><i class="fa-solid fa-id-card"></i> <span>Cetak Kartu Pelajar</span></a></li>
            <li><a href="tambah_guru"><i class="fa-solid fa-user-plus"></i> <span>Tambah Guru</span></a></li>
            <li><a href="input_absen"><i class="fa-solid fa-clipboard-user"></i> <span>Input Manual</span></a></li>
            <li><a href="laporan"><i class="fa-solid fa-file-lines"></i> <span>Laporan Absensi</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="navbar">
            <div class="navbar-left">
                <button class="btn-menu" onclick="toggleSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2>Bersihkan Data &amp; Reset</h2>
            </div>
            
        </div>

        <div class="info-box">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <b>Perhatian:</b> Aksi di halaman ini bersifat permanen dan tidak dapat dibatalkan (irreversible). Seluruh data yang dihapus tidak bisa dikembalikan. Pastikan Anda sudah mencadangkan (backup) data sebelum melanjutkan. <b>Data master</b> (akun admin/guru/siswa, siswa, guru, dan kelas) tidak akan dihapus — hanya riwayat absensi yang dibersihkan.
            </div>
        </div>

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

        <!-- Statistik Riwayat Absensi -->
        <div class="stat-grid">
            <div class="stat-mini blue">
                <p>Total Riwayat</p>
                <h3><?= $stat['total'] ?></h3>
            </div>
            <div class="stat-mini green">
                <p>Hadir</p>
                <h3><?= $stat['hadir'] ?></h3>
            </div>
            <div class="stat-mini yellow">
                <p>Sakit</p>
                <h3><?= $stat['sakit'] ?></h3>
            </div>
            <div class="stat-mini yellow">
                <p>Izin</p>
                <h3><?= $stat['izin'] ?></h3>
            </div>
            <div class="stat-mini red">
                <p>Alpa</p>
                <h3><?= $stat['alpa'] ?></h3>
            </div>
        </div>

        <div class="grid-panel">
            <!-- Pilihan 1: Hapus Daftar Hadir -->
            <div class="action-panel warn">
                <h3><i class="fa-solid fa-list-check" style="color: var(--warning);"></i> Hapus Daftar Hadir</h3>
                <p>Menghapus seluruh catatan <b>kehadiran</b> siswa (status Hadir / Terlambat) dari tabel absensi.</p>
                <ul>
                    <li><b>Yang dihapus:</b> riwayat kehadiran siswa.</li>
                    <li><b>Yang tetap:</b> siswa, guru, kelas, akun, serta riwayat Sakit/Izin/Alpa.</li>
                    <li>Penomoran ID riwayat direset ke awal.</li>
                </ul>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="hapus_hadir">
                    <button type="submit" class="btn-warning" onclick="return confirm('Yakin ingin menghapus SEMUA daftar hadir (Hadir/Terlambat)? Tindakan ini permanen. Master data siswa tetap tersimpan.')">
                        <i class="fa-solid fa-trash-can"></i> Hapus Daftar Hadir
                    </button>
                </form>
            </div>

            <!-- Pilihan 2: Hapus Semua Data -->
            <div class="action-panel danger">
                <h3><i class="fa-solid fa-fire" style="color: var(--danger);"></i> Hapus Semua Data Absensi</h3>
                <p>Menghapus <b>seluruh riwayat absensi</b> (Hadir, Terlambat, Sakit, Izin, Alpa) dan mereset ID ke awal.</p>
                <ul>
                    <li><b>Yang dihapus:</b> semua catatan absensi siswa.</li>
                    <li><b>Yang tetap:</b> siswa, guru, kelas, dan akun.</li>
                    <li>Penomoran ID riwayat direset ke awal.</li>
                </ul>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="hapus_semua">
                    <button type="submit" class="btn-danger" onclick="return confirm('Yakin ingin menghapus SEMUA data absensi (Hadir, Sakit, Izin, Alpa)? Tindakan ini PERMANEN dan tidak bisa dibatalkan. Master data siswa tetap tersimpan.')">
                        <i class="fa-solid fa-trash-can"></i> Hapus Semua Data
                    </button>
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
