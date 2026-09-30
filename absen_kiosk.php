<?php
/**
 * absen_kiosk.php — Pos Absen dengan PIN (tanpa login akun)
 * Petugas pos absensi membuka halaman dengan PIN kiosk (disimpan ter-hash di DB).
 * - PIN benar  -> sesi kiosk perangkat terbuka (bertahan selama perangkat aktif)
 * - Admin yang login tetap langsung masuk tanpa PIN
 * - Tombol "Kunci Kiosk" untuk mengunci kembali
 */
session_start();
require_once 'config/database.php';
require_once 'lib/core.php';
date_default_timezone_set('Asia/Jakarta');

// ---------- GERBANG PIN ----------
$adalah_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$kiosk_terbuka = isset($_SESSION['kiosk_unlocked']) && $_SESSION['kiosk_unlocked'] === true;

if (!$adalah_admin && !$kiosk_terbuka) {
    // Tampilkan layar PIN
    $pin_error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kiosk_pin'])) {
        $pin = trim($_POST['kiosk_pin']);
        // Rate limit sederhana: max 5 percobaan / 5 menit per sesi
        if (!isset($_SESSION['kiosk_tries'])) $_SESSION['kiosk_tries'] = [];
        $_SESSION['kiosk_tries'] = array_values(array_filter($_SESSION['kiosk_tries'] ?? [], function ($t) {
            return $t > time() - 300;
        }));
        if (count($_SESSION['kiosk_tries']) >= 5) {
            $pin_error = 'Terlalu banyak percobaan. Tunggu beberapa menit lalu coba lagi.';
        } elseif ($pin === '') {
            $pin_error = 'Masukkan PIN.';
        } else {
            $hash = $pdo->query("SELECT pin_hash FROM kiosk_pin ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($hash && password_verify($pin, $hash)) {
                $_SESSION['kiosk_unlocked'] = true;
                $_SESSION['kiosk_tries'] = [];
                session_write_close();
                header("Location: absen_kiosk.php");
                exit;
            }
            $_SESSION['kiosk_tries'][] = time();
            session_write_close();
            $pin_error = 'PIN salah. Coba lagi.';
            session_start();
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pos Absen — Masukkan PIN | SMAN 1 Bangunrejo</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
            body { background: linear-gradient(rgba(15,23,42,0.92), rgba(15,23,42,0.92)), url('assets/sman1bare.jpeg') center/cover no-repeat fixed; background-color: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            .pin-card { background: white; padding: 40px; border-radius: 24px; width: 100%; max-width: 400px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); text-align: center; }
            .pin-card .logo { width: 72px; height: 72px; border-radius: 18px; background: #f8fafc; padding: 8px; object-fit: contain; margin-bottom: 14px; }
            .pin-card h1 { font-size: 20px; font-weight: 800; color: #0f172a; }
            .pin-card p { font-size: 13px; color: #64748b; margin: 6px 0 24px; }
            .pin-input-wrap { position: relative; margin-bottom: 14px; }
            .pin-input-wrap i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
            .pin-input-wrap input { width: 100%; padding: 15px 16px 15px 46px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 22px; letter-spacing: 10px; text-align: center; color: #0f172a; outline: none; background: #f8fafc; }
            .pin-input-wrap input:focus { border-color: #3b82f6; background: white; box-shadow: 0 0 0 4px rgba(59,130,246,0.15); }
            .pin-btn { width: 100%; padding: 15px; background: #3b82f6; color: white; border: none; border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
            .pin-btn:hover { background: #2563eb; }
            .pin-error { background: #fee2e2; color: #991b1b; padding: 12px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 14px; border-left: 4px solid #ef4444; text-align: left; }
            .pin-footer { margin-top: 22px; font-size: 12px; color: #94a3b8; }
            .pin-footer a { color: #3b82f6; text-decoration: none; font-weight: 700; }
        </style>
    </head>
    <body>
        <div class="pin-card">
            <img src="assets/logo_sman1.png" alt="Logo" class="logo">
            <h1>Pos Absensi</h1>
            <p>Masukkan PIN petugas untuk membuka halaman absen</p>
            <?php if (!empty($pin_error)): ?>
                <div class="pin-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($pin_error) ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="pin-input-wrap">
                    <i class="fa-solid fa-key"></i>
                    <input type="password" name="kiosk_pin" inputmode="numeric" pattern="[0-9]*" maxlength="12" placeholder="••••••••" autofocus autocomplete="off">
                </div>
                <button type="submit" class="pin-btn"><i class="fa-solid fa-unlock"></i> Buka Pos Absen</button>
            </form>
            <div class="pin-footer">Admin? <a href="login.php">Masuk dengan akun</a></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------- HALAMAN KIOSK (PIN benar / admin) ----------
// Aksi kunci kiosk
if (isset($_GET['action']) && $_GET['action'] === 'lock') {
    unset($_SESSION['kiosk_unlocked']);
    session_write_close();
    header("Location: absen_kiosk.php");
    exit;
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

// Riwayat absen hari ini sesuai jenis
$time_col = ['hadir' => 'a.time_in', 'pulang' => 'a.time_out', 'sholat' => 'a.time_sholat'][$type];
$stmt_history = $pdo->prepare("SELECT s.name, s.nis, s.class, a.time FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? AND $time_col IS NOT NULL ORDER BY a.time DESC");
$stmt_history->execute([$today]);
$today_history = $stmt_history->fetchAll();

// Jam batas untuk penentuan Terlambat
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
    <title>Pos Absen Admin - <?= $title[0] ?> | SMAN 1 Bangunrejo</title>
    <!-- Library Kamera -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        body { background: var(--bg-light); color: var(--dark-secondary); min-height: 100vh; }

        /* Header kiosk */
        .kiosk-header { background: var(--dark); color: white; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .kiosk-brand { display: flex; align-items: center; gap: 12px; }
        .kiosk-brand img { width: 44px; height: 44px; border-radius: 50%; background: white; padding: 4px; object-fit: contain; }
        .kiosk-brand h1 { font-size: 16px; font-weight: 800; }
        .kiosk-brand p { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .kiosk-login { color: #94a3b8; text-decoration: none; font-size: 12px; font-weight: 700; border: 1px solid #334155; padding: 8px 14px; border-radius: 999px; transition: all 0.2s; }
        .kiosk-login:hover { color: white; border-color: var(--primary); }

        .kiosk-wrap { max-width: 760px; margin: 24px auto 40px; padding: 0 16px; }

        /* Pilihan jenis absen */
        .type-tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
        .type-tab { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 16px 8px; background: white; border: 2px solid var(--border); border-radius: 16px; text-decoration: none; color: var(--gray); font-weight: 800; font-size: 13px; transition: all 0.2s ease; }
        .type-tab i { font-size: 22px; }
        .type-tab:hover { border-color: var(--primary); color: var(--primary); }
        .type-tab.active { border-color: var(--primary); background: var(--primary-light); color: var(--primary); box-shadow: 0 4px 14px rgba(59,130,246,0.15); }

        /* Panel scanner */
        .scan-panel { background: white; padding: 28px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .scan-panel h2 { font-size: 20px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
        .scan-panel h2 i { color: var(--success); }
        .scan-hint { font-size: 13px; color: var(--gray); margin-bottom: 18px; }

        .scan-mode-switch { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .scan-mode-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border: 2px solid var(--border); background: white; color: var(--gray); border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s ease; }
        .scan-mode-btn.active { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }

        .scanner-box { display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; border: 2px dashed var(--primary); border-radius: 16px; background: var(--primary-light); padding: 26px 18px; }
        .scanner-box > i { font-size: 44px; color: var(--primary); }
        .scanner-box label { font-size: 15px; font-weight: 700; color: var(--dark); }
        .scanner-box input { width: 100%; max-width: 420px; padding: 14px 18px; border: 2px solid var(--border); border-radius: 12px; font-size: 16px; color: var(--dark-secondary); outline: none; background: white; text-align: center; letter-spacing: 1px; }
        .scanner-box input:focus { border-color: var(--success); box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        .scanner-box small { font-size: 12px; color: var(--gray); }
        #reader { width: 100%; border-radius: 12px; overflow: hidden; }
        #reader video { border-radius: 12px; }

        /* Riwayat */
        .history-panel { background: white; padding: 24px; border-radius: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-top: 20px; }
        .history-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .history-header h3 { font-size: 15px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 8px; }
        .history-header h3 i { color: var(--success); }
        .history-count { background: var(--success-light); color: #059669; font-size: 12px; font-weight: 800; padding: 4px 12px; border-radius: 30px; }
        .history-list { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
        .history-item { display: flex; align-items: center; gap: 12px; background: var(--bg-light); border: 1px solid var(--border); padding: 10px 14px; border-radius: 12px; }
        .history-item .avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--success)); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; color: white; flex-shrink: 0; }
        .history-item .info { flex: 1; min-width: 0; }
        .history-item .info .name { font-size: 14px; font-weight: 700; color: var(--dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .history-item .info .meta { font-size: 12px; color: var(--gray); margin-top: 2px; }
        .history-item .time { font-size: 12px; font-weight: 700; color: var(--success); flex-shrink: 0; }
        .history-empty { text-align: center; color: var(--gray); font-size: 13px; padding: 20px 0; }

        .kiosk-footer { text-align: center; padding: 16px; color: var(--gray); font-size: 12px; }

        @media (max-width: 576px) {
            .type-tabs { grid-template-columns: 1fr; }
            .kiosk-brand h1 { font-size: 14px; }
        }
    </style>
</head>
<body>
    <header class="kiosk-header">
        <div class="kiosk-brand">
            <img src="assets/logo_sman1.png" alt="Logo">
            <div>
                <h1>Absensi SMAN 1 Bangunrejo</h1>
                <p>Pos Absensi Admin — <?= htmlspecialchars($_SESSION['username'] ?? 'admin') ?></p>
            </div>
        </div>
        <a href="admin/index.php" class="kiosk-login"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="absen_kiosk.php?action=lock" class="kiosk-login" style="border-color: #7f1d1d; color: #fca5a5;"><i class="fa-solid fa-lock"></i> Kunci Kiosk</a>
    </header>

    <div class="kiosk-wrap">
        <!-- Pilihan jenis absen -->
        <div class="type-tabs">
            <a href="absen_kiosk.php?type=hadir" class="type-tab <?= $type === 'hadir' ? 'active' : '' ?>">
                <i class="fa-solid fa-right-to-bracket"></i> Absen Hadir
            </a>
            <a href="absen_kiosk.php?type=pulang" class="type-tab <?= $type === 'pulang' ? 'active' : '' ?>">
                <i class="fa-solid fa-right-from-bracket"></i> Absen Pulang
            </a>
            <a href="absen_kiosk.php?type=sholat" class="type-tab <?= $type === 'sholat' ? 'active' : '' ?>">
                <i class="fa-solid fa-mosque"></i> Absen Sholat
            </a>
        </div>

        <div class="scan-panel">
            <h2><i class="fa-solid <?= $title[2] ?>"></i> <?= $title[0] ?></h2>
            <div class="scan-hint"><?= $title[1] ?><?= $jam_batas !== '' ? ' — Batas: ' . htmlspecialchars(substr($jam_batas, 0, 5)) . ' WIB' : '' ?></div>

            <div class="scan-mode-switch">
                <button type="button" id="btnCamera" class="scan-mode-btn active" onclick="setMode('camera')"><i class="fa-solid fa-camera"></i> Kamera</button>
                <button type="button" id="btnScanner" class="scan-mode-btn" onclick="setMode('scanner')"><i class="fa-solid fa-qrcode"></i> Scanner Alat (USB)</button>
            </div>

            <div id="cameraMode">
                <div id="reader"></div>
            </div>

            <div id="scannerMode" style="display:none;">
                <div class="scanner-box">
                    <i class="fa-solid fa-qrcode"></i>
                    <label for="scannerInput">Arahkan kursor ke kotak ini, lalu scan kartu siswa.</label>
                    <input type="text" id="scannerInput" name="scannerInput" autocomplete="off" placeholder="Scan kartu siswa..." autofocus>
                    <small>Scanner mengetik token otomatis lalu submit. Kartu berisi QR absen siswa.</small>
                </div>
            </div>
        </div>

        <div class="history-panel">
            <div class="history-header">
                <h3><i class="fa-solid fa-user-check"></i> Sudah Absen Hari Ini</h3>
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

    <footer class="kiosk-footer">Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?></footer>

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
            const foto = data.foto ? data.foto : '';
            const nisn = data.nisn || '-';
            const time = (data.time || '00:00').substring(0, 5);
            const batas = '<?= $jam_batas ?>';
            const terlambat = batas && time > batas.substring(0, 5);

            let photoHtml = foto
                ? `<img src="${foto}" style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid ${terlambat ? '#f59e0b' : '#10b981'};margin:10px auto;display:block;">`
                : `<div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#10b981);display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:white;margin:10px auto;">${data.name.charAt(0).toUpperCase()}</div>`;

            Swal.fire({
                title: terlambat ? '⚠️ Terlambat Absen ' + data.jenis : '✅ Berhasil Absen ' + data.jenis,
                html: `
                    ${photoHtml}
                    <div style="font-size:22px;font-weight:800;color:#0f172a;margin-top:12px;">${data.name}</div>
                    <div style="font-size:14px;color:#64748b;margin-top:4px;">NISN: ${nisn}</div>
                    <div style="font-size:14px;color:${terlambat ? '#d97706' : '#64748b'};">Pukul ${time} WIB${terlambat ? ` (melewati batas ${batas})` : ''}</div>
                `,
                icon: terlambat ? 'warning' : 'success',
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: true,
                confirmButtonText: 'OK',
                confirmButtonColor: '#10b981',
                width: 380
            }).then(function() {
                if (currentMode === 'scanner') document.getElementById('scannerInput').focus();
            });
        }

        // Beep offline via WebAudio — instan tanpa unduh audio dari URL
        // eksternal (soundjay) yang bisa lambat/blokir di jaringan sekolah.
        let _audioCtx = null;
        function playBeep() {
            try {
                _audioCtx = _audioCtx || new (window.AudioContext || window.webkitAudioContext)();
                if (_audioCtx.state === 'suspended') _audioCtx.resume();
                const o = _audioCtx.createOscillator();
                const g = _audioCtx.createGain();
                o.type = 'square';
                o.frequency.value = 1600;
                g.gain.setValueAtTime(0.0001, _audioCtx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.12, _audioCtx.currentTime + 0.01);
                g.gain.exponentialRampToValueAtTime(0.0001, _audioCtx.currentTime + 0.18);
                o.connect(g); g.connect(_audioCtx.destination);
                o.start(); o.stop(_audioCtx.currentTime + 0.2);
            } catch (e) {}
        }

        // QR yang terbaca saat proses berjalan TIDAK dibuang — diantre dan
        // langsung diproses begitu scanner aktif kembali (scan 1 -> 2 cepat).
        let lastToken = '', lastTokenAt = 0, pendingToken = null;
        function resumeScanning() {
            isScanning = true;
            if (currentMode === 'scanner') document.getElementById('scannerInput').focus();
            if (pendingToken) {
                const t = pendingToken;
                pendingToken = null;
                processToken(t);
            }
        }

        function processToken(token) {
            if (!isScanning) { pendingToken = token; return; }
            const now = Date.now();
            // QR yang sama masih di frame kamera -> abaikan (cegah kirim dobel)
            if (token === lastToken && now - lastTokenAt < 1200) return;
            isScanning = false;
            lastToken = token;
            lastTokenAt = now;
            const started = now;

            playBeep();

            fetch('admin/proses_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'qr_token=' + encodeURIComponent(token) + '&type=' + encodeURIComponent(scanType)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showSuccessAlert(data);
                    addToHistory(data);
                } else {
                    Swal.fire({ title: 'Gagal!', text: data.message, icon: 'error', timer: 2000, showConfirmButton: false });
                }
                // Aktifkan kembali secepatnya: total minimal ~600ms per scan
                // (dulu 3 detik tetap — inilah penyebab scan ke-2 lambat).
                setTimeout(resumeScanning, Math.max(0, 600 - (Date.now() - started)));
            })
            .catch(() => {
                Swal.fire({ title: 'Error', text: 'Kesalahan koneksi jaringan!', icon: 'error', timer: 2000, showConfirmButton: false });
                setTimeout(resumeScanning, 600);
            });
        }

        function onScanSuccess(decodedText) {
            processToken(decodedText);
        }

        let currentMode = 'camera';
        // Scanner dioptimalkan agar QR cepat terbaca, termasuk dari jarak:
        // fps 15, resolusi ideal 720p, area QR adaptif (mengikuti ukuran frame).
        let html5QrcodeScanner = new Html5QrcodeScanner("reader", {
            fps: 15,
            qrbox: function(width, height) {
                const size = Math.max(180, Math.min(450, Math.floor(Math.min(width, height) * 0.75)));
                return { width: size, height: size };
            },
            disableFlip: false,
            rememberLastUsedCamera: true,
            videoConstraints: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        }, false);
        html5QrcodeScanner.render(onScanSuccess);

        function setMode(mode) {
            currentMode = mode;
            document.getElementById('btnCamera').classList.toggle('active', mode === 'camera');
            document.getElementById('btnScanner').classList.toggle('active', mode === 'scanner');
            document.getElementById('cameraMode').style.display = mode === 'camera' ? 'block' : 'none';
            document.getElementById('scannerMode').style.display = mode === 'scanner' ? 'block' : 'none';
            if (mode === 'scanner') scannerInput.focus();
        }

        const scannerInput = document.getElementById('scannerInput');
        let scanTimer = null;

        scannerInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const v = scannerInput.value.trim();
                if (v) processToken(v);
                scannerInput.value = '';
            }
        });

        document.addEventListener('keypress', function(e) {
            if (currentMode !== 'scanner') return;
            if (e.target !== scannerInput) scannerInput.focus();
            clearTimeout(scanTimer);
            scanTimer = setTimeout(function() {
                const v = scannerInput.value.trim();
                if (v && v.length > 5) processToken(v);
                scannerInput.value = '';
            }, 300);
        });

        document.addEventListener('click', function() {
            if (currentMode === 'scanner') scannerInput.focus();
        });
    </script>
</body>
</html>
