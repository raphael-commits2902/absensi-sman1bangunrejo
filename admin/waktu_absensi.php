<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Pastikan tabel waktu_absensi ada + data default
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS waktu_absensi (
        id INT(11) NOT NULL AUTO_INCREMENT,
        jenis VARCHAR(20) NOT NULL,
        jam_mulai TIME DEFAULT NULL,
        jam_batas TIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY jenis (jenis)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        'hadir'  => ['06:30:00', '07:00:00'],
        'pulang' => ['15:00:00', '16:00:00'],
        'sholat' => ['11:30:00', '12:30:00'],
    ];
    $stmt_seed = $pdo->prepare("INSERT IGNORE INTO waktu_absensi (jenis, jam_mulai, jam_batas) VALUES (?, ?, ?)");
    foreach ($defaults as $jenis => $times) {
        $stmt_seed->execute([$jenis, $times[0], $times[1]]);
    }
} catch (PDOException $e) {
    // abaikan jika tabel sudah ada
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach (['hadir', 'pulang', 'sholat'] as $jenis) {
            $jam_mulai = isset($_POST[$jenis . '_mulai']) ? trim($_POST[$jenis . '_mulai']) : '';
            $jam_batas = isset($_POST[$jenis . '_batas']) ? trim($_POST[$jenis . '_batas']) : '';

            $stmt_upd = $pdo->prepare("UPDATE waktu_absensi SET jam_mulai = ?, jam_batas = ? WHERE jenis = ?");
            $stmt_upd->execute([$jam_mulai, $jam_batas, $jenis]);
        }
        $success = "Pengaturan waktu absensi berhasil disimpan.";
    } catch (PDOException $e) {
        $error = "Terjadi kesalahan database: " . $e->getMessage();
    }
}

// Ambil pengaturan waktu saat ini
$settings = [];
foreach ($pdo->query("SELECT * FROM waktu_absensi") as $row) {
    $settings[$row['jenis']] = $row;
}

$labels = [
    'hadir'  => ['Absen Hadir', 'Waktu mulai absen masuk dan batas akhir hadir. Scan sebelum batas = Hadir, setelah batas = Terlambat.', 'fa-right-to-bracket', '#3b82f6', '#eff6ff'],
    'pulang' => ['Absen Pulang', 'Waktu mulai pulang dan batas akhir absen pulang. Scan sebelum waktu mulai dianggap belum waktunya pulang.', 'fa-right-from-bracket', '#f59e0b', '#fef3c7'],
    'sholat' => ['Absen Sholat', 'Waktu mulai sholat dan batas akhir absen sholat. Scan setelah batas = Terlambat sholat.', 'fa-mosque', '#10b981', '#d1fae5'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waktu Absensi - Admin Panel</title>
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

        .info-box { background: var(--primary-light); padding: 15px; border-radius: 10px; margin-bottom: 25px; font-size: 13px; color: #1e40af; display: flex; gap: 10px; align-items: flex-start; line-height: 1.6; }
        .info-box i { font-size: 16px; margin-top: 2px; }
        .info-box b { font-weight: 800; }

        .form-panel { background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .form-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 25px; color: var(--dark); display: flex; align-items: center; gap: 10px; }

        .time-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; }
        .time-card { border: 2px solid var(--border); border-radius: 16px; padding: 22px; transition: all 0.2s ease; }
        .time-card:hover { border-color: var(--primary); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
        .time-card .card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .time-card .card-head .icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; }
        .time-card .card-head h4 { font-size: 15px; font-weight: 800; color: var(--dark); }
        .time-card .card-head p { font-size: 12px; color: var(--gray); margin-top: 2px; line-height: 1.4; }
        .time-card .time-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px; }
        .time-card .form-group label { display: block; margin-bottom: 6px; color: var(--gray); font-weight: 600; font-size: 12px; }
        .time-card .form-group input { width: 100%; padding: 10px 12px; border: 2px solid var(--border); border-radius: 10px; font-size: 15px; color: var(--dark-secondary); outline: none; background: var(--bg-light); transition: all 0.2s ease; }
        .time-card .form-group input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .time-card .info-status { margin-top: 14px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        .btn-submit { padding: 14px 30px; background: var(--primary); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 10px; }
        .btn-submit:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2); }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-clipboard-check"></i> <span>Absensi</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="submenu">
                    <li><a href="scan?type=hadir"><i class="fa-solid fa-right-to-bracket"></i> <span>Absen Hadir</span></a></li>
                    <li><a href="scan?type=pulang"><i class="fa-solid fa-right-from-bracket"></i> <span>Absen Pulang</span></a></li>
                    <li><a href="scan?type=sholat"><i class="fa-solid fa-mosque"></i> <span>Absen Sholat</span></a></li>
                    <li><a href="waktu_absensi" class="active"><i class="fa-solid fa-clock"></i> <span>Waktu Absensi</span></a></li>
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
                <h2>Waktu Absensi</h2>
            </div>
            
        </div>

        <div class="info-box">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <b>Aturan penentuan status:</b> Siswa yang scan sebelum jam batas = <b>Hadir</b> (tepat waktu). Siswa yang scan setelah jam batas = <b>Terlambat</b>. Siswa yang sama sekali tidak melakukan absen = <b>Alpa</b>.
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

        <div class="form-panel">
            <h3><i class="fa-solid fa-sliders" style="color: var(--primary);"></i> Kelola Waktu Absensi</h3>

            <form method="POST" action="">
                <div class="time-grid">
                    <?php foreach (['hadir', 'pulang', 'sholat'] as $jenis): ?>
                        <?php $lbl = $labels[$jenis]; $set = $settings[$jenis] ?? []; ?>
                        <div class="time-card">
                            <div class="card-head">
                                <div class="icon" style="background: <?= $lbl[3] ?>;">
                                    <i class="fa-solid <?= $lbl[2] ?>"></i>
                                </div>
                                <div>
                                    <h4><?= $lbl[0] ?></h4>
                                    <p><?= $lbl[1] ?></p>
                                </div>
                            </div>
                            <div class="time-fields">
                                <div class="form-group">
                                    <label for="<?= $jenis ?>_mulai">Jam Mulai</label>
                                    <input type="time" id="<?= $jenis ?>_mulai" name="<?= $jenis ?>_mulai" value="<?= htmlspecialchars($set['jam_mulai'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="<?= $jenis ?>_batas">Jam Batas</label>
                                    <input type="time" id="<?= $jenis ?>_batas" name="<?= $jenis ?>_batas" value="<?= htmlspecialchars($set['jam_batas'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="info-status" style="color: <?= $lbl[3] ?>;">
                                <i class="fa-solid fa-circle-info"></i>
                                Mulai: <b><?= htmlspecialchars($set['jam_mulai'] ?? '-') ?></b> &middot; Batas: <b><?= htmlspecialchars($set['jam_batas'] ?? '-') ?></b>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn-submit" style="margin-top: 25px;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Waktu Absensi
                </button>
            </form>
        </div>
    </div>

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