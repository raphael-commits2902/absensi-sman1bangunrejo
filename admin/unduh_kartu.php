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

    // Ellipse (FPDF core tidak punya) — 4 kurva Bezier, raw PDF operator
    function ellipse($x, $y, $w, $h, $style = 'D')
    {
        $k = $this->k;
        $hp = $this->h;
        $kappa = 0.5522847498;
        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        $ox = ($w / 2) * $kappa;
        $oy = ($h / 2) * $kappa;
        $op = sprintf(
            '%.2F %.2F m ' .
            '%.2F %.2F %.2F %.2F %.2F %.2F c ' . // atas -> kanan
            '%.2F %.2F %.2F %.2F %.2F %.2F c ' . // kanan -> bawah
            '%.2F %.2F %.2F %.2F %.2F %.2F c ' . // bawah -> kiri
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',  // kiri -> atas
            $cx * $k, ($hp - $y) * $k,
            ($cx + $ox) * $k, ($hp - $y) * $k,
            ($x + $w) * $k, ($hp - ($cy - $oy)) * $k,
            ($x + $w) * $k, ($hp - $cy) * $k,
            ($x + $w) * $k, ($hp - ($cy + $oy)) * $k,
            ($cx + $ox) * $k, ($hp - ($y + $h)) * $k,
            $cx * $k, ($hp - ($y + $h)) * $k,
            ($cx - $ox) * $k, ($hp - ($y + $h)) * $k,
            $x * $k, ($hp - ($cy + $oy)) * $k,
            $x * $k, ($hp - $cy) * $k,
            $x * $k, ($hp - ($cy - $oy)) * $k,
            ($cx - $ox) * $k, ($hp - $y) * $k,
            $cx * $k, ($hp - $y) * $k
        );
        if ($style === 'F') {
            $op .= 'f';
        } elseif ($style === 'DF') {
            $op .= 'B';
        } else {
            $op .= 'S';
        }
        $this->_out($op);
    }

    // Poligon bebas (FPDF core tidak punya) — raw PDF path operator,
    // $points = array berisi pasangan [x, y] dalam mm (koordinat atas-kiri).
    function poly($points, $style = 'F')
    {
        $k = $this->k;
        $hp = $this->h;
        $op = '';
        $first = true;
        foreach ($points as $p) {
            $px = $p[0] * $k;
            $py = ($hp - $p[1]) * $k;
            $op .= $first ? sprintf('%.2F %.2F m ', $px, $py) : sprintf('%.2F %.2F l ', $px, $py);
            $first = false;
        }
        $op .= 'h ';
        if ($style === 'F') {
            $op .= 'f';
        } elseif ($style === 'DF') {
            $op .= 'B';
        } else {
            $op .= 'S';
        }
        $this->_out($op);
    }

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

// ==================== DATA TANDA TANGAN KEPALA SEKOLAH ====================
define('KEPSEK_TTD', '../assets/ttd_kepsek.png'); // PNG latar transparant (cap + nama + NIP sudah di dalam gambar)

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
    // Preserve alpha bila source punya transparansi (logo, ikon, ttd);
    // foto biasa (tanpa alpha) tetap berlatar putih.
    $hasAlpha = false;
    $stepX = max(1, intdiv($w, 40));
    $stepY = max(1, intdiv($h, 40));
    for ($yy = 0; $yy < $h && !$hasAlpha; $yy += $stepY) {
        for ($xx = 0; $xx < $w && !$hasAlpha; $xx += $stepX) {
            if (((imagecolorat($im, $xx, $yy) & 0x7F000000) >> 24) >= 100) {
                $hasAlpha = true;
            }
        }
    }
    if ($hasAlpha) {
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefilledrectangle($out, 0, 0, $nw, $nh, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    }
    imagealphablending($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    $tmp = tempnam(sys_get_temp_dir(), 'kartu') . '.png';
    imagepng($out, $tmp, 9);
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
        $sx = (int)round(($canvas - $w) / 2 - $bbox[0]);
        $sy = (int)round(($canvas - $h) / 2 - $bbox[7]);
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
function dashed_line($pdf, $x1, $y, $x2, $lw, $gap, $cr = null, $cg = null, $cb = null)
{
    if ($cr !== null) {
        $pdf->SetDrawColor($cr, $cg, $cb);
    }
    $x = $x1;
    while ($x < $x2) {
        $end = min($x + $lw, $x2);
        $pdf->Line($x, $y, $end, $y);
        $x = $end + $gap;
    }
}
// Normalisasi spasi teks.
// PENTING 1: jangan pakai modifier /u di sini. String yang sudah diberi elipsis
// CP1252 (chr(133)) BUKAN UTF-8 valid, sehingga preg_* dengan /u mengembalikan
// NULL → trim(NULL) = '' → seluruh baris hilang dari kartu.
// PENTING 2: jangan pakai \v (dan \s) di kelas karakter: di PCRE, \v cocok dengan
// SEMUA vertical whitespace termasuk 0x85 (NEL) = karakter elipsis kita, sehingga
// elipsis ikut terhapus. Pakai escape hex eksplisit.
function norm_spasi($txt)
{
    return trim(preg_replace('/[ \t\n\x0B\x0C\r]+/', ' ', (string)$txt));
}

// Potong teks SATU BARIS berdasarkan LEBAR NYATA (mm) pada font aktif,
// tambahkan elipsis (…) bila terpotong. Dipakai agar teks panjang tidak
// pernah meluber ke dalam gambar QR / tanda tangan kepala sekolah.
function clip_text($pdf, $txt, $maxW)
{
    $txt = norm_spasi($txt);
    if ($txt === '') {
        return '';
    }
    if ($pdf->GetStringWidth($txt) <= $maxW) {
        return $txt;
    }
    $ell = chr(133); // '…' (aman di CP1252 font inti Helvetica)
    // Hindari ellipsis ganda bila baris sudah berelipsis lalu dipotong lagi
    $txt = rtrim($txt, $ell);
    $n = mb_strlen($txt);
    while ($n > 0) {
        $n--;
        $cand = rtrim(mb_substr($txt, 0, $n)) . $ell;
        if ($pdf->GetStringWidth($cand) <= $maxW) {
            return $cand;
        }
    }
    return $ell;
}

// Wrap teks menjadi MAKSIMAL $maxLines baris (lebar $maxW per baris),
// elipsis di akhir baris terakhir bila masih ada sisa teks.
// Dipakai untuk ALAMAT supaya tidak menembus ke bawah ke gambar ttd.
function wrap_clip_text($pdf, $txt, $maxW, $maxLines)
{
    $txt = norm_spasi($txt);
    $maxLines = max(1, (int)$maxLines);
    if ($txt === '') {
        return '';
    }
    // FPDF menghitung lebar teks yang tersedia sebagai $maxW - 2*cMargin
    // (interior cell margin, lihat FPDF::MultiCell). Batas di sini WAJIB
    // memakai rumus yang sama; kalau tidak, MultiCell akan memecah baris
    // sekali lagi dengan sendirinya sehingga baris berikutnya tertimpa
    // (contoh nyata: angka tahun "2008" menimpa nilai JK).
    $wmax = $maxW - 2 * (function () { return $this->cMargin; })->call($pdf);
    if ($wmax <= 0) {
        $wmax = $maxW; // jaga-jaga bila cMargin tidak wajar
    }
    if ($pdf->GetStringWidth($txt) <= $wmax) {
        return $txt; // muat satu baris
    }
    $words = preg_split('/[ \t\n\x0B\x0C\r]+/', $txt);
    if (!is_array($words) || empty($words)) {
        return clip_text($pdf, $txt, $wmax);
    }

    $lines = array();
    $cur = '';
    $sisa = false; // true bila masih ada kata yang tidak muat di baris terakhir
    foreach ($words as $w) {
        $test = ($cur === '') ? $w : $cur . ' ' . $w;
        if ($cur === '' || $pdf->GetStringWidth($test) <= $wmax) {
            $cur = $test;
            continue;
        }
        // Baris sekarang sudah penuh: buka baris baru bila masih ada jatah,
        // kalau tidak, sisa teks akan ditandai elipsis.
        if (count($lines) + 1 >= $maxLines) {
            $sisa = true;
            break;
        }
        $lines[] = $cur;
        $cur = $w;
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    if (empty($lines)) {
        $lines[] = '';
    }
    if ($sisa) {
        $last = array_pop($lines);
        $lines[] = clip_text($pdf, rtrim($last) . chr(133), $wmax); // teks dipastikan terpotong
    }
    foreach ($lines as $i => $ln) {
        $lines[$i] = clip_text($pdf, $ln, $wmax); // jaga-jaga kata tunggal super panjang
    }
    return implode("\n", $lines);
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
$cBg = array(241, 241, 241);       // band judul: putih samar abu-abu (match HTML #f1f1f1)
$cFooter = array(248, 250, 252);   // band footer tetap #f8fafc (match HTML)
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
$qr_size = 15.0; // QR (top y+21.03, bottom y+36.03 → jarak 0.47mm ke tinta ttd yang mulai y+36.5)
$pad = 2.85;

// Struktur halaman: berpasangan depan-belakang per 8 kartu (2 kolom x 4 baris)
// sehingga halaman ganjil = depan, halaman genap = belakang.
$perPage = 8;

// Ukuran halaman A4 (mm) + mode posisi halaman belakang:
//  - 'none'  : posisi kartu belakang SAMA PERSIS dengan halaman depan
//              (bawaan; halaman depan & belakang sejajar saat PDF dibuka
//              maupun dicetak satuan)
//  - 'long'  : dicerminkan pada sumbu vertikal = "flip on long edge"
//              (kertas dibalik seperti buku — untuk cetak bolak-balik/duplex)
//  - 'short' : dicerminkan pada sumbu horizontal = "flip on short edge"
//              (untuk printer yang membalik kertas pada sumbu mendatar)
$PAGE_W = 210.0;
$PAGE_H = 297.0;
$duplex = 'none';

$totalStudents = count($students);
for ($chunkStart = 0; $chunkStart < $totalStudents; $chunkStart += $perPage) {
    $chunk = array_slice($students, $chunkStart, $perPage);

    // ---- Halaman DEPAN: 8 kartu ----
    if ($chunkStart > 0) {
        $pdf->AddPage(); // halaman pertama sudah dibuat sebelum loop
    }
    foreach ($chunk as $k => $s) {
        $slot = $k; // posisi slot 0..7 (sama dengan halaman belakangnya)
        $x = 12 + ($slot % 2) * 95.6;
        $y = 8 + intval($slot / 2) * 70;

    // (bingkai digambar TERAKHIR di akhir loop agar tidak tertimpa rect kop/band/footer)

    // ---- KOP: gradasi ----
    $steps = 60;
    $stripe = $CW / $steps;
    $stripeOver = 0; // overdraw 0.5mm dihapus: background kop jangan melewati bingkai kanan
    for ($i = 0; $i < $steps; $i++) {
        $c = grad_color($i / $steps);
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->Rect($x + $i * $stripe, $y, $stripe + $stripeOver, $kopH, 'F');
    }
    // Garis bawah kop
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor($cLineLight[0], $cLineLight[1], $cLineLight[2]);
    $pdf->Line($x, $y + $kopH, $x + $CW, $y + $kopH);

    // Logo kiri & kanan langsung di atas gradasi (background putih dihapus, logo PNG transparan)
    if ($logo_lampung) {
        $pdf->Image($logo_lampung, $x + 1.35 + $logo_pad, $y + 1.5 + $logo_pad, $logo_in, $logo_in);
    }
    if ($logo_sman1) {
        $pdf->Image($logo_sman1, $x + $CW - 1.35 - $badge + $logo_pad, $y + 1.5 + $logo_pad, $logo_in, $logo_in);
    }

    // Teks kop (tengah, diperbesar)
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x, $y + 2.0);
    $pdf->Cell($CW, 2.6, 'PEMERINTAHAN PROVINSI LAMPUNG', 0, 0, 'C');

    $pdf->SetTextColor(191, 219, 254);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetXY($x, $y + 4.8);
    $pdf->Cell($CW, 2.3, 'DINAS PENDIDIKAN DAN KEBUDAYAAN', 0, 0, 'C');

    $pdf->SetTextColor($cGold[0], $cGold[1], $cGold[2]);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetXY($x, $y + 7.4);
    $pdf->Cell($CW, 2.6, 'SMAN 1 BANGUNREJO', 0, 0, 'C');

    // ---- Band judul ----
    $pdf->SetFillColor($cBg[0], $cBg[1], $cBg[2]);
    $pdf->Rect($x, $y + $kopH, $CW, $titleH, 'F');
    // (dashed line di bawah band judul dihapus sesuai request)
    $pdf->SetTextColor($cBlueDark[0], $cBlueDark[1], $cBlueDark[2]);
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->SetXY($x, $y + $kopH + 1.7);
    $pdf->Cell($CW, 2.4, 'KARTU PELAJAR', 0, 0, 'C');

    // Aksen tanda "-" di sisi kiri & kanan judul (meniru tampilan web: gradasi transparan -> biru)
    $barW = 6.88;   // 26px
    $barH = 0.4;    // 1.5px
    $barGap = 2.12; // 8px
    $cBlueAcc = array(59, 130, 246); // #3b82f6
    $cxBar = $x + $CW / 2;
    $halfT = $pdf->GetStringWidth('KARTU PELAJAR') / 2 + 0.5;
    $segs = array(0.35, 0.7, 1.0); // gradasi 3 segmen (transparan -> biru)
    $segW = $barW / count($segs);
    $yBar = $y + $kopH + 2.9 - $barH / 2;
    foreach (array(-1, 1) as $side) {
        foreach ($segs as $i => $f) {
            $pdf->SetFillColor(
                $cBg[0] + ($cBlueAcc[0] - $cBg[0]) * $f,
                $cBg[1] + ($cBlueAcc[1] - $cBg[1]) * $f,
                $cBg[2] + ($cBlueAcc[2] - $cBg[2]) * $f
            );
            $bx = ($side < 0)
                ? ($cxBar - $halfT - $barGap - $barW + $i * $segW)
                : ($cxBar + $halfT + $barGap + $i * $segW);
            $pdf->Rect($bx, $yBar, $segW + 0.01, $barH, 'F');
        }
    }

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
    $py = $y + $bodyTop + $pad;
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
    $nameY = $y + $bodyTop + $pad;
    $valMaxW = $x + $CW - $pad - $qr_size - $pad - $infoX; // sampai sebelum QR (kanan)
    // Nama: dipotong berdasarkan lebar nyata, berhenti sebelum zona gambar QR
    $nameMaxW = ($x + $CW - $pad - $qr_size - 1.2) - $infoX;
    $pdf->SetTextColor($cDark[0], $cDark[1], $cDark[2]);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY($infoX, $nameY);
    $pdf->Cell($valMaxW, 3.79, clip_text($pdf, $s['name'], $nameMaxW), 0, 0, 'L');

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
    // Lebar nilai dibatasi berhenti sebelum zona tanda tangan (ttd mulai x+61.37),
    // supaya alamat panjang wrap ke bawah, bukan menimpa tanda tangan.
    $valMaxRow = ($x + 60.2) - $valX;
    // Catatan: batas di atas adalah lebar LAYOUT (sama dengan lebar kolom nilai di
    // preview HTML). Koreksi margin internal MultiCell (2*cMargin ≈ 2mm) sudah
    // ditangani di dalam wrap_clip_text(), jadi JANGAN dikurangi lagi di sini —
    // pengurangan ganda membuat PDF wrap lebih awal daripada preview.

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
        // Nilai — wrap berdasarkan LEBAR NYATA (mm) pada zona aman masing-masing
        // baris, sehingga teks panjang tidak pernah meluber ke dalam gambar:
        //  - NISN/TTL/JK sejajar gambar QR  -> berhenti sebelum tepi kiri QR
        //  - ALAMAT berada segaris gambar ttd -> berhenti sebelum tepi kiri ttd
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetXY($valX, $rowY);
        if ($r[0] === 'alamat') {
            $txt = wrap_clip_text($pdf, $r[2], $valMaxRow, 3);
            $pdf->MultiCell($valMaxRow, 2.0, $txt, 0, 'L'); // rata kiri: 'J' bawaan FPDF bikin jarak antar kata melebar
        } else {
            $wRow = ($x + $CW - $pad - $qr_size - 0.5) - $valX; // tepat sebelum tepi kiri QR
            $txt = wrap_clip_text($pdf, $r[2], $wRow, 2);
            $pdf->MultiCell($wRow, 2.0, $txt, 0, 'L');
        }
        // Tinggi baris dinamis (meniru alur flex HTML): baris yang wrap ke 2 baris
        // mendorong baris berikutnya turun, jadi teks tidak pernah saling menimpa.
        $rowY += (substr_count($txt, "\n") + 1) * 2.0 + 0.89;
    }

    // ---- QR Code (kanan, sejajar foto) ----
    // Digeser ke atas sedikit (1.06mm ≈ 4px), sinkron dengan margin-top:-4px pada .card-qr di preview HTML
    $qr_up = 1.06;
    $qrx = $x + $CW - $pad - $qr_size;
    $qry = $y + $bodyTop + $pad - $qr_up;
    $qr_jpeg = null;
    $qr_tmp = tempnam(sys_get_temp_dir(), 'qr') . '.png';
    // NISN kosong dilewati: QRcode::png('') gagal ("empty string") dan menulis
    // log ke lib/ — lebih baik kotak QR dibiarkan kosong tanpa sampah file log.
    if (trim((string)$s['nisn']) !== '' && absensi_qr_png($s['nisn'], $qr_tmp, 8, 2)) {
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

    // ---- Tanda tangan Kepala Sekolah (kanan bawah, di bawah QR) ----
    // Gambar sudah memuat cap + tanda tangan + nama + NIP (semua di dalam gambar).
    $ttdRight = $x + $CW - 1.0;           // margin kanan 1mm dari tepi kartu (gambar sudah di-crop flush kanan)
    $ttdH = 15.0;                          // tinggi gambar (mm)
    // Rasio dibaca dinamik dari file aset (fallback 626x356 bila getimagesize gagal)
    $ttd_size = @getimagesize(KEPSEK_TTD);
    $ttd_ratio = 550 / 356;
    if (is_array($ttd_size) && !empty($ttd_size) && !empty($ttd_size[1])) {
        $ttd_ratio = $ttd_size[0] / $ttd_size[1];
    }
    $ttdW = $ttdH * $ttd_ratio;
    $ttdY = $y + 36.5;                     // mulai di bawah QR (34.93); NIP duduk ~1.6mm di band footer
    // Gambar tanda tangan (PNG transparan — FPDF mendukung alpha)
    if (file_exists(KEPSEK_TTD)) {
        $pdf->Image(KEPSEK_TTD, $ttdRight - $ttdW, $ttdY, $ttdW, $ttdH);
    }

    // ---- Footer (digambar DULU, karena gambar ttd boleh menimpa band footer;
// background ttd sudah transparan) ----
    $fTop = $y + $CH - $footerH;
    $pdf->SetFillColor($cFooter[0], $cFooter[1], $cFooter[2]);
    $pdf->Rect($x, $fTop, $CW, $footerH, 'F');
    $pdf->SetTextColor($cGray[0], $cGray[1], $cGray[2]);
    $pdf->SetFont('Helvetica', 'B', 4.5);
    $pdf->SetXY($x, $fTop + 2.15);
    $pdf->Cell($CW, 1.7, 'KARTU INI WAJIB DIBAWA SETIAP HARI', 0, 0, 'C');
    // garis dashed pas di atas teks footer (teks cell top = fTop+2.15; masih di bawah NIP)
    // (garis patah-patah footer dihapus sesuai request)

    // ---- Bingkai kartu digambar TERAKHIR: hitam 0.3mm, sama persis dengan preview HTML (1.13px) ----
    // Setelah semua isi, agar tidak tertimpa rect gradasi kop / band judul / footer (penyebab garis putus)
    $pdf->SetLineWidth(0.3);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $CW, $CH);

    // Bersihkan file sementara foto/QR
    if (!empty($foto_jpeg)) @unlink($foto_jpeg);
    if (!empty($qr_jpeg)) @unlink($qr_jpeg);

    } // akhir foreach kartu depan

    // ==================== SISI BELAKANG KARTU PELAJAR ====================
    // Halaman belakang untuk 8 kartu pada chunk ini. Seluruh desain (banner
    // biru, badan putih + tint biru, band biru, bingkai biru) digambar penuh
    // dengan perintah vektor FPDF — tidak perlu gambar background pre-render.
    // Posisi slot kartu sisi belakang = SAMA PERSIS dengan halaman depan
    // (x = 12 + kolom*95.6, y = 8 + baris*70) sesuai request, sehingga setiap
    // kartu belakang sejajar tepat dengan kartu depannya saat PDF dibuka
    // maupun dicetak. Bila dibutuhkan cetak bolak-balik (duplex), set $duplex
    // di atas ke 'long'/'short' — posisi akan dicerminkan mengikuti mode itu.
    // Isi: logo SMAN 1 Bangunrejo paling atas di tengah, teks pengantar
    // pengembalian di bawahnya, lalu kode QR besar di bagian bawah tengah.
    // Semua posisi dihitung agar tidak saling menumpuk satu sama lain.
    $pdf->AddPage();
    foreach ($chunk as $k => $s) {
        $slot = $k;
        $x = 12 + ($slot % 2) * 95.6;
        $y = 8 + intval($slot / 2) * 70;
        // Cerminkan posisi kartu hanya bila mode duplex aktif (lihat $duplex di atas)
        if ($duplex === 'long') {
            $x = $PAGE_W - $x - $CW;   // cermin sumbu vertikal (flip on long edge)
        } elseif ($duplex === 'short') {
            $y = $PAGE_H - $y - $CH;   // cermin sumbu horizontal (flip on short edge)
        }

        // ---- BATAS DALAM KARTU: semua elemen sisi belakang WAJIB berada
        // di dalam rect kartu (x, y, CW, CH) dengan padding 1mm dari tepi,
        // sehingga tidak ada yang melewati/menimpa kartu pelajar lain. ----
        $bk_pad = 1.0;                                   // padding dari tepi kartu (mm)
        $bk_innerL = $x + $bk_pad;                       // batas kiri dalam
        $bk_innerR = $x + $CW - $bk_pad;                 // batas kanan dalam

        // ====== DESAIN SISI BELAKANG ala KMI Al-Azhar (referensi belakng.jpg):
        // kartu putih, banner biru judul di atas, teks pengantar, QR besar
        // dengan NISN di bawahnya, dan band biru informasi di bagian bawah. ======
        $bk_blue = array(30, 107, 184);                  // biru tema (dari referensi)
        $bk_ink  = array(15, 23, 42);                    // hampir hitam untuk teks isi

        // --- Basis kartu PUTIH + tint biru TRANSPARAN (kesan warna lembut) ---
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $CW, $CH, 'F');
        $pdf->SetAlpha(0.07);                            // overlay biru ~7% (agak transparan)
        $pdf->SetFillColor($bk_blue[0], $bk_blue[1], $bk_blue[2]);
        $pdf->Rect($x, $y, $CW, $CH, 'F');
        $pdf->SetAlpha(1);

        // --- Banner biru gaya GAMING: tepi kanan miring (bevel) + slash aksen ---
        $bk_hH = 9.0;                                    // tinggi banner (mm)
        $bk_cut = 5.5;                                   // kedalaman potongan miring (mm)
        $pdf->SetFillColor($bk_blue[0], $bk_blue[1], $bk_blue[2]);
        $pdf->poly(array(
            array($x, $y),
            array($x + $CW - $bk_cut, $y),
            array($x + $CW, $y + $bk_hH),
            array($x, $y + $bk_hH)
        ), 'F');
        // slash aksen miring paralel dengan tepi potong (biru muda)
        $pdf->SetFillColor(79, 195, 247);                // #4FC3F7
        $pdf->poly(array(
            array($x + $CW - 8.0, $y),
            array($x + $CW - 6.5, $y),
            array($x + $CW - 2.5, $y + $bk_hH),
            array($x + $CW - 4.0, $y + $bk_hH)
        ), 'F');
        // slash tipis kedua (lebih terang, layered, tetap di dalam banner)
        $pdf->SetFillColor(144, 202, 249);               // #90CAF9
        $pdf->poly(array(
            array($x + $CW - 6.2, $y),
            array($x + $CW - 5.6, $y),
            array($x + $CW - 0.1, $y + $bk_hH),
            array($x + $CW - 0.7, $y + $bk_hH)
        ), 'F');
        // Logo SMAN 1 di TENGAH banner (tanpa kotak latar / background)
        if ($logo_sman1) {
            $pdf->Image($logo_sman1, $x + $CW / 2 - 3.5, $y + 1.0, 7.0, 7.0);
        }

        // --- Teks pengantar ASLI (satu paragraf, TANPA nomor, rata tengah) ---
        $bk_msg = 'Barang siapa yang menemukan kartu pelajar ini diharapkan dapat '
                . 'mengembalikan kepada pemiliknya atau menyerahkan ke SMAN 1 Bangunrejo '
                . 'pada alamat berikut: Jalan Raya Sidorejo, Kec. Bangunrejo, Lampung Tengah.';
        $bk_itemY = $y + 9.8;                            // di bawah banner 9mm
        $bk_lh = 2.2;                                    // tinggi baris (mm)
        $bk_qr_top = $y + 16.5;                          // batas bawah teks = sisi atas QR
        $pdf->SetTextColor($bk_ink[0], $bk_ink[1], $bk_ink[2]);
        $pdf->SetFont('Helvetica', '', 6.5);
        $bk_lines = explode("\n", wrap_clip_text($pdf, $bk_msg, $CW - 4.0, 4));
        foreach ($bk_lines as $bk_ln) {
            if ($bk_itemY + $bk_lh > $bk_qr_top + 0.01) {
                break;                                   // jangan pernah masuk zona QR
            }
            $pdf->SetXY($x, $bk_itemY);
            $pdf->Cell($CW, $bk_lh, $bk_ln, 0, 0, 'C');
            $bk_itemY += $bk_lh;
        }

        // --- QR besar (tanpa plat/oval putih tambahan: gambar QR dari lib
        //     lokal sudah membawa quiet zone putihnya sendiri) ---
        $bk_qr_size = 24.0;                              // QR 24mm
        $bk_qr_x = max($bk_innerL, min($x + $CW / 2 - $bk_qr_size / 2, $bk_innerR - $bk_qr_size));
        $bk_qr_y = $y + 17.4;                            // QR: y+17.4 s.d. y+41.4
        // Gambar QR (berisi NISN, sama dengan QR sisi depan)
        $bk_qr_tmp = tempnam(sys_get_temp_dir(), 'bqr') . '.png';
        $bk_qr_jpeg = null;
        if (trim((string)$s['nisn']) !== '' && absensi_qr_png($s['nisn'], $bk_qr_tmp, 10, 3)) {
            $bk_qr_jpeg = to_jpeg($bk_qr_tmp, 300, 300);
            if ($bk_qr_jpeg) {
                $pdf->Image($bk_qr_jpeg, $bk_qr_x, $bk_qr_y, $bk_qr_size, $bk_qr_size);
            }
        }
        if (!empty($bk_qr_jpeg)) @unlink($bk_qr_jpeg);
        @unlink($bk_qr_tmp);
        // Angka NISN di bawah QR (biru tua tebal, menyatu dengan tema)
        $pdf->SetTextColor($bk_blue[0], $bk_blue[1], $bk_blue[2]);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetXY($x, $y + 41.9);
        $pdf->Cell($CW, 2.3, !empty($s['nisn']) ? $s['nisn'] : '-', 0, 0, 'C');

        // --- Band biru bawah gaya GAMING: tepi kanan miring + slash aksen ---
        $pdf->SetFillColor($bk_blue[0], $bk_blue[1], $bk_blue[2]);
        $pdf->poly(array(
            array($x, $y + 45.5),
            array($x + $CW, $y + 45.5),
            array($x + $CW - $bk_cut, $y + $CH),
            array($x, $y + $CH)
        ), 'F');
        // slash tipis biru muda paralel dengan potongan kanan band
        $pdf->SetFillColor(79, 195, 247);
        $pdf->poly(array(
            array($x, $y + 46.9),
            array($x + $CW - 0.91, $y + 46.9),
            array($x + $CW - 1.43, $y + 47.7),
            array($x, $y + 47.7)
        ), 'F');
        // Teks berlaku (putih) di tengah band (logo hanya di banner atas)
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetXY($x, $y + 48.4);
        $pdf->Cell($CW, 3.0, 'BERLAKU SELAMA MENJADI PELAJAR SMAN 1 BANGUNREJO', 0, 0, 'C');

        // --- Bingkai BIRU kartu belakang (digambar terakhir agar tidak tertimpa) ---
        $pdf->SetLineWidth(0.5);
        $pdf->SetDrawColor($bk_blue[0], $bk_blue[1], $bk_blue[2]);
        $pdf->Rect($x, $y, $CW, $CH);
    } // akhir foreach kartu belakang
} // akhir for chunk (halaman depan + belakang)

if ($logo_lampung) @unlink($logo_lampung);
if ($logo_sman1) @unlink($logo_sman1);
if ($logo_sman1_wm) @unlink($logo_sman1_wm);
if ($default_foto) @unlink($default_foto);
foreach ($icons as $ic) @unlink($ic);

$safe_class = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filter_class);
$pdf->Output('D', 'kartu_pelajar_' . $safe_class . '_' . date('Ymd_His') . '.pdf');