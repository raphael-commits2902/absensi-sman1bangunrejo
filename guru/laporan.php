<?php
session_start();
require_once '../config/database.php';
require_once '../lib/core.php';

// Pastikan hanya Guru yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guru') {
    header("Location: ../login.php"); exit;
}

$user_id = $_SESSION['user_id'];
$stmt_wk = $pdo->prepare("SELECT name, nip FROM guru WHERE user_id = ?");
$stmt_wk->execute([$user_id]);
$wk = $stmt_wk->fetch();

if (!$wk) die("Data Guru tidak valid. Silakan hubungi Administrator.");

// Inisialisasi Filter Pencarian & Kategori
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$filter_date = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';

// Susun Query SQL dinamis berdasarkan input filter
[$query_sql, $params] = absensi_laporan_query($pdo, $search_name, $filter_class, $filter_date);
$stmt = $pdo->prepare($query_sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Ambil daftar kelas untuk dropdown
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Ambil pengaturan waktu absensi untuk penentuan Terlambat
$waktu = absensi_get_waktu($pdo);
$batas_hadir = $waktu['hadir']['jam_batas'] ?? null;

// Daftar siswa yang Alpa (belum ada catatan) pada tanggal filter tertentu
$alpa_students = absensi_alpa_students($pdo, $filter_date, $filter_class);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Absensi - Guru</title>
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

        .main-content { margin-left: 0; padding: 30px 40px; min-height: 100vh; }

        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 16px 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .navbar-left { display: flex; align-items: center; gap: 16px; }
        .navbar h2 { font-size: 20px; font-weight: 700; color: var(--dark); }
        .btn-menu { display: block; background: none; border: none; font-size: 24px; color: var(--dark); cursor: pointer; }
        .user-profile { display: flex; align-items: center; gap: 12px; font-weight: 600; background: var(--bg-light); padding: 8px 16px; border-radius: 30px; }
        .user-profile i { color: var(--primary); font-size: 20px; }

        .filter-panel { background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .filter-panel h3 { font-size: 16px; margin-bottom: 15px; color: var(--dark); }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--gray); }
        .form-group input, .form-group select { padding: 10px 14px; border: 2px solid var(--border); border-radius: 8px; font-size: 14px; color: var(--dark-secondary); outline: none; transition: border-color 0.2s; background: var(--bg-light); }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary); background: #fff; }

        .btn-filter { padding: 11px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-filter:hover { background: var(--primary-hover); }
        .btn-print { padding: 11px 20px; background: var(--success); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 14px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-print:hover { background: #059669; }

        .data-panel { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .print-header { display: none; text-align: center; margin-bottom: 20px; }
        .print-header h1 { font-size: 24px; margin-bottom: 5px; text-transform: uppercase; }
        .print-header p { font-size: 14px; color: var(--gray); }

        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        th { padding: 16px; background: var(--bg-light); color: var(--gray); font-weight: 600; font-size: 14px; border-bottom: 2px solid var(--border); }
        td { padding: 16px; border-bottom: 1px solid var(--border); font-size: 15px; transition: background 0.2s; }
        tbody tr:hover td { background: var(--bg-light); }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 30px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .badge.hadir { background: var(--success-light); color: #065f46; }
        .badge.terlambat { background: var(--warning-light); color: #92400e; }
        .badge.sakit { background: var(--warning-light); color: #92400e; }
        .badge.izin { background: #e0f2fe; color: #0369a1; }
        .badge.alpa { background: var(--danger-light); color: #991b1b; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        @media (max-width: 992px) {
            .main-content { padding: 20px; }
            .navbar h2 { font-size: 18px; }
        }

        @media print {
            .sidebar, .filter-panel, .navbar, .sidebar-overlay { display: none !important; }
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
            body { background: white; }
            .print-header { display: block !important; }
            .data-panel { box-shadow: none !important; padding: 0 !important; }
            th, td { padding: 12px !important; font-size: 12px !important; border-bottom: 1px solid #000 !important; white-space: normal; }
            th { background: #f0f0f0 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
            .badge { border: 1px solid #000; background: transparent !important; color: #000 !important; padding: 2px 6px; border-radius: 4px; }
            .badge i { display: none; }
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

    <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo" class="brand-logo"> <span>Absensi SMAN 1 Bangunrejo</span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="index.php"><i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span></a></li>
            <li><a href="scan.php"><i class="fa-solid fa-camera"></i> <span>Scan Absensi</span></a></li>
            <li><a href="laporan.php" class="active"><i class="fa-solid fa-file-lines"></i> <span>Laporan Absensi</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="navbar">
            <div class="navbar-left">
                <button class="btn-menu" onclick="toggleSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2>Laporan Absensi</h2>
            </div>
            
        </div>

        <div class="filter-panel">
            <h3><i class="fa-solid fa-filter" style="color: var(--primary); margin-right: 8px;"></i> Saring Data Laporan</h3>
            <form method="GET" action="" id="formLaporan">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="search_name">Pencarian Nama</label>
                        <input type="text" id="search_name" name="search_name" value="<?= htmlspecialchars($search_name) ?>" placeholder="Ketik nama siswa...">
                    </div>
                    <div class="form-group">
                        <label for="filter_class">Berdasarkan Kelas</label>
                        <select id="filter_class" name="filter_class">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $filter_class === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter_date">Tanggal Spesifik</label>
                        <input type="date" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                    </div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Terapkan Filter</button>
                    <button type="button" class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Cetak / PDF</button>
                </div>
            </form>
        </div>

        <div class="data-panel">
            <div class="print-header">
                <h1>Laporan Kehadiran Siswa</h1>
                <p>
                    Dicetak pada: <?= date('d M Y, H:i') ?> |
                    Parameter: <?= empty($filter_class) && empty($filter_date) ? 'Semua Data Keseluruhan' : 'Data Tersaring' ?>
                </p>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>NISN</th>
                            <th>Nama Lengkap</th>
                            <th>Kelas</th>
                            <th>Tanggal Kehadiran</th>
                            <th>Jam Masuk</th>
                            <th>Jam Pulang</th>
                            <th>Jam Sholat</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($reports)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: var(--gray); padding: 30px;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 32px; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                                    Tidak ditemukan data absensi yang sesuai dengan filter Anda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach($reports as $row): $st = absensi_status($row, $batas_hadir); ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><span style="font-family: monospace; font-weight: 600; color: var(--gray);"><?= htmlspecialchars(!empty($row['nisn']) ? $row['nisn'] : '-') ?></span></td>
                                    <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['class']) ?></td>
                                    <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                                    <td><?= !empty($row['time_in']) ? '<i class="fa-regular fa-clock" style="color: var(--gray); margin-right: 4px;"></i> ' . htmlspecialchars($row['time_in']) : '<span style="color: #cbd5e1;">-</span>' ?></td>
                                    <td><?= !empty($row['time_out']) ? '<i class="fa-regular fa-clock" style="color: var(--gray); margin-right: 4px;"></i> ' . htmlspecialchars($row['time_out']) : '<span style="color: #cbd5e1;">-</span>' ?></td>
                                    <td><?= !empty($row['time_sholat']) ? '<i class="fa-regular fa-clock" style="color: var(--gray); margin-right: 4px;"></i> ' . htmlspecialchars($row['time_sholat']) : '<span style="color: #cbd5e1;">-</span>' ?></td>
                                    <td>
                                        <span class="badge <?= strtolower($st) ?>">
                                            <?php if($st == 'Hadir') echo '<i class="fa-solid fa-check"></i>'; ?>
                                            <?php if($st == 'Terlambat') echo '<i class="fa-solid fa-clock"></i>'; ?>
                                            <?php if($st == 'Sakit') echo '<i class="fa-solid fa-pills"></i>'; ?>
                                            <?php if($st == 'Izin') echo '<i class="fa-solid fa-envelope"></i>'; ?>
                                            <?php if($st == 'Alpa') echo '<i class="fa-solid fa-xmark"></i>'; ?>
                                            <?= $st ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($filter_date) && !empty($alpa_students)): ?>
            <div class="data-panel" style="margin-top: 30px;">
                <h3 style="font-size: 16px; color: var(--dark); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-user-xmark" style="color: var(--danger);"></i> Siswa Alpa (Belum Absen) -
                    <?= date('d M Y', strtotime($filter_date)) ?>
                    <span class="badge alpa" style="margin-left: auto;"><?= count($alpa_students) ?> Siswa</span>
                </h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>NISN</th>
                                <th>Nama Lengkap</th>
                                <th>Kelas</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no_alpa = 1; foreach ($alpa_students as $alpa): ?>
                                <tr>
                                    <td><?= $no_alpa++ ?></td>
                                    <td><span style="font-family: monospace; font-weight: 600; color: var(--gray);"><?= htmlspecialchars(!empty($alpa['nisn']) ? $alpa['nisn'] : '-') ?></span></td>
                                    <td><strong><?= htmlspecialchars($alpa['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($alpa['class']) ?></td>
                                    <td><span class="badge alpa"><i class="fa-solid fa-user-xmark"></i> Alpa</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

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