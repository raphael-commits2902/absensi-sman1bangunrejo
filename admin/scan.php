<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit;
}

$type = isset($_GET['type']) ? trim($_GET['type']) : 'hadir';
if (!in_array($type, ['hadir', 'pulang', 'sholat'])) $type = 'hadir';

$titles = [
    'hadir'  => ['Absen Hadir', 'Scan QR untuk mencatat kehadiran siswa (jam masuk)', 'fa-right-to-bracket'],
    'pulang' => ['Absen Pulang', 'Scan QR untuk mencatat jam pulang siswa', 'fa-right-from-bracket'],
    'sholat' => ['Absen Sholat', 'Scan QR untuk mencatat kehadiran sholat siswa', 'fa-mosque'],
];
$title = $titles[$type];

$today = date('Y-m-d');

// Ambil daftar siswa yang sudah absen hari ini sesuai jenis scan
$time_col = ['hadir' => 'a.time_in', 'pulang' => 'a.time_out', 'sholat' => 'a.time_sholat'][$type];
$stmt_history = $pdo->prepare("SELECT s.name, s.nis, s.class, a.time FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND $time_col IS NOT NULL ORDER BY a.time DESC");
$stmt_history->execute([$today]);
$today_history = $stmt_history->fetchAll();

// Ambil jam batas sesuai jenis scan untuk penentuan Terlambat
$stmt_waktu = $pdo->prepare("SELECT jam_batas FROM waktu_absensi WHERE jenis = ?");
$stmt_waktu->execute([$type]);
$jam_batas = $stmt_waktu->fetchColumn();
if (empty($jam_batas)) $jam_batas = '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan QR Code - <?= $title[0] ?> | Admin Panel</title>
    <!-- Library Kamera (Tanpa Composer) -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <!-- SweetAlert untuk Notifikasi Premium -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        
        /* Scanner Panel */
        .scan-panel { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 700px; }
        .scan-panel h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .scan-panel h3 i { color: var(--success); }
        .scan-hint { font-size: 13px; color: var(--gray); margin-bottom: 20px; }
        #reader { width: 100%; border-radius: 12px; overflow: hidden; }
        #reader video { border-radius: 12px; }
        .btn-back { display: inline-flex; align-items: center; gap: 8px; margin-top: 25px; padding: 12px 24px; background: var(--dark); color: white; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 14px; transition: all 0.2s ease; }
        .btn-back:hover { background: var(--dark-secondary); transform: translateY(-1px); }

        /* History Panel */
        .history-panel { background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 700px; margin-top: 20px; }
        .history-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .history-header h3 { font-size: 15px; font-weight: 700; color: var(--dark); }
        .history-header h3 i { color: var(--success); margin-right: 8px; }
        .history-count { background: var(--success-light); color: #059669; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 30px; }
        .history-list { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
        .history-list::-webkit-scrollbar { width: 6px; }
        .history-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .history-item { display: flex; align-items: center; gap: 12px; background: var(--bg-light); border: 1px solid var(--border); padding: 10px 14px; border-radius: 12px; }
        .history-item .avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--success)); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; color: white; flex-shrink: 0; }
        .history-item .info { flex: 1; min-width: 0; }
        .history-item .info .name { font-size: 14px; font-weight: 700; color: var(--dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .history-item .info .meta { font-size: 12px; color: var(--gray); margin-top: 2px; }
        .history-item .time { font-size: 12px; font-weight: 700; color: var(--success); flex-shrink: 0; }
        .history-empty { text-align: center; color: var(--gray); font-size: 13px; padding: 20px 0; }
        
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
                    <li><a href="scan?type=hadir" class="<?= $type === 'hadir' ? 'active' : '' ?>"><i class="fa-solid fa-right-to-bracket"></i> <span>Absen Hadir</span></a></li>
                    <li><a href="scan?type=pulang" class="<?= $type === 'pulang' ? 'active' : '' ?>"><i class="fa-solid fa-right-from-bracket"></i> <span>Absen Pulang</span></a></li>
                    <li><a href="scan?type=sholat" class="<?= $type === 'sholat' ? 'active' : '' ?>"><i class="fa-solid fa-mosque"></i> <span>Absen Sholat</span></a></li>
                    <li><a href="waktu_absensi"><i class="fa-solid fa-clock"></i> <span>Waktu Absensi</span></a></li>
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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar">
            <div class="navbar-left">
                <button class="btn-menu" onclick="toggleSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h2>Scan QR Code</h2>
            </div>
            
        </div>

        <div class="scan-panel">
            <h3><i class="fa-solid <?= $title[2] ?>"></i> <?= $title[0] ?></h3>
            <div class="scan-hint"><?= $title[1] ?></div>
            <div id="reader"></div>
            <a href="index" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
        </div>

        <div class="history-panel">
            <div class="history-header">
                <h3><i class="fa-solid fa-user-check"></i> Siswa Sudah Absen Hari Ini</h3>
                <span class="history-count" id="historyCount"><?= count($today_history) ?></span>
            </div>
            <div class="history-list" id="historyList">
                <?php if (count($today_history) > 0): ?>
                    <?php foreach ($today_history as $row): ?>
                        <div class="history-item">
                            <div class="avatar"><?= htmlspecialchars(strtoupper(substr($row['name'], 0, 1))) ?></div>
                            <div class="info">
                                <div class="name"><?= htmlspecialchars($row['name']) ?></div>
                                <div class="meta">NIS: <?= htmlspecialchars($row['nis']) ?> &middot; <?= htmlspecialchars($row['class']) ?></div>
                            </div>
                            <div class="time"><?= htmlspecialchars(date('H:i', strtotime($row['time']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="history-empty" id="historyEmpty">Belum ada siswa yang absen hari ini.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let isScanning = true;
        let scanType = '<?= $type ?>';

        function addToHistory(data) {
            const list = document.getElementById('historyList');
            const empty = document.getElementById('historyEmpty');
            if (empty) empty.remove();

            const name = data.name || 'Siswa';
            const nis = data.nis || '-';
            const kelas = data.class || '-';
            const time = (data.time || '00:00').substring(0, 5);

            const item = document.createElement('div');
            item.className = 'history-item';
            item.innerHTML = `
                <div class="avatar">${name.charAt(0).toUpperCase()}</div>
                <div class="info">
                    <div class="name">${name}</div>
                    <div class="meta">NIS: ${nis} &middot; ${kelas}</div>
                </div>
                <div class="time">${time}</div>
            `;
            list.prepend(item);

            const count = document.getElementById('historyCount');
            count.textContent = parseInt(count.textContent) + 1;
        }

        function showSuccessAlert(data) {
            const foto = data.foto ? '../' + data.foto : '';
            const nisn = data.nisn || '-';
            const time = (data.time || '00:00').substring(0, 5);
            const batas = '<?= $jam_batas ?>';
            const terlambat = batas && time > batas.substring(0, 5);

            let photoHtml = foto
                ? `<img src="${foto}" style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid ${terlambat ? '#f59e0b' : '#10b981'};margin:10px auto;display:block;">`
                : `<div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#10b981);display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:white;margin:10px auto;">${data.name.charAt(0).toUpperCase()}</div>`;

            Swal.fire({
                title: terlambat ? '⚠️ Terlambat' : '✅ Berhasil ' + data.jenis,
                html: `
                    ${photoHtml}
                    <div style="font-size:22px;font-weight:800;color:#0f172a;margin-top:12px;">${data.name}</div>
                    <div style="font-size:14px;color:#64748b;margin-top:4px;">NISN: ${nisn}</div>
                    <div style="font-size:14px;color:${terlambat ? '#d97706' : '#64748b'};">Pukul ${time} WIB${terlambat ? ` (melewati batas ${batas})` : ''}</div>
                `,
                icon: terlambat ? 'warning' : 'success',
                showConfirmButton: false,
                timer: 2500,
                width: 380
            });
        }

        function onScanSuccess(decodedText, decodedResult) {
            if (!isScanning) return; // Mencegah scan berulang saat loading
            isScanning = false;

            // Membunyikan nada beep (opsional)
            let audio = new Audio('https://www.soundjay.com/button/beep-07.wav');
            audio.play();

            // Kirim data ke backend
            fetch('proses_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'qr_token=' + encodeURIComponent(decodedText) + '&type=' + encodeURIComponent(scanType)
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    showSuccessAlert(data);
                    addToHistory(data);
                } else {
                    Swal.fire('Gagal!', data.message, 'error');
                }
                // Jeda 3 detik sebelum bisa scan lagi
                setTimeout(() => { isScanning = true; }, 3000);
            });
        }

        let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
        html5QrcodeScanner.render(onScanSuccess);

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
            document.querySelector('.top-header').classList.toggle('active');
        }

        function toggleSubmenu(el) {
            el.closest('.has-submenu').classList.toggle('open');
        }

        // Buka submenu Absensi jika ada item yang aktif
        var activeSub = document.querySelector('.submenu a.active');
        if (activeSub) activeSub.closest('.has-submenu').classList.add('open');
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