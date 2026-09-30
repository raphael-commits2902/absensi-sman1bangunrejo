<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guru') {
    header("Location: ../login.php"); exit;
}

$hari_ini = date('Y-m-d');

// Ambil daftar siswa yang sudah absen hari ini
$stmt_today = $pdo->prepare("SELECT s.name, s.nis, s.class, a.time, a.status FROM attendance a JOIN students s ON a.student_id = s.id WHERE a.date = ? ORDER BY a.time DESC");
$stmt_today->execute([$hari_ini]);
$today_attendance = $stmt_today->fetchAll();

// Ambil jam batas absen hadir untuk penentuan Terlambat
$batas_hadir = $pdo->query("SELECT jam_batas FROM waktu_absensi WHERE jenis = 'hadir'")->fetchColumn();
if (empty($batas_hadir)) $batas_hadir = '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kamera Absensi - Guru</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Scanner & SweetAlert -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #0f172a; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; color: white; }
        
        .scanner-container { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); padding: 30px; border-radius: 24px; width: 100%; max-width: 550px; text-align: center; backdrop-filter: blur(10px); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
        .scanner-container h2 { font-size: 24px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.5px; }
        .scanner-container p { color: #94a3b8; font-size: 14px; margin-bottom: 25px; line-height: 1.5; }
        
        .scan-mode-switch { display: flex; gap: 10px; margin-bottom: 20px; justify-content: center; flex-wrap: wrap; }
        .scan-mode-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border: 2px solid #334155; background: rgba(255,255,255,0.05); color: #94a3b8; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s ease; }
        .scan-mode-btn.active { border-color: #3b82f6; background: #1e3a8a; color: white; }
        .scan-mode-btn:hover { border-color: #3b82f6; color: white; }
        .scanner-box { display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; border: 2px dashed #3b82f6; border-radius: 16px; background: rgba(255,255,255,0.04); padding: 30px 20px; }
        .scanner-box > i { font-size: 48px; color: #3b82f6; }
        .scanner-box label { font-size: 15px; font-weight: 700; color: white; }
        .scanner-box input { width: 100%; max-width: 420px; padding: 14px 18px; border: 2px solid #475569; border-radius: 12px; font-size: 16px; color: white; outline: none; background: #1e293b; text-align: center; letter-spacing: 1px; transition: all 0.2s ease; }
        .scanner-box input:focus { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        .scanner-box small { font-size: 12px; color: #64748b; }
        
        #reader { width: 100%; border-radius: 16px; overflow: hidden; border: none !important; background: black; }
        #reader button { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 10px; transition: background 0.2s; }
        #reader button:hover { background: #2563eb; }
        #reader select { padding: 8px; margin-top: 10px; border-radius: 6px; background: #1e293b; color: white; border: 1px solid #475569; }
        
        .btn-back { display: inline-flex; align-items: center; gap: 8px; margin-top: 25px; color: #94a3b8; text-decoration: none; font-size: 14px; font-weight: 600; transition: color 0.2s; padding: 10px 20px; border-radius: 30px; background: rgba(255,255,255,0.1); }
        .btn-back:hover { color: white; background: rgba(255,255,255,0.2); }

        .history-container { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); padding: 24px; border-radius: 24px; width: 100%; max-width: 550px; margin-top: 20px; backdrop-filter: blur(10px); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
        .history-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .history-header h3 { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
        .history-header h3 i { color: #10b981; margin-right: 8px; }
        .history-count { background: #10b981; color: white; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 30px; }
        .history-list { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
        .history-list::-webkit-scrollbar { width: 6px; }
        .history-list::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        .history-item { display: flex; align-items: center; gap: 12px; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); padding: 10px 14px; border-radius: 12px; }
        .history-item .avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #10b981); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; color: white; flex-shrink: 0; }
        .history-item .info { flex: 1; min-width: 0; }
        .history-item .info .name { font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .history-item .info .meta { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .history-item .time { font-size: 12px; font-weight: 700; color: #10b981; flex-shrink: 0; }
        .history-empty { text-align: center; color: #64748b; font-size: 13px; padding: 20px 0; }
    

        /* Header Atas (oval / pill) */
        .top-header { position: relative; z-index: 1500; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 20px 0; padding: 10px 14px; border-radius: 999px; background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border: 1px solid rgba(255,255,255,0.18); box-shadow: 0 12px 30px -10px rgba(37, 99, 235, 0.6); transition: transform 0.3s ease; }
        .top-header.active { transform: translateX(260px); }
        .top-header img { width: 44px; height: 44px; border-radius: 50%; background: white; padding: 4px; object-fit: contain; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        .top-header h1 { color: white; font-size: 16px; font-weight: 800; letter-spacing: 0.4px; margin: 0; white-space: nowrap; }



        /* Header responsif: panjang menyesuaikan desktop/mobile */
        @media (max-width: 576px) {
            .top-header h1 { display: none; }
            .top-header img { width: 38px; height: 38px; }
        }

</style>
</head>
<body>
    <!-- Header Atas -->
    <header class="top-header">
        <a href="index" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
            <img src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
            <h1>Absensi SMAN 1 Bangunrejo</h1>
        </a>
    </header>

    <div class="scanner-container">
        <h2><i class="fa-solid fa-camera"></i> Scanner Absensi</h2>
        <p>Arahkan QR Code siswa ke kamera untuk mencatat kehadiran (jam masuk).</p>

        <!-- Mode Switch: Kamera / Scanner Alat -->
        <div class="scan-mode-switch">
            <button type="button" id="btnCamera" class="scan-mode-btn active" onclick="setMode('camera')"><i class="fa-solid fa-camera"></i> Kamera</button>
            <button type="button" id="btnScanner" class="scan-mode-btn" onclick="setMode('scanner')"><i class="fa-solid fa-qrcode"></i> Scanner Alat (USB)</button>
        </div>

        <!-- Mode Kamera -->
        <div id="cameraMode">
            <div id="reader"></div>
        </div>

        <!-- Mode Scanner Alat (keyboard-wedge USB) -->
        <div id="scannerMode" style="display:none;">
            <div class="scanner-box">
                <i class="fa-solid fa-qrcode"></i>
                <label for="scannerInput">Arahkan kursor ke kotak ini, lalu scan kartu siswa dengan alat scanner.</label>
                <input type="text" id="scannerInput" name="scannerInput" autocomplete="off" placeholder="Scan kartu siswa..." autofocus>
                <small>Scanner akan mengetik otomatis dan submit setelah selesai. Pastikan kartu berisi token QR absen siswa.</small>
            </div>
        </div>

        <a href="index.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    </div>

    <div class="history-container">
        <div class="history-header">
            <h3><i class="fa-solid fa-user-check"></i> Siswa Sudah Absen Hari Ini</h3>
            <span class="history-count" id="historyCount"><?= count($today_attendance) ?></span>
        </div>
        <div class="history-list" id="historyList">
            <?php if (count($today_attendance) > 0): ?>
                <?php foreach ($today_attendance as $row): ?>
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

    <script>
        let isScanning = true;

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
            const batas = '<?= $batas_hadir ?>';
            const terlambat = batas && time > batas.substring(0, 5);

            let photoHtml = foto
                ? `<img src="${foto}" style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid ${terlambat ? '#f59e0b' : '#10b981'};margin:10px auto;display:block;">`
                : `<div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#10b981);display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:white;margin:10px auto;">${data.name.charAt(0).toUpperCase()}</div>`;

            Swal.fire({
                title: terlambat ? '⚠️ Terlambat' : '✅ Berhasil Absen',
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
                // Setelah alert ditutup, refocus input scanner agar bisa scan berikutnya
                if (currentMode === 'scanner') document.getElementById('scannerInput').focus();
            });
        }

        function onScanSuccess(decodedText, decodedResult) {
            processToken(decodedText);
        }

        // Proses token QR (dipakai kamera & scanner alat sama-sama)
        // Beep offline via WebAudio — instan tanpa unduh audio dari URL
        // eksternal (mixkit) yang bisa lambat/blokir di jaringan sekolah.
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

            // Suara Beep
            playBeep();

            fetch('proses_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'qr_token=' + encodeURIComponent(token)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showSuccessAlert(data);
                    addToHistory(data);
                } else if (data.status === 'already') {
                    Swal.fire({ title: '⚠️ Sudah Absen', text: `${data.name} sudah absen hari ini.`, icon: 'warning', timer: 2500, showConfirmButton: false });
                } else {
                    Swal.fire({ title: '❌ Gagal', text: data.message, icon: 'error', timer: 2500, showConfirmButton: false });
                }
                // Aktifkan kembali secepatnya: total minimal ~600ms per scan
                // (dulu 3 detik tetap — inilah penyebab scan ke-2 lambat).
                setTimeout(resumeScanning, Math.max(0, 600 - (Date.now() - started)));
            }).catch(error => {
                Swal.fire({ title: 'Error', text: 'Kesalahan koneksi jaringan!', icon: 'error', timer: 2000, showConfirmButton: false });
                setTimeout(resumeScanning, 600);
            });
        }

        let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 15, qrbox: { width: 260, height: 260 } }, false);
        html5QrcodeScanner.render(onScanSuccess);

        // Mode aktif saat ini
        let currentMode = 'camera';

        function setMode(mode) {
            currentMode = mode;
            document.getElementById('btnCamera').classList.toggle('active', mode === 'camera');
            document.getElementById('btnScanner').classList.toggle('active', mode === 'scanner');
            document.getElementById('cameraMode').style.display = mode === 'camera' ? 'block' : 'none';
            document.getElementById('scannerMode').style.display = mode === 'scanner' ? 'block' : 'none';

            if (mode === 'scanner') {
                scannerInput.focus();
            }
        }

        // --- Scanner Alat (keyboard-wedge USB) ---
        const scannerInput = document.getElementById('scannerInput');
        let scanTimer = null;

        scannerInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const v = scannerInput.value.trim();
                if (v && v.length > 0) {
                    processToken(v);
                }
                scannerInput.value = '';
            }
        });

        // Fallback: jika scanner tidak mengirim Enter, auto-submit saat jeda ketikan
        document.addEventListener('keypress', function(e) {
            if (currentMode !== 'scanner') return;
            if (e.target !== scannerInput) {
                scannerInput.focus();
            }
            clearTimeout(scanTimer);
            scanTimer = setTimeout(function() {
                const v = scannerInput.value.trim();
                if (v && v.length > 5) {
                    processToken(v);
                }
                scannerInput.value = '';
            }, 300);
        });

        // Jaga fokus tetap di input scanner
        document.addEventListener('click', function(e) {
            if (currentMode === 'scanner') {
                scannerInput.focus();
            }
        });
    </script>


    <!-- Footer -->
    <footer style="text-align: center; padding: 18px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(100,116,139,0.2); margin-top: 24px;">
        Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
    </footer>
</body>
</html>