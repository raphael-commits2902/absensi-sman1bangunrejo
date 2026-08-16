<?php
session_start();
require_once '../config/database.php';
require_once '../lib/core.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Ambil filter yang sama dengan halaman laporan
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$filter_date = isset($_GET['filter_date']) ? trim($_GET['filter_date']) : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';
if (!in_array($format, ['excel', 'pdf'], true)) {
    $format = 'pdf';
}

// Susun Query SQL dinamis berdasarkan filter
[$query_sql, $params] = absensi_laporan_query($pdo, $search_name, $filter_class, $filter_date);
$stmt = $pdo->prepare($query_sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Ambil pengaturan waktu absensi untuk penentuan status Terlambat
$waktu = absensi_get_waktu($pdo);
$batas_hadir = $waktu['hadir']['jam_batas'] ?? null;
$batas_sholat = $waktu['sholat']['jam_batas'] ?? null;

// Parameter deskripsi untuk header laporan
$param_desc = [];
if (!empty($filter_class)) $param_desc[] = "Kelas: $filter_class";
if (!empty($filter_date)) $param_desc[] = "Tanggal: " . date('d M Y', strtotime($filter_date));
if (!empty($search_name)) $param_desc[] = "Nama: $search_name";
$param_text = empty($param_desc) ? 'Semua Data Keseluruhan' : implode(' | ', $param_desc);

if ($format === 'excel') {
    // ==================== EXPORT EXCEL (.xls) ====================
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_absensi_' . date('Ymd_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM agar UTF-8 (nama berbahasa Indonesia) terbaca rapi di Excel
    echo "\xEF\xBB\xBF";
?>
<html>
<head>
<meta charset="UTF-8">
</head>
<body>
<table border="1">
    <tr>
        <td colspan="9" style="text-align: center; font-size: 16px; font-weight: bold; background-color: #3b82f6; color: #ffffff;">
            LAPORAN KEHADIRAN SISWA
        </td>
    </tr>
    <tr>
        <td colspan="9" style="font-size: 11px;">
            Dicetak pada: <?= date('d M Y, H:i') ?> &nbsp;|&nbsp; Parameter: <?= $param_text ?>
        </td>
    </tr>
    <tr style="background-color: #dbeafe; font-weight: bold;">
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
    <?php if(empty($reports)): ?>
        <tr><td colspan="9" style="text-align: center;">Tidak ditemukan data absensi yang sesuai.</td></tr>
    <?php else: ?>
        <?php $no = 1; foreach($reports as $row): ?>
            <?php $st = absensi_status($row, $batas_hadir, $batas_sholat); ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= htmlspecialchars(!empty($row['nisn']) ? $row['nisn'] : '-') ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['class']) ?></td>
                <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                <td><?= !empty($row['time_in']) ? htmlspecialchars($row['time_in']) : '-' ?></td>
                <td><?= !empty($row['time_out']) ? htmlspecialchars($row['time_out']) : '-' ?></td>
                <td><?= !empty($row['time_sholat']) ? htmlspecialchars($row['time_sholat']) : '-' ?></td>
                <td><?= htmlspecialchars($st) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>
</body>
</html>
<?php
    exit;
}

// ==================== EXPORT PDF (Real PDF, Pure PHP - Tanpa Library) ====================

function pdf_esc($s) {
    if ($s === null) $s = '';
    $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    if ($conv === false) $conv = $s;
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $conv);
}

function pdf_text($font, $size, $x, $y, $s) {
    return "BT /$font $size Tf $x $y Td (" . pdf_esc($s) . ") Tj ET\n";
}

// Ukuran halaman A4 LANDSCAPE (dalam satuan points)
$pw = 841.89;
$ph = 595.28;
$ml = 40;
$tw = $pw - 2 * $ml;

// Lebar kolom: No, NIS, Nama, Kelas, Tanggal, Jam Masuk, Jam Pulang, Jam Sholat, Status
$cw = array(30, 55, 140, 60, 80, 60, 60, 60, 50);
$rh = 18;   // tinggi baris data
$hh = 20;   // tinggi baris header tabel

$title_y   = $ph - $ml - 20;   // baseline judul
$info_y    = $ph - $ml - 38;   // baseline info tanggal cetak
$table_top = $ph - $ml - 60;   // posisi atas header tabel

$per_page = floor(($ph - $ml * 2 - 60 - $hh) / $rh);

$objs = array();
$addObj = function ($body) use (&$objs) {
    $objs[] = $body;
    return count($objs);
};

// 1: Catalog
$addObj("<< /Type /Catalog /Pages 2 0 R >>");
// 2 & 3: Font (Helvetica & Helvetica-Bold, WinAnsiEncoding)
$addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
$addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

$pageObjNums = array();

