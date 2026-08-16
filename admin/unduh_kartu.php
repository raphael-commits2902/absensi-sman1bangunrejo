<?php
session_start();
require_once '../config/database.php';
require_once '../lib/fpdf.php';
require_once '../lib/core.php';

// Pastikan hanya admin yang bisa mengakses
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// FPDF dengan dukungan transparansi (alpha) untuk watermark — script resmi fpdf.org
class PDF_Kartu extends FPDF
{
    protected $extgstates = array();

    function SetAlpha($alpha, $bm = 'Normal')
    {
        $gs = $this->AddExtGState(array('ca' => $alpha, 'CA' => $alpha, 'BM' => '/' . $bm));
        $this->SetExtGState($gs);
    }

    function AddExtGState($parms)
    {
        $n = count($this->extgstates) + 1;
        $this->extgstates[$n]['parms'] = $parms;
        return $n;
    }

    function SetExtGState($gs)
    {
        $this->_out(sprintf('/GS%d gs', $gs));
    }

    function _enddoc()
    {
        if (!empty($this->extgstates) && $this->PDFVersion < '1.4')
            $this->PDFVersion = '1.4';
        parent::_enddoc();
    }

    function _putextgstates()
    {
        for ($i = 1; $i <= count($this->extgstates); $i++) {
            $this->_newobj();
            $this->extgstates[$i]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            $parms = $this->extgstates[$i]['parms'];
            $this->_put(sprintf('/ca %.3F', $parms['ca']));
            $this->_put(sprintf('/CA %.3F', $parms['CA']));
            $this->_put('/BM ' . $parms['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    function _putresourcedict()
    {
        parent::_putresourcedict();
        $this->_put('/ExtGState <<');
        foreach ($this->extgstates as $k => $extgstate)
            $this->_put('/GS' . $k . ' ' . $extgstate['n'] . ' 0 R');
        $this->_put('>>');
    }

    function _putresources()
    {
        $this->_putextgstates();
        parent::_putresources();
    }
}

$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';

if (empty($filter_class)) {
    header("Location: cetak_kartu");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE class = ? ORDER BY name ASC");
$stmt->execute([$filter_class]);
$students = $stmt->fetchAll();

if (empty($students)) {
    header("Location: cetak_kartu?filter_class=" . urlencode($filter_class));
    exit;
}

// ==================== HELPER ====================

// Ubah gambar apapun (png/webp/jpg) menjadi JPEG dengan latar putih, untuk FPDF
function to_jpeg($src, $maxW, $maxH)
{
    if (!file_exists($src)) return null;
    $info = @getimagesize($src);
    if (!$info) return null;
    switch ($info['mime']) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
        case 'image/png':  $im = @imagecreatefrompng($src);  break;
        case 'image/webp': $im = @imagecreatefromwebp($src); break;
        default: return null;
    }
    if (!$im) return null;
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1, $maxW / $w, $maxH / $h);
    $nw = max(1, round($w * $scale));
    $nh = max(1, round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $tmp = tempnam(sys_get_temp_dir(), 'kartu') . '.jpg';
    imagejpeg($out, $tmp, 90);
    imagedestroy($out);
    return $tmp;
}

// Render ikon FontAwesome (solid) menjadi PNG transparan
function render_icon($codepoint, $size, $rgb)
{
    $canvas = $size * 2;
    $img = imagecreatetruecolor($canvas, $canvas);
    imagesavealpha($img, true);
    $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $trans);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    $font = __DIR__ . '/../lib/fa-solid-900.ttf';
    $glyph = mb_chr($codepoint, 'UTF-8');
    $bbox = imagettftext($img, $canvas * 0.8, 0, 0, 0, $color, $font, $glyph);
    $w = $bbox[2] - $bbox[0];
    $h = $bbox[1] - $bbox[7];
    if ($w > 0 && $h > 0) {
        $sx = ($canvas - $w) / 2 - $bbox[0];
        $sy = ($canvas - $h) / 2 - $bbox[7];
        imagefilledrectangle($img, 0, 0, $canvas - 1, $canvas - 1, $trans);
        imagettftext($img, $canvas * 0.8, 0, $sx, $sy, $color, $font, $glyph);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'icon') . '.png';
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Warna gradasi kop: #0f172a -> #1e3a8a (60%) -> #3b82f6
function grad_color($t)
{
    if ($t <= 0.6) {
        $tt = $t / 0.6;
        return array(15 + (30 - 15) * $tt, 23 + (58 - 23) * $tt, 42 + (138 - 42) * $tt);
    }
    $tt = ($t - 0.6) / 0.4;
    return array(30 + (59 - 30) * $tt, 58 + (130 - 58) * $tt, 138 + (246 - 138) * $tt);
}

// Garis putus-putus
function dashed_line($pdf, $x1, $y, $x2, $lw, $gap)
{
    $x = $x1;
    while ($x < $x2) {
        $end = min($x + $lw, $x2);
        $pdf->Line($x, $y, $end, $y);
        $x = $end + $gap;
    }
}

// ==================== GAMBAR STATIS (logo & ikon) ====================

$logo_lampung = to_jpeg('../assets/logo_lampung.png', 200, 200);
$logo_sman1 = to_jpeg('../assets/logo_sman1.png', 200, 200);
$logo_sman1_wm = to_jpeg('../assets/logo_sman1.png', 300, 300);
$default_foto = to_jpeg('../assets/default_photo.png', 120, 150);

$icons = array(
    'nisn'  => render_icon(0xF292, 28, array(59, 130, 246)), // fa-hashtag
    'ttl'   => render_icon(0xF1FD, 28, array(59, 130, 246)), // fa-cake-candles
    'jk'    => render_icon(0xF228, 28, array(59, 130, 246)), // fa-venus-mars
    'alamat'=> render_icon(0xF3C5, 28, array(59, 130, 246)), // fa-location-dot
);

// ==================== BANGUN PDF (ukuran kartu sama dengan KTP: 85.60 x 53.98 mm) ====================

$pdf = new PDF_Kartu('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

$CW = 85.6;   // lebar kartu KTP 85.60mm
$CH = 53.98;  // tinggi kartu KTP 53.98mm

// Warna yang dipakai (sama dengan preview)
$cDark = array(15, 23, 42);
$cBlueDark = array(30, 58, 138);
$cBlue = array(59, 130, 246);
$cLineLight = array(147, 197, 253);
$cBg = array(248, 250, 252);
$cGold = array(251, 191, 36);
$cGray = array(148, 163, 184);
$cBody = array(71, 85, 105);
$cPhotoBorder = array(226, 232, 240);

$kopH = 13.91;  // kop
$titleH = 5.33; // band judul
$bodyTop = $kopH + $titleH; // 19.24
$footerH = 4.12;

$badge = 9.27;   // badge logo
$logo_in = 8.2;  // logo di dalam badge
$logo_pad = 0.53;
$photo_w = 13.19; // foto
$photo_h = 16.4;
$qr_size = 12.84; // QR
$pad = 2.85;

foreach ($students as $idx => $s) {
    // 8 kartu per halaman (2 kolom x 4 baris)
    $slot = $idx % 8;
    if ($idx > 0 && $slot === 0) {
        $pdf->AddPage();
    }
    $x = 12 + ($slot % 2) * 95.6;
    $y = 8 + intval($slot / 2) * 70;

    // ---- Bingkai kartu ----
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor($cDark[0], $cDark[1], $cDark[2]);
    $pdf->Rect($x, $y, $CW, $CH);

    // ---- KOP: gradasi ----
    $steps = 60;
    $stripe = $CW / $steps;
    for ($i = 0; $i < $steps; $i++) {
        $c = grad_color($i / $steps);
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->Rect($x + $i * $stripe, $y, $stripe + 0.5, $kopH, 'F');
    }
    // Garis bawah kop
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor($cLineLight[0], $cLineLight[1], $cLineLight[2]);
    $pdf->Line($x, $y + $kopH, $x + $CW, $y + $kopH);

    // Badge putih + logo kiri & kanan
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x + 1.35, $y + 1.5, $badge, $badge, 'F');
    $pdf->Rect($x + $CW - 1.35 - $badge, $y + 1.5, $badge, $badge, 'F');
    if ($logo_lampung) {
        $pdf->Image($logo_lampung, $x + 1.35 + $logo_pad, $y + 1.5 + $logo_pad, $logo_in, $logo_in);
    }
    if ($logo_sman1) {
        $pdf->Image($logo_sman1, $x + $CW - 1.35 - $badge + $logo_pad, $y + 1.5 + $logo_pad, $logo_in, $logo_in);
    }

    // Teks kop (tengah)
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 6.5);
    $pdf->SetXY($x, $y + 2.2);
    $pdf->Cell($CW, 2.3, 'PEMERINTAHAN PROVINSI LAMPUNG', 0, 0, 'C');

    $pdf->SetTextColor(191, 219, 254);
    $pdf->SetFont('Helvetica', '', 6);
    $pdf->SetXY($x, $y + 4.7);
    $pdf->Cell($CW, 2.1, 'DINAS PENDIDIKAN DAN KEBUDAYAAN', 0, 0, 'C');

    $pdf->SetTextColor($cGold[0], $cGold[1], $cGold[2]);
    $pdf->SetFont('Helvetica', 'B', 6.5);
    $pdf->SetXY($x, $y + 7.2);
    $pdf->Cell($CW, 2.3, 'SMAN 1 BANGUNREJO', 0, 0, 'C');

    // ---- Band judul ----
    $pdf->SetFillColor($cBg[0], $cBg[1], $cBg[2]);
    $pdf->Rect($x, $y + $kopH, $CW, $titleH, 'F');
    dashed_line($pdf, $x, $y + $kopH + $titleH, $x + $CW, 0.3, 0.2);
    $pdf->SetTextColor($cBlueDark[0], $cBlueDark[1], $cBlueDark[2]);
    $pdf->SetFont('Helvetica', 'B', 6);
    $pdf->SetXY($x, $y + $kopH + 1.35);
    $pdf->Cell($CW, 2.0, 'KARTU PELAJAR', 0, 0, 'C');

    // ---- Watermark logo di tengah kartu ----
    if ($logo_sman1_wm) {
        $wm = 28.53;
        $pdf->SetAlpha(0.1);
        $pdf->Image($logo_sman1_wm, $x + $CW / 2 - $wm / 2, $y + $CH * 0.6 - $wm / 2, $wm, $wm);
        $pdf->SetAlpha(1);
    }

    // ---- Foto / avatar ----
    $foto_path = !empty($s['foto']) ? '../' . $s['foto'] : '';
    $px = $x + $pad;
    $py = $bodyTop + $pad;
    $foto_jpeg = (!empty($foto_path) && file_exists($foto_path)) ? to_jpeg($foto_path, 120, 150) : null;
    if ($foto_jpeg) {
        $pdf->Image($foto_jpeg, $px, $py, $photo_w, $photo_h);
    } elseif ($default_foto) {
        $pdf->Image($default_foto, $px, $py, $photo_w, $photo_h);
    }
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor($cPhotoBorder[0], $cPhotoBorder[1], $cPhotoBorder[2]);
    $pdf->Rect($px, $py, $photo_w, $photo_h);

    // ---- Nama ----
    $infoX = $x + $pad + $photo_w + 2.49;
    $nameY = $bodyTop + $pad;
    $valMaxW = $x + $CW - $pad - $qr_size - $pad - $infoX; // sampai sebelum QR (kanan)
    $pdf->SetTextColor($cDark[0], $cDark[1], $cDark[2]);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY($infoX, $nameY);
    $pdf->Cell($valMaxW, 3.79, mb_substr($s['name'], 0, 24), 0, 0, 'L');

    // ---- Detail: NISN / TTL / JK / ALAMAT (sama persis preview) ----
    $ttl_txt = trim($s['tempat_lahir']);
    if (!empty($s['tanggal_lahir'])) {
        $tgl = absensi_format_tgl_id($s['tanggal_lahir']);
        $ttl_txt = $ttl_txt !== '' ? $ttl_txt . ', ' . $tgl : $tgl;
    }
    $jk_txt = $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : ($s['jenis_kelamin'] === 'P' ? 'Perempuan' : '-');

    $rows = array(
        array('nisn', 'NISN', !empty($s['nisn']) ? $s['nisn'] : '-'),
        array('ttl', 'TTL', $ttl_txt !== '' ? $ttl_txt : '-'),
        array('jk', 'JK', $jk_txt),
        array('alamat', 'ALAMAT', !empty($s['alamat']) ? $s['alamat'] : '-'),
    );

    $iconSize = 2.49;
    $lblX = $infoX + 1.07 + $iconSize;
    $colonX = $lblX + 10.35;           // kolom label
    $valX = $colonX + 1.07;
    $valMaxRow = $x + $CW - $pad - $qr_size - $pad - $valX;

    $rowY = $nameY + 4.68;
    foreach ($rows as $r) {
        // Ikon
        if (isset($icons[$r[0]])) {
            $pdf->Image($icons[$r[0]], $infoX, $rowY + 0.18, $iconSize, $iconSize);
        }
        // Label (rata kiri) + titik dua di ujung kolom
        $pdf->SetTextColor($cDark[0], $cDark[1], $cDark[2]);
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetXY($lblX, $rowY);
        $pdf->Cell($colonX - $lblX, 2.0, $r[1]);
        $pdf->SetXY($colonX, $rowY);
        $pdf->Cell(1.07, 2.0, ':');
        // Nilai
        $pdf->SetTextColor($cBody[0], $cBody[1], $cBody[2]);
        $pdf->SetFont('Helvetica', '', 6);
        if ($r[0] === 'alamat') {
            $pdf->SetXY($valX, $rowY);
            $pdf->MultiCell($valMaxRow, 2.0, $r[2]);
        } else {
            $pdf->SetXY($valX, $rowY);
            $pdf->Cell($valMaxRow, 2.0, $r[2]);
        }
        $rowY += 2.89;
    }

    // ---- QR Code (kanan, sejajar foto) ----
    $qrx = $x + $CW - $pad - $qr_size;
    $qry = $bodyTop + $pad;
    $qr_jpeg = null;
    $qr_tmp = tempnam(sys_get_temp_dir(), 'qr') . '.png';
    if (absensi_qr_png($s['qr_token'], $qr_tmp, 8, 2)) {
        $qr_jpeg = to_jpeg($qr_tmp, 200, 200);
        @unlink($qr_tmp);
    }
    if ($qr_jpeg) {
        $pdf->Image($qr_jpeg, $qrx, $qry, $qr_size, $qr_size);
    }
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor($cPhotoBorder[0], $cPhotoBorder[1], $cPhotoBorder[2]);
    $pdf->Rect($qrx, $qry, $qr_size, $qr_size);
    $pdf->SetTextColor($cGray[0], $cGray[1], $cGray[2]);
    $pdf->SetFont('Helvetica', 'B', 4.5);
    $pdf->SetXY($qrx, $qry + $qr_size + 0.71);
    $pdf->Cell($qr_size, 1.5, 'QR ABSEN', 0, 0, 'C');

    // ---- Footer ----
    $fTop = $y + $CH - $footerH;
    $pdf->SetFillColor($cBg[0], $cBg[1], $cBg[2]);
    $pdf->Rect($x, $fTop, $CW, $footerH, 'F');
    dashed_line($pdf, $x, $fTop, $x + $CW, 0.3, 0.2);
    $pdf->SetTextColor($cGray[0], $cGray[1], $cGray[2]);
    $pdf->SetFont('Helvetica', 'B', 4.5);
    $pdf->SetXY($x, $fTop + 1.21);
    $pdf->Cell($CW, 1.75, 'KARTU INI WAJIB DIBAWA SETIAP HARI', 0, 0, 'C');

    // Bersihkan file sementara foto/QR
    if (!empty($foto_jpeg)) @unlink($foto_jpeg);
    if (!empty($qr_jpeg)) @unlink($qr_jpeg);
}

if ($logo_lampung) @unlink($logo_lampung);
if ($logo_sman1) @unlink($logo_sman1);
if ($logo_sman1_wm) @unlink($logo_sman1_wm);
if ($default_foto) @unlink($default_foto);
foreach ($icons as $ic) @unlink($ic);

$safe_class = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filter_class);
$pdf->Output('D', 'kartu_pelajar_' . $safe_class . '_' . date('Ymd_His') . '.pdf');