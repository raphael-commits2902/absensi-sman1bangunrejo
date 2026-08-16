<?php
session_start();
require_once '../config/database.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

$students = [];
if (!empty($filter_class)) {
    $stmt = $pdo->prepare("SELECT nisn, name, class, qr_token FROM students WHERE class = ? ORDER BY name ASC");
    $stmt->execute([$filter_class]);
    $students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak QR Code Siswa - <?= htmlspecialchars($filter_class) ?></title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome 6 CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #f1f5f9; color: #1e293b; }
        
        /* Toolbar (tidak ikut tercetak) */
        .toolbar { background: #0f172a; color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .toolbar h1 { font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .toolbar h1 i { color: #3b82f6; }
        .toolbar select { padding: 9px 14px; border-radius: 8px; border: none; font-size: 14px; min-width: 180px; outline: none; }
        .toolbar .btn-print { padding: 10px 18px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .toolbar .btn-print:hover { background: #2563eb; }
        .toolbar .btn-download { padding: 10px 18px; background: #10b981; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .toolbar .btn-download:hover { background: #059669; }
        .toolbar .btn-back { padding: 10px 18px; background: #1e293b; color: #94a3b8; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .toolbar .btn-back:hover { color: white; }
        
        /* Info baris */
        .info-bar { max-width: 1000px; margin: 20px auto 0; padding: 0 20px; font-size: 14px; color: #64748b; }
        .info-bar strong { color: #0f172a; }
        
        /* Grid Kartu QR */
        .cards-grid { max-width: 1000px; margin: 20px auto 40px; padding: 0 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        /* Kartu QR Code */
        .qr-card { background: white; border: 2px solid #1e293b; border-radius: 14px; padding: 20px; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08); page-break-inside: avoid; }
        .qr-card .card-school { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .qr-card h2 { font-size: 18px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }
        .qr-card .card-nis { font-size: 13px; font-weight: 700; color: #3b82f6; margin: 4px 0 14px; }
        .qr-card img { width: 170px; height: 170px; border: 2px solid #e2e8f0; padding: 8px; border-radius: 10px; background: white; }
        .qr-card .card-footer { margin-top: 14px; font-size: 11px; font-weight: 700; color: #64748b; letter-spacing: 2px; }
        
        .empty-state { max-width: 1000px; margin: 40px auto; padding: 40px 20px; text-align: center; background: white; border-radius: 16px; }
        .empty-state i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state p { color: #64748b; font-size: 15px; }
        
        /* Responsif */
        @media (max-width: 768px) {
            .cards-grid { grid-template-columns: 1fr; }
        }
        
        /* Khusus Print: hilangkan toolbar & rapatkan kartu */
        @media print {
            body { background: white; }
            .toolbar { display: none !important; }
            .info-bar { display: none !important; }
            .cards-grid { max-width: 100%; margin: 0; padding: 0; gap: 8px; }
            .qr-card { box-shadow: none; border: 1.5px solid #000; border-radius: 8px; padding: 10px; }
            .qr-card img { width: 110px; height: 110px; padding: 4px; }
            .qr-card h2 { font-size: 13px; }
            .qr-card .card-nis { font-size: 10px; margin-bottom: 6px; }
            .qr-card .card-school { font-size: 8px; margin-bottom: 3px; }
            .qr-card .card-footer { font-size: 8px; margin-top: 6px; }
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <div class="toolbar">
        <h1><i class="fa-solid fa-qrcode"></i> Cetak QR Code Siswa</h1>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <select id="select_kelas" onchange="goKelas()">
                <option value="">-- Pilih Kelas --</option>
                <?php foreach($classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filter_class === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Cetak QR</button>
            <?php if(!empty($filter_class) && !empty($students)): ?>
                <a href="download_qr?filter_class=<?= urlencode($filter_class) ?>" class="btn-download"><i class="fa-solid fa-download"></i> Download QR</a>
            <?php endif; ?>
            <a href="data_siswa" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <?php if(empty($filter_class)): ?>
        <!-- Belum pilih kelas -->
        <div class="empty-state">
            <i class="fa-solid fa-school-circle-question"></i>
            <p>Silakan pilih kelas pada menu di atas, lalu klik <strong>Cetak QR</strong>.<br>Semua kartu QR siswa di kelas tersebut akan dicetak sekaligus.</p>
        </div>
    <?php elseif(empty($students)): ?>
        <!-- Kelas tidak punya siswa -->
        <div class="empty-state">
            <i class="fa-solid fa-users-slash"></i>
            <p>Belum ada siswa terdaftar di kelas <strong><?= htmlspecialchars($filter_class) ?></strong>.</p>
        </div>
    <?php else: ?>
        <div class="info-bar">
            Kelas: <strong><?= htmlspecialchars($filter_class) ?></strong> — Total <?= count($students) ?> siswa
        </div>

        <!-- Grid Kartu QR -->
        <div class="cards-grid">
            <?php foreach($students as $s): ?>
                <div class="qr-card">
                    <div class="card-school">Kartu Absensi Digital</div>
                    <h2><?= htmlspecialchars($s['name']) ?></h2>
                    <div class="card-nis">NISN: <?= htmlspecialchars(!empty($s['nisn']) ? $s['nisn'] : '-') ?></div>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($s['qr_token']) ?>" alt="QR <?= htmlspecialchars($s['name']) ?>">
                    <div class="card-footer">KARTU ABSENSI RESMI</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
        function goKelas() {
            var kelas = document.getElementById('select_kelas').value;
            if (kelas) {
                window.location.href = 'cetak_qr?filter_class=' + encodeURIComponent(kelas);
            }
        }
        <?php if(!empty($filter_class) && !empty($students)): ?>
            window.onload = function() {
                setTimeout(function() { window.print(); }, 600);
            };
        <?php endif; ?>
    </script>


    <!-- Footer -->
    <footer style="text-align: center; padding: 18px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(100,116,139,0.2); margin-top: 24px;">
        Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
    </footer>
</body>
</html>