if (empty($reports)) {
    // Halaman kosong dengan pesan
    $msg = '';
    $msg .= pdf_text('F2', 16, $ml, $title_y, 'LAPORAN KEHADIRAN SISWA');
    $msg .= pdf_text('F1', 10, $ml, $info_y, "Dicetak pada: " . date('d M Y, H:i') . "  |  Parameter: $param_text");
    $msg .= pdf_text('F1', 12, $ml, 400, 'Tidak ditemukan data absensi yang sesuai dengan filter Anda.');
    $cNum = $addObj("<< /Length " . strlen($msg) . " >>\nstream\n" . $msg . "endstream");
    $pObj = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Contents $cNum 0 R /Resources << /Font << /F1 2 0 R /F2 3 0 R >> >> >>";
    $pageObjNums[] = $addObj($pObj);
} else {
    $chunks = array_chunk($reports, $per_page);
    $total_pages = count($chunks);
    $pageNum = 0;

    foreach ($chunks as $rows) {
        $pageNum++;
        $stream = '';

        // Judul & info
        $stream .= pdf_text('F2', 16, $ml, $title_y, 'LAPORAN KEHADIRAN SISWA');
        $stream .= pdf_text('F1', 10, $ml, $info_y, "Dicetak pada: " . date('d M Y, H:i') . "  |  Parameter: $param_text");
        $stream .= "q 0.8 w $ml " . ($ph - $ml - 30) . " $tw 0 re S Q\n";

        // Header tabel (isi abu-abu)
        $stream .= "q 0.85 g $ml " . ($ph - $table_top - $hh) . " $tw $hh re f Q\n";
        $hx = $ml;
        $headers = array('No.', 'NISN', 'Nama Lengkap', 'Kelas', 'Tanggal Kehadiran', 'Jam Masuk', 'Jam Pulang', 'Jam Sholat', 'Status');
        foreach ($headers as $i => $h) {
            $stream .= pdf_text('F2', 10, $hx + 6, $ph - $table_top + 13, $h);
            $hx += $cw[$i];
        }

        // Baris data
        $no = 1;
        foreach ($rows as $row) {
            $row_top = $table_top + $hh + ($no - 1) * $rh;
            if ($no % 2 === 0) {
                $stream .= "q 0.97 g $ml " . ($ph - $row_top - $rh) . " $tw $rh re f Q\n";
            }
            $cell = array(
                $no,
                !empty($row['nisn']) ? $row['nisn'] : '-',
                $row['name'],
                $row['class'],
                date('d M Y', strtotime($row['date'])),
                !empty($row['time_in']) ? $row['time_in'] : '-',
                !empty($row['time_out']) ? $row['time_out'] : '-',
                !empty($row['time_sholat']) ? $row['time_sholat'] : '-',
                absensi_status($row, $batas_hadir, $batas_sholat)
            );
            $x = $ml;
            foreach ($cell as $i => $val) {
                $stream .= pdf_text('F1', 10, $x + 6, $ph - $row_top + 12.5, $val);
                $x += $cw[$i];
            }
            $no++;
        }

        // Garis tabel (horizontal & vertikal)
        for ($j = 0; $j <= count($rows); $j++) {
            $y = $ph - ($table_top + $hh + $j * $rh);
            $stream .= "$ml $y $tw 0 re S\n";
        }
        $x = $ml;
        foreach ($cw as $w) {
            $stream .= "$x " . ($ph - $table_top - $hh) . " 0 " . ($hh + count($rows) * $rh) . " re S\n";
            $x += $w;
        }

        // Footer: nomor halaman
        $stream .= pdf_text('F1', 9, $ml, $ml + 16, "Laporan Absensi - Halaman $pageNum dari $total_pages");

        // Tanda tangan di halaman terakhir
        if ($pageNum === $total_pages) {
            $stream .= pdf_text('F1', 10, $ml + 350, $ml + 115, 'Mengetahui,');
            $stream .= "q 0.7 w " . ($ml + 350) . " " . ($ml + 80) . " 170 0 re S Q\n";
            $stream .= pdf_text('F1', 10, $ml + 350, $ml + 68, 'KEPALA SEKOLAH');
        }

        $cNum = $addObj("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream");
        $pObj = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Contents $cNum 0 R /Resources << /Font << /F1 2 0 R /F2 3 0 R >> >> >>";
        $pageObjNums[] = $addObj($pObj);
    }
}

// Objek Pages (Kids = semua halaman)
$kids = implode(' ', array_map(function ($n) { return "$n 0 R"; }, $pageObjNums));
$addObj("<< /Type /Pages /Kids [$kids] /Count " . count($pageObjNums) . " >>");

// ==== Susun file PDF dengan xref yang akurat ====
$pdf = "%PDF-1.4\n";
$offsets = array(1 => 0);
foreach ($objs as $i => $body) {
    $offsets[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
}

$xrefStart = strlen($pdf);
$pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= count($objs); $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}
$pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefStart\n%%EOF";

// Unduh sebagai file PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="laporan_absensi_' . date('Ymd_His') . '.pdf"');
header('Pragma: no-cache');
header('Expires: 0');
echo $pdf;
exit;