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

// Chunk 8 kartu per halaman A4 (2 kolom x 4 baris pada tata letak cetak).
// Halaman depan & belakang disusun berselang-seling per chunk dengan urutan
// siswa yang sama persis — sejajar 1:1 saat dicetak duplex (konsisten dengan
// PDF unduh_kartu.php yang memakai urutan sama, $duplex = 'none').
$card_pages = array_chunk($students, 8);
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
        .id-card { position: relative; background: white; border: 1.13px solid #000; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.12); page-break-inside: avoid; display: flex; flex-direction: column; width: 85.6mm; height: 53.98mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Watermark logo di tengah kartu */
        .id-card .watermark { position: absolute; top: 60%; left: 50%; transform: translate(-50%, -50%); width: 108px; height: 108px; object-fit: contain; opacity: 0.1; pointer-events: none; z-index: 0; }
        .id-card .card-kop, .id-card .card-title-band, .id-card .card-body, .id-card .card-footer { position: relative; z-index: 1; }
        /* Kop Surat: gradasi biru, logo kiri & kanan, teks di tengah */
        .id-card .card-kop { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #3b82f6 100%); padding: 7px 10px 6px; display: flex; align-items: center; justify-content: space-between; gap: 6px; border-bottom: 2px solid #93c5fd; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .id-card .card-kop img.kop-logo { width: 34px; height: 34px; object-fit: contain; flex-shrink: 0; }
        .id-card .card-kop .kop-text { text-align: center; min-width: 0; }
        .id-card .card-kop .kop-text .kop-line1 { font-size: 11.5px; font-weight: 800; color: white; letter-spacing: 0.5px; text-transform: uppercase; white-space: nowrap; }
        .id-card .card-kop .kop-text .kop-line2 { font-size: 10px; font-weight: 600; color: #bfdbfe; letter-spacing: 0.5px; margin-top: 1px; text-transform: uppercase; white-space: nowrap; }
        .id-card .card-kop .kop-text .kop-line3 { font-size: 11.5px; font-weight: 800; color: #fbbf24; letter-spacing: 1px; margin-top: 1px; text-transform: uppercase; white-space: nowrap; }
        /* Judul band modern dengan garis samping */
        .id-card .card-title-band { padding: 4px 10px 5px; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 10px; font-weight: 800; letter-spacing: 2px; color: #1e3a8a; text-transform: uppercase; background: #f1f1f1; }
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
        .id-card .card-info .detail .val { color: #000; font-weight: 600; min-width: 0; word-break: break-word; }
        /* ALAMAT: nilai wrap di kolom sendiri (hanging indent), berhenti sebelum zona tanda tangan */
        .id-card .card-info .detail.detail-wrap { display: block; position: relative; padding-left: 56px; max-width: calc(56px + 28.6mm); }
        .id-card .card-info .detail.detail-wrap i { position: absolute; left: 0; top: 1px; }
        .id-card .card-info .detail.detail-wrap .lbl { position: absolute; left: 14px; top: 0; }
        .id-card .card-info .detail.detail-wrap .val { display: inline; }
        .id-card .card-qr { display: flex; flex-direction: column; align-items: center; gap: 2px; flex-shrink: 0; margin-top: -4px; /* QR digeser ke atas sedikit (sinkron dengan PDF: 1.06mm) */ }
        .id-card .card-qr img { width: 57px; height: 57px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px; background: white; }
        .id-card .card-footer { margin-top: auto; background: #f8fafc; padding: 3px 10px; font-size: 6px; font-weight: 700; color: #94a3b8; letter-spacing: 1px; text-align: center; text-transform: uppercase; position: relative; }
        /* Blok tanda tangan Kepala Sekolah (kanan bawah) — gambar memuat cap + nama + NIP */
        .id-card .card-ttd { position: absolute; right: 1mm; bottom: 2mm; text-align: right; z-index: 2; line-height: 1.25; }
        .id-card .card-ttd img { height: 15mm; width: auto; display: inline-block; margin: 0.2mm 0; }

        /* ===== Kop halaman cetak (header atas) ===== */
        .print-header { max-width: 1000px; margin: 20px auto 0; padding: 14px 20px; background: white; border-radius: 12px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); display: flex; align-items: center; justify-content: space-between; gap: 14px; text-align: center; flex-wrap: wrap; }
        .print-header .ph-logo { width: 15mm; height: 15mm; object-fit: contain; flex-shrink: 0; }
        .print-header .ph-text { min-width: 0; }
        .print-header .ph-line1 { font-size: 12px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.6px; }
        .print-header .ph-line2 { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
        .print-header .ph-line3 { font-size: 11px; color: #64748b; margin-top: 2px; }
        .print-header .ph-title { display: inline-block; margin-top: 7px; background: #0f172a; color: #ffffff; font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; padding: 5px 14px; border-radius: 999px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Label "Sisi Belakang" (hanya tampil di layar, tidak ikut tercetak) */
        .side-label { max-width: 1000px; margin: 18px auto 6px; padding: 0 20px; font-size: 13px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; letter-spacing: 1.2px; display: flex; align-items: center; gap: 8px; }
        .side-label i { color: #3b82f6; }

        /* ===== Kartu sisi BELAKANG — mirror desain PDF (unduh_kartu.php):
               banner biru miring + logo, teks pengembalian, QR besar NISN,
               band bawah "BERLAKU SELAMA...", bingkai biru. ===== */
        .id-card.id-card-back { background: linear-gradient(rgba(30,107,184,0.07), rgba(30,107,184,0.07)), #ffffff; border: 0.5mm solid #1e6bb8; }
        .id-card-back .bk-banner { position: absolute; top: 0; left: 0; width: 100%; height: 9mm; background: #1e6bb8; clip-path: polygon(0 0, calc(100% - 5.5mm) 0, 100% 100%, 0 100%); z-index: 1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .id-card-back .bk-slash1 { position: absolute; top: 0; left: calc(100% - 8mm); width: 1.5mm; height: 9mm; background: #4fc3f7; transform: skewX(24deg); transform-origin: top left; }
        .id-card-back .bk-slash2 { position: absolute; top: 0; left: calc(100% - 6.2mm); width: 0.6mm; height: 9mm; background: #90caf9; transform: skewX(34deg); transform-origin: top left; }
        .id-card-back .bk-logo { position: absolute; top: 1mm; left: 50%; transform: translateX(-50%); width: 7mm; height: 7mm; object-fit: contain; z-index: 2; }
        .id-card-back .bk-msg { position: absolute; top: 9.8mm; left: 1mm; right: 1mm; text-align: center; font-size: 2.2mm; line-height: 2.2mm; color: #0f172a; font-weight: 500; overflow: hidden; z-index: 1; }
        .id-card-back .bk-qr { position: absolute; top: 17.4mm; left: 50%; transform: translateX(-50%); width: 24mm; height: 24mm; z-index: 1; }
        .id-card-back .bk-qr img { width: 24mm; height: 24mm; display: block; background: white; }
        .id-card-back .bk-nisn { position: absolute; top: 41.9mm; left: 0; width: 100%; text-align: center; font-size: 3mm; line-height: 2.3mm; font-weight: 800; color: #1e6bb8; z-index: 1; }
        .id-card-back .bk-band { position: absolute; left: 0; top: 45.5mm; width: 100%; bottom: 0; background: #1e6bb8; clip-path: polygon(0 0, 100% 0, calc(100% - 5.5mm) 100%, 0 100%); z-index: 1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .id-card-back .bk-band-slash { position: absolute; left: 0; top: 1.4mm; width: 100%; height: 0.8mm; background: #4fc3f7; clip-path: polygon(0 0, calc(100% - 0.91mm) 0, calc(100% - 1.43mm) 100%, 0 100%); }
        .id-card-back .bk-valid { position: absolute; left: 0; top: 2.9mm; width: 100%; text-align: center; color: #ffffff; font-weight: 800; font-size: 2.1mm; line-height: 3mm; letter-spacing: 0.2px; }

        .empty-state { max-width: 1000px; margin: 40px auto; padding: 40px 20px; text-align: center; background: white; border-radius: 16px; }
        .empty-state i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state p { color: #64748b; font-size: 15px; }
        
        /* Responsif */
        @media (max-width: 768px) {
            .cards-grid { grid-template-columns: 1fr; }
            .id-card .card-body { flex-wrap: wrap; }
        }
        
        /* Khusus Print: kertas A4 penuh tanpa margin browser — agar lebar
           cetak 210mm selalu cukup untuk 2 kartu (2 x 85.6mm + gap ~173mm)
           dan TIDAK memicu "fit/shrink-to-page": kartu tercetak 1:1 KTP. */
        @page { size: A4; margin: 0; }
        @media print {
            body { background: white; }
            .toolbar, .info-bar, .empty-state, footer, .side-label { display: none !important; }
            /* Kop halaman cetak — tinggi TETAP 22mm dipakai mengkompensasi
               padding halaman belakang PERTAMA (offset-header) supaya kartu
               belakang sejajar 1:1 dengan kartu depannya saat duplex. */
            .print-header { max-width: none; margin: 0; padding: 2.5mm 15mm 0; height: 22mm; border-radius: 0; box-shadow: none; overflow: hidden; flex-wrap: nowrap; }
            .print-header .ph-logo { width: 13mm; height: 13mm; }
            .print-header .ph-line1 { font-size: 9px; }
            .print-header .ph-line2 { font-size: 15px; margin-top: 1px; }
            .print-header .ph-line3 { font-size: 8.5px; margin-top: 1px; }
            .print-header .ph-title { font-size: 9.5px; padding: 3px 12px; margin-top: 4px; }
            /* Setiap blok = satu lembar A4 (8 kartu): sisi depan lalu sisi
               belakang berselang-seling → halaman cetak berpasangan. */
            .print-page { page-break-after: always; break-after: page; page-break-inside: avoid; break-inside: avoid; }
            .print-page.last-page { page-break-after: auto; break-after: auto; }
            /* Halaman belakang pertama mengikuti kop (22mm) — padatkan grid-nya */
            .print-page.offset-header .cards-grid { padding-top: calc(12mm + 22mm); }
            /* Grid presisi cetak: 2 kolom PAS selebar kartu KTP, terpusat di
               kertas; padding dalam menggantikan margin browser (@page margin 0). */
            .cards-grid {
                max-width: none; width: 100%;
                margin: 0; padding: 12mm 15mm;
                grid-template-columns: repeat(2, 85.6mm);
                gap: 2.5mm;
                justify-content: center;
            }
            /* Ukuran KTP 1:1 — sama persis dengan sisi belakang pada PDF
               hasil download (85.60 x 53.98 mm). */
            .id-card {
                width: 85.6mm; height: 53.98mm;
                box-shadow: none; border: 1.13px solid #000; border-radius: 0;
                page-break-inside: avoid; break-inside: avoid;
            }
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
            <span title="Agar ukuran kartu tepat KTP (85.6 x 53.98 mm), pastikan skala cetak di dialog printer = 100% / Actual size." style="font-size: 11px; color: #94a3b8; display: flex; align-items: center; gap: 6px;"><i class="fa-solid fa-circle-info" style="color: #3b82f6;"></i> Skala cetak: 100% (Actual size)</span>
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
        <!-- Kop halaman: tampil di layar dan ikut tercetak di atas halaman pertama -->
        <div class="print-header">
            <img class="ph-logo" src="../assets/logo_lampung.png" alt="Logo Provinsi Lampung">
            <div class="ph-text">
                <div class="ph-line1">Pemerintah Provinsi Lampung &mdash; Dinas Pendidikan dan Kebudayaan</div>
                <div class="ph-line2">SMA Negeri 1 Bangunrejo</div>
                <div class="ph-line3">Jalan Raya Sidorejo, Kec. Bangunrejo, Lampung Tengah</div>
                <div class="ph-title">Kartu Pelajar &mdash; Kelas <?= htmlspecialchars($filter_class) ?> (<?= count($students) ?> siswa) &middot; Sisi Depan &amp; Belakang</div>
            </div>
            <img class="ph-logo" src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
        </div>

        <div class="info-bar">
            Kelas: <strong><?= htmlspecialchars($filter_class) ?></strong> — Total <?= count($students) ?> siswa
        </div>

        <?php foreach ($card_pages as $pi => $page_students): $is_last_chunk = ($pi === count($card_pages) - 1); ?>
        <!-- Sisi DEPAN (satu blok per halaman A4) -->
        <div class="print-page front-page">
            <div class="cards-grid">
            <?php foreach($page_students as $s): ?>
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
                            <div class="detail detail-wrap"><i class="fa-solid fa-location-dot"></i> <span class="lbl">ALAMAT<span class="colon">:</span></span><span class="val"><?= htmlspecialchars(!empty($s['alamat']) ? $s['alamat'] : '-') ?></span></div>
                        </div>
                        <div class="card-qr">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($s['nisn']) ?>" alt="QR">
                        </div>
                    </div>
                    <div class="card-footer">Kartu ini wajib dibawa setiap hari</div>
                    <div class="card-ttd">
                        <img src="../assets/ttd_kepsek.png?v=7" alt="Tanda tangan Kepala Sekolah">
                    </div>
                    <img class="watermark" src="../assets/logo_sman1.png" alt="">
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <!-- Sisi BELAKANG — mengikuti langsung halaman depannya; urutan siswa
             sama persis agar sejajar 1:1 saat dicetak duplex bolak-balik. -->
        <div class="print-page back-page<?= $pi === 0 ? ' offset-header' : '' ?><?= $is_last_chunk ? ' last-page' : '' ?>">
            <div class="side-label"><i class="fa-solid fa-rotate"></i> Sisi Belakang &mdash; Kelas <?= htmlspecialchars($filter_class) ?> (halaman <?= ($pi * 2) + 2 ?>)</div>
            <div class="cards-grid">
            <?php foreach($page_students as $s): ?>
                <div class="id-card id-card-back">
                    <div class="bk-banner">
                        <span class="bk-slash1"></span>
                        <span class="bk-slash2"></span>
                        <img class="bk-logo" src="../assets/logo_sman1.png" alt="Logo SMAN 1 Bangunrejo">
                    </div>
                    <div class="bk-msg">Barang siapa yang menemukan kartu pelajar ini diharapkan dapat mengembalikan kepada pemiliknya atau menyerahkan ke SMAN 1 Bangunrejo pada alamat berikut: Jalan Raya Sidorejo, Kec. Bangunrejo, Lampung Tengah.</div>
                    <div class="bk-qr">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($s['nisn']) ?>" alt="QR NISN">
                    </div>
                    <div class="bk-nisn"><?= htmlspecialchars(!empty($s['nisn']) ? $s['nisn'] : '-') ?></div>
                    <div class="bk-band">
                        <span class="bk-band-slash"></span>
                        <div class="bk-valid">BERLAKU SELAMA MENJADI PELAJAR SMAN 1 BANGUNREJO</div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
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