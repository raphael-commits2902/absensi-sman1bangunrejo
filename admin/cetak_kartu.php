<?php
session_start();
require_once '../config/database.php';
require_once '../lib/core.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$classes = $pdo->query("SELECT name FROM kelas ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Jika belum memilih kelas, tampilkan kelas paling atas
if (empty($filter_class) && !empty($classes)) {
    $filter_class = $classes[0];
}

$students = [];
if (!empty($filter_class)) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE class = ? ORDER BY name ASC");
    $stmt->execute([$filter_class]);
    $students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Kartu Pelajar - <?= htmlspecialchars($filter_class) ?></title>
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
        
        .info-bar { max-width: 1000px; margin: 20px auto 0; padding: 0 20px; font-size: 14px; color: #64748b; }
        .info-bar strong { color: #0f172a; }
        
        /* Grid Kartu */
        .cards-grid { max-width: 1000px; margin: 20px auto 40px; padding: 0 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        
        /* Kartu Pelajar (ukuran sama dengan KTP: 85.60 x 53.98 mm) */
        .id-card { position: relative; background: white; border: 1.5px solid #1e293b; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.12); page-break-inside: avoid; width: 85.6mm; height: 53.98mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Watermark logo di tengah kartu */
        .id-card .watermark { position: absolute; top: 60%; left: 50%; transform: translate(-50%, -50%); width: 108px; height: 108px; object-fit: contain; opacity: 0.1; pointer-events: none; z-index: 0; }
        .id-card .card-kop, .id-card .card-title-band, .id-card .card-body, .id-card .card-footer { position: relative; z-index: 1; }
        /* Kop Surat: gradasi biru, logo kiri & kanan, teks di tengah */
        .id-card .card-kop { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #3b82f6 100%); padding: 7px 10px 6px; display: flex; align-items: center; justify-content: space-between; gap: 6px; border-bottom: 2px solid #93c5fd; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .id-card .card-kop img.kop-logo { width: 34px; height: 34px; object-fit: contain; flex-shrink: 0; background: white; border-radius: 10px; padding: 2px; }
        .id-card .card-kop .kop-text { text-align: center; min-width: 0; }
        .id-card .card-kop .kop-text .kop-line1 { font-size: 9px; font-weight: 800; color: white; letter-spacing: 0.5px; text-transform: uppercase; white-space: nowrap; }
        .id-card .card-kop .kop-text .kop-line2 { font-size: 8px; font-weight: 600; color: #bfdbfe; letter-spacing: 0.5px; margin-top: 1px; text-transform: uppercase; white-space: nowrap; }
        .id-card .card-kop .kop-text .kop-line3 { font-size: 9px; font-weight: 800; color: #fbbf24; letter-spacing: 1px; margin-top: 1px; text-transform: uppercase; white-space: nowrap; }
        /* Judul band modern dengan garis samping */
        .id-card .card-title-band { padding: 3px 10px; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 8px; font-weight: 800; letter-spacing: 2px; color: #1e3a8a; text-transform: uppercase; border-bottom: 1px dashed #cbd5e1; background: #f8fafc; }
        .id-card .card-title-band::before, .id-card .card-title-band::after { content: ''; height: 1.5px; width: 26px; background: linear-gradient(90deg, transparent, #3b82f6); border-radius: 2px; }
        .id-card .card-title-band::after { background: linear-gradient(90deg, #3b82f6, transparent); }
        .id-card .card-body { padding: 9px 10px; display: flex; gap: 8px; align-items: flex-start; }
        .id-card .avatar { width: 50px; height: 62px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 800; flex-shrink: 0; }
        .id-card .photo { width: 50px; height: 62px; border-radius: 8px; object-fit: cover; border: 1.5px solid #e2e8f0; background: white; flex-shrink: 0; }
        .id-card .card-info { flex: 1; min-width: 0; }
        .id-card .card-info .student-name { font-size: 12px; font-weight: 800; color: #0f172a; line-height: 1.25; word-break: break-word; }
        .id-card .card-info .detail { margin-top: 3px; font-size: 8.5px; color: #475569; font-weight: 600; display: flex; align-items: flex-start; gap: 4px; line-height: 1.35; }
        .id-card .card-info .detail i { color: #3b82f6; width: 10px; text-align: center; flex-shrink: 0; margin-top: 1px; }
        .id-card .card-info .detail .lbl { display: inline-flex; align-items: baseline; justify-content: space-between; width: 38px; color: #0f172a; font-weight: 700; flex-shrink: 0; }
        .id-card .card-info .detail .lbl .colon { flex-shrink: 0; }
        .id-card .card-info .detail .val { color: #475569; font-weight: 600; min-width: 0; word-break: break-word; }
        .id-card .card-qr { display: flex; flex-direction: column; align-items: center; gap: 2px; flex-shrink: 0; }
        .id-card .card-qr img { width: 50px; height: 50px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px; background: white; }
        .id-card .card-qr span { font-size: 6px; font-weight: 700; color: #94a3b8; letter-spacing: 1px; text-transform: uppercase; }
        .id-card .card-footer { background: #f8fafc; border-top: 1px dashed #cbd5e1; padding: 3px 10px; font-size: 6px; font-weight: 700; color: #94a3b8; letter-spacing: 1px; text-align: center; text-transform: uppercase; }
        
        .empty-state { max-width: 1000px; margin: 40px auto; padding: 40px 20px; text-align: center; background: white; border-radius: 16px; }
        .empty-state i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state p { color: #64748b; font-size: 15px; }
        
        /* Responsif */
        @media (max-width: 768px) {
            .cards-grid { grid-template-columns: 1fr; }
            .id-card .card-body { flex-wrap: wrap; }
        }
        
        /* Khusus Print: kartu tetap ukuran KTP 85.6 x 54mm */
        @media print {
            body { background: white; }
            .toolbar, .info-bar { display: none !important; }
            .cards-grid { max-width: 100%; margin: 0; padding: 0; gap: 8px; }
            .id-card { box-shadow: none; border: 1px solid #000; border-radius: 8px; }
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <div class="toolbar">
        <h1><i class="fa-solid fa-id-card"></i> Cetak Kartu Pelajar</h1>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <select id="select_kelas" onchange="goKelas()">
                <option value="">-- Pilih Kelas --</option>
                <?php foreach($classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filter_class === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Cetak Kartu</button>
            <?php if(!empty($filter_class) && !empty($students)): ?>
                <a class="btn-download" href="unduh_kartu?filter_class=<?= urlencode($filter_class) ?>"><i class="fa-solid fa-file-pdf"></i> Download PDF</a>
            <?php endif; ?>
            <a href="index" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <?php if(empty($filter_class)): ?>
        <!-- Belum pilih kelas -->
        <div class="empty-state">
            <i class="fa-solid fa-school-circle-question"></i>
            <p>Silakan pilih kelas pada menu di atas untuk melihat <strong>preview kartu pelajar</strong>.<br>Semua kartu siswa di kelas tersebut akan ditampilkan dan siap dicetak.</p>
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

        <!-- Preview Kartu Pelajar -->
        <div class="cards-grid">
            <?php foreach($students as $s): ?>
                <div class="id-card">
                    <div class="card-kop">
                        <img class="kop-logo" src="../assets/logo_lampung.png" alt="Logo Provinsi Lampung">
                        <div class="kop-text">
                            <div class="kop-line1">Pemerintahan Provinsi Lampung</div>
                            <div class="kop-line2">Dinas Pendidikan dan Kebudayaan</div>
                            <div class="kop-line3">SMAN 1 Bangunrejo</div>
                        </div>
                        <img class="kop-logo" src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
                    </div>
                    <div class="card-title-band">Kartu Pelajar</div>
                    <?php
                        // Format tempat & tanggal lahir
                        $ttl = trim($s['tempat_lahir']);
                        if (!empty($s['tanggal_lahir'])) {
                            $tgl = absensi_format_tgl_id($s['tanggal_lahir']);
                            $ttl = $ttl !== '' ? $ttl . ', ' . $tgl : $tgl;
                        }
                        $jk = $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($s['jenis_kelamin'] === 'P' ? 'Perempuan' : '-');
                        $foto_path = !empty($s['foto']) ? '../' . $s['foto'] : '';
                        ?>
                    <div class="card-body">
                        <?php if($foto_path !== '' && file_exists($foto_path)): ?>
                            <img class="photo" src="<?= htmlspecialchars($foto_path) ?>" alt="Foto <?= htmlspecialchars($s['name']) ?>">
                        <?php else: ?>
                            <img class="photo" src="../assets/default_photo.png" alt="Foto tidak tersedia">
                        <?php endif; ?>
                        <div class="card-info">
                            <div class="student-name"><?= htmlspecialchars($s['name']) ?></div>
                            <div class="detail"><i class="fa-solid fa-hashtag"></i> <span class="lbl">NISN<span class="colon">:</span></span><span class="val"><?= htmlspecialchars(!empty($s['nisn']) ? $s['nisn'] : '-') ?></span></div>
                            <div class="detail"><i class="fa-solid fa-cake-candles"></i> <span class="lbl">TTL<span class="colon">:</span></span><span class="val"><?= htmlspecialchars($ttl) ?></span></div>
                            <div class="detail"><i class="fa-solid fa-venus-mars"></i> <span class="lbl">JK<span class="colon">:</span></span><span class="val"><?= $jk ?></span></div>
                            <div class="detail"><i class="fa-solid fa-location-dot"></i> <span class="lbl">ALAMAT<span class="colon">:</span></span><span class="val"><?= htmlspecialchars(!empty($s['alamat']) ? $s['alamat'] : '-') ?></span></div>
                        </div>
                        <div class="card-qr">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($s['qr_token']) ?>" alt="QR">
                            <span>QR Absen</span>
                        </div>
                    </div>
                    <div class="card-footer">Kartu ini wajib dibawa setiap hari</div>
                    <img class="watermark" src="../assets/logo_sman1.png" alt="">
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
        function goKelas() {
            var kelas = document.getElementById('select_kelas').value;
            if (kelas) {
                window.location.href = 'cetak_kartu?filter_class=' + encodeURIComponent(kelas);
            }
        }
    </script>


    <!-- Footer -->
    <footer style="text-align: center; padding: 18px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(100,116,139,0.2); margin-top: 24px;">
        Absensi SMAN 1 Bangunrejo &copy; <?= date('Y') ?>
    </footer>
</body>
</html>