<?php
session_start();
require_once '../config/database.php';
require_once '../lib/core.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';

if (empty($filter_class)) {
    header("Location: cetak_qr");
    exit;
}

// Ambil siswa di kelas tersebut
$stmt = $pdo->prepare("SELECT nisn, name, class, qr_token FROM students WHERE class = ? ORDER BY name ASC");
$stmt->execute([$filter_class]);
$students = $stmt->fetchAll();

if (empty($students)) {
    header("Location: cetak_qr?filter_class=" . urlencode($filter_class));
    exit;
}

// Bersihkan nama file agar aman
function sanitize_filename($s) {
    $s = preg_replace('/[^A-Za-z0-9_.\- ]/', '', $s);
    return trim(preg_replace('/\s+/', '_', $s));
}

$zip = new ZipArchive();
$zip_name = 'QR_' . sanitize_filename($filter_class) . '_' . date('Ymd_His') . '.zip';
$tmp_zip = sys_get_temp_dir() . '/' . $zip_name;

if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Gagal membuat file ZIP.');
}

foreach ($students as $s) {
    $tmp_png = tempnam(sys_get_temp_dir(), 'qr') . '.png';
    if (!absensi_qr_png($s['qr_token'], $tmp_png, 10, 4)) {
        @unlink($tmp_png);
        continue;
    }
    $entry = 'QR_' . sanitize_filename(!empty($s['nisn']) ? $s['nisn'] : $s['name']) . '_' . sanitize_filename($s['name']) . '.png';
    $zip->addFromString($entry, file_get_contents($tmp_png));
    @unlink($tmp_png);
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($tmp_zip));
header('Pragma: no-cache');
header('Expires: 0');
readfile($tmp_zip);
@unlink($tmp_zip);
exit